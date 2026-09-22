<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\OpenSpout;

use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

final class OpenSpoutReader implements Reader
{
    private ReaderInterface $reader;

    public function __construct(
        private readonly array $options = [],
        private readonly ?object $nativeOptions = null,
    ) {}

    public function open(string $path): void
    {
        $this->reader = $this->createReaderForExtension(
            strtolower(pathinfo($path, PATHINFO_EXTENSION))
        );

        $this->reader->open($path);
    }

    /** @return iterable<int, SheetReader> */
    public function sheets(): iterable
    {
        foreach ($this->reader->getSheetIterator() as $sheet) {
            yield new OpenSpoutSheetReader($sheet, $this->options);
        }
    }

    public function close(): void
    {
        $this->reader->close();
    }

    private function createReaderForExtension(string $extension): ReaderInterface
    {
        return match ($extension) {
            'xlsx' => new XlsxReader($this->xlsxOptions()),
            'csv', 'tsv' => new CsvReader(
                $this->nativeOptions instanceof CsvOptions ? $this->nativeOptions : null,
            ),
            'ods' => new OdsReader($this->odsOptions()),
            'xls' => throw new UnsupportedByEngine(
                'The .xls (legacy binary) format is not supported by the OpenSpout engine. '
                .'Use .xlsx, .csv, or .ods instead.'
            ),
            default => throw new UnsupportedByEngine(
                "Unsupported file extension: .{$extension}"
            ),
        };
    }

    /**
     * WithFormatData asks OpenSpout to render date-styled cells as strings
     * (SHOULD_FORMAT_DATES). The flag is layered onto any native options the
     * import supplied so WithReaderOptions and WithFormatData compose.
     */
    private function xlsxOptions(): ?XlsxOptions
    {
        $options = $this->nativeOptions instanceof XlsxOptions ? $this->nativeOptions : null;

        if (! $this->formatData()) {
            return $options;
        }

        $options ??= new XlsxOptions;
        $options->SHOULD_FORMAT_DATES = true;

        return $options;
    }

    private function odsOptions(): ?OdsOptions
    {
        $options = $this->nativeOptions instanceof OdsOptions ? $this->nativeOptions : null;

        if (! $this->formatData()) {
            return $options;
        }

        $options ??= new OdsOptions;
        $options->SHOULD_FORMAT_DATES = true;

        return $options;
    }

    private function formatData(): bool
    {
        return (bool) ($this->options['formatData'] ?? false);
    }
}
