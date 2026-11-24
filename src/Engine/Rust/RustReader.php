<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Rust;

use LogicException;
use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Support\CellNormalizer;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use RustySheet\Reader as NativeReader;
use RustySheet\UnsupportedFormatException;

/**
 * Reader driver backed by the rusty_sheet PHP extension: a streaming XLSX/CSV reader
 * written in Rust, 25–440× faster than OpenSpout on large XLSX workbooks and about
 * 10× faster on CSV.
 *
 * Rows match the OpenSpout driver's output, including WithFormatData display strings
 * and CsvOptions (delimiter, enclosure, escape). Each file falls back to OpenSpout for
 * what the extension doesn't handle yet:
 *  - .ods (.xls still throws UnsupportedByEngine);
 *  - CSV in an encoding other than UTF-8;
 *  - the native XLSX option SHOULD_USE_1904_DATES (forcing the 1904 date system).
 *
 * Differences from the OpenSpout driver, all deliberate:
 *  - shared formulas filled down in Excel return their real formula, not "=";
 *  - an unparseable number cell yields null, not 0.0;
 *  - with dates.import_as=serial and no timezone, dates before 1900-03-01 (including
 *    every time-only value) yield their true serial: 18:00 is 0.75, not -0.25.
 *  - with WithCalculatedFormulas, text starting with "=" is kept (OpenSpout turns it into
 *    a formula cell without a value, so the text becomes null);
 *  - corrupt XML throws a RustySheet\ReaderException instead of silently ending the sheet.
 */
final class RustReader implements Reader
{
    public const string EXTENSION = 'rusty_sheet';

    /** Rows fetched from the extension per call. */
    private const int BATCH_SIZE = 1000;

    private ?NativeReader $native = null;

    private ?OpenSpoutReader $fallback = null;

    public function __construct(
        private readonly array $options = [],
        private readonly ?object $nativeOptions = null,
    ) {}

    public static function isAvailable(): bool
    {
        return extension_loaded(self::EXTENSION);
    }

    public function open(string $path): void
    {
        $this->native = null;
        $this->fallback = null;

        if ($this->needsOpenSpout()) {
            $this->openFallback($path);

            return;
        }

        try {
            $this->native = NativeReader::open($path, $this->extensionOptions($path));
        } catch (UnsupportedFormatException) {
            $this->openFallback($path);
        }
    }

    /** @return iterable<int, SheetReader> */
    public function sheets(): iterable
    {
        if ($this->fallback instanceof OpenSpoutReader) {
            yield from $this->fallback->sheets();

            return;
        }

        $native = $this->native ?? throw new LogicException('Call open() before sheets().');
        $convertDates = $this->convertsDatesToSerial();

        foreach ($native->sheetNames() as $index => $name) {
            yield new RustSheetReader($native, $index, $name, $convertDates, self::BATCH_SIZE);
        }
    }

    public function close(): void
    {
        $this->native?->close();
        $this->fallback?->close();
        $this->native = null;
        $this->fallback = null;
    }

    /** Whether this file is being read by OpenSpout rather than the extension. */
    public function usesFallback(): bool
    {
        return $this->fallback instanceof OpenSpoutReader;
    }

    private function openFallback(string $path): void
    {
        $this->fallback = new OpenSpoutReader($this->options, $this->nativeOptions);
        $this->fallback->open($path);
    }

    private function needsOpenSpout(): bool
    {
        return $this->nativeOptions instanceof XlsxOptions && $this->nativeOptions->SHOULD_USE_1904_DATES;
    }

    /** WithFormatData, or SHOULD_FORMAT_DATES via WithReaderOptions: dates as display strings. */
    private function formatsDates(): bool
    {
        return (bool) ($this->options['formatData'] ?? false)
            || ($this->nativeOptions instanceof XlsxOptions && $this->nativeOptions->SHOULD_FORMAT_DATES);
    }

    /** @return array<string, mixed> options for RustySheet\Reader::open() */
    private function extensionOptions(string $path): array
    {
        $timezone = $this->timezone();

        // Like the OpenSpout driver, native options only count when their class matches
        // the file: XlsxOptions for .xlsx, CsvOptions for .csv/.tsv.
        $isCsv = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['csv', 'tsv'], true);
        $native = $this->nativeOptions;
        $options = [
            'calculated_formulas' => (bool) ($this->options['calculateFormulas'] ?? false),
            'preserve_empty_rows' => ($isCsv ? $native instanceof CsvOptions : $native instanceof XlsxOptions)
                && $native->SHOULD_PRESERVE_EMPTY_ROWS,
            // OpenSpout driver parity: a bad row is skipped rather than failing the import.
            'on_error' => 'skip_row',
            // Used for .csv/.tsv only. Without CsvOptions the OpenSpout driver reads .tsv with
            // a comma too, so the delimiter is always explicit here.
            'csv' => $native instanceof CsvOptions
                ? [
                    'delimiter' => $native->FIELD_DELIMITER,
                    'enclosure' => $native->FIELD_ENCLOSURE,
                    'escape' => $native->FIELD_ESCAPE,
                    // Anything but UTF-8 makes the extension decline, and OpenSpout reads the file.
                    'encoding' => $native->ENCODING,
                ]
                : ['delimiter' => ','],
        ];

        if ($this->formatsDates()) {
            // Display strings; a timezone has nothing to apply to (as with the OpenSpout driver).
            $options['dates'] = 'formatted';

            return $options;
        }

        // The extension applies timezones to DateTimeImmutable only, so serial mode with a
        // timezone reads datetimes and converts them here, exactly as the OpenSpout driver does.
        if ($this->dateMode() === CellNormalizer::DATES_AS_DATETIME || $timezone !== null) {
            $options['dates'] = 'datetime';
            if ($timezone !== null) {
                $options['timezone'] = $timezone;
            }
        }

        return $options;
    }

    private function convertsDatesToSerial(): bool
    {
        return ! $this->formatsDates()
            && $this->dateMode() === CellNormalizer::DATES_AS_SERIAL
            && $this->timezone() !== null;
    }

    private function dateMode(): string
    {
        return CellNormalizer::assertValidDateMode(
            (string) ($this->options['dates']['import_as'] ?? CellNormalizer::DATES_AS_SERIAL)
        );
    }

    private function timezone(): ?string
    {
        $tz = $this->options['dates']['timezone'] ?? null;

        return $tz === null || $tz === '' ? null : (string) $tz;
    }
}
