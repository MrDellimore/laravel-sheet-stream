<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Imports;

use Closure;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\ReadsFirstRow;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Support\RowHelper;
use MrDellimore\SheetStream\Support\SheetResolver;
use MrDellimore\SheetStream\Support\TempFileResolver;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;

/**
 * Answers questions about a file before (or without) importing it, through the same
 * reader driver an import would use: SheetStream::headers() and SheetStream::countRows().
 */
final readonly class FileInspector
{
    public function __construct(
        private string $driver = 'openspout',
        private string $headingFormatter = 'slug',
    ) {}

    /**
     * The first row of every sheet, keyed by sheet name in workbook order.
     *
     * @param  array<string, mixed>  $readerOptions
     * @return array<string, array<int, mixed>>
     */
    public function headers(string $path, ?string $disk = null, array $readerOptions = [], bool $formatted = false): array
    {
        // Row 1 is the header even when it is blank: without this the streaming drivers
        // skip leading blank rows and would report the first row that has data instead.
        $reader = EngineFactory::reader($this->driver, $readerOptions, $this->preserveEmptyRows($path));

        return $this->withOpenFile($reader, $path, $disk, 'sheet_stream_headers_', function (Reader $reader) use ($formatted): array {
            $headers = [];

            foreach ($reader->sheets() as $sheetReader) {
                $row = $this->trimTrailingBlanks(self::firstRow($sheetReader) ?? []);

                $headers[$sheetReader->name()] = $formatted
                    ? RowHelper::normalizeHeadings($row, $this->headingFormatter)
                    : $row;
            }

            return $headers;
        });
    }

    /**
     * The number of rows an import of this file would hand to the import class, per
     * processed sheet, keyed by sheet name in workbook order.
     *
     * @param  array<string, mixed>  $readerOptions
     * @return array<string, int>
     */
    public function countRows(object $import, string $path, ?string $disk = null, array $readerOptions = []): array
    {
        $nativeOptions = $import instanceof WithReaderOptions ? $import->readerOptions() : null;
        $reader = EngineFactory::reader($this->driver, $readerOptions, $nativeOptions);

        return $this->withOpenFile($reader, $path, $disk, 'sheet_stream_count_', function (Reader $reader) use ($import): array {
            $sheets = $import instanceof WithMultipleSheets ? $import->sheets() : null;
            $counts = [];

            foreach ($reader->sheets() as $sheetIndex => $sheetReader) {
                $sheetImport = SheetResolver::resolve($import, $sheetIndex, $sheetReader->name(), $sheets);

                if ($sheetImport !== null) {
                    $counts[$sheetReader->name()] = $this->countSheetRows(
                        $sheetReader,
                        $sheetImport instanceof WithHeadingRow,
                        $sheetImport instanceof SkipsEmptyRows,
                    );
                }

                if ($sheets === null) {
                    // Without WithMultipleSheets only the first sheet is ever imported.
                    break;
                }
            }

            return $counts;
        });
    }

    /**
     * Pull one row and stop. Readers that fetch ahead expose a cheaper way to do it.
     *
     * @return array<int, mixed>|null null when the sheet has no rows
     */
    public static function firstRow(SheetReader $sheetReader): ?array
    {
        if ($sheetReader instanceof ReadsFirstRow) {
            $row = $sheetReader->firstRow();

            return $row === null ? null : array_values($row);
        }

        foreach ($sheetReader->rows() as $row) {
            return array_values($row);
        }

        return null;
    }

    /**
     * Mirrors ImportRunner's row loop up to the point a row reaches validation: the first
     * row the reader yields is the heading, and SkipsEmptyRows drops blank rows after it.
     */
    private function countSheetRows(SheetReader $sheetReader, bool $hasHeadingRow, bool $skipsEmptyRows): int
    {
        $count = 0;
        $headingPending = $hasHeadingRow;

        foreach ($sheetReader->rows() as $row) {
            if ($headingPending) {
                $headingPending = false;

                continue;
            }

            if ($skipsEmptyRows && RowHelper::isEmptyRow($row)) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    /**
     * Open the file (downloading it from $disk to a temp copy first when needed), run the
     * callback, and always close the reader and remove the temp copy.
     *
     * @template T
     *
     * @param  Closure(Reader): T  $callback
     * @return T
     */
    private function withOpenFile(Reader $reader, string $path, ?string $disk, string $tempPrefix, Closure $callback): mixed
    {
        $localPath = TempFileResolver::localPath($path, $disk, $tempPrefix);

        try {
            $reader->open($localPath);

            try {
                return $callback($reader);
            } finally {
                $reader->close();
            }
        } finally {
            TempFileResolver::cleanup($localPath, $disk);
        }
    }

    /** Native options are matched to the file type by class, so pick the file's own. */
    private function preserveEmptyRows(string $path): ?object
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'xlsx' => new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true),
            'csv', 'tsv' => new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true),
            'ods' => new OdsOptions(SHOULD_PRESERVE_EMPTY_ROWS: true),
            default => null,
        };
    }

    /**
     * The drivers disagree on a short or blank row ([] vs [''] vs [null, null]); without
     * the trailing blanks they agree, and a blank first row is [] on all of them.
     *
     * @param  array<int, mixed>  $row
     * @return array<int, mixed>
     */
    private function trimTrailingBlanks(array $row): array
    {
        while ($row !== [] && in_array(end($row), [null, ''], true)) {
            array_pop($row);
        }

        return $row;
    }
}
