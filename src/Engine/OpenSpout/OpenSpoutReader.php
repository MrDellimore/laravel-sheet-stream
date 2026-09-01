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
            'xlsx' => new XlsxReader(
                $this->nativeOptions instanceof XlsxOptions ? $this->nativeOptions : null,
            ),
            'csv', 'tsv' => new CsvReader(
                $this->nativeOptions instanceof CsvOptions ? $this->nativeOptions : null,
            ),
            'ods' => new OdsReader(
                $this->nativeOptions instanceof OdsOptions ? $this->nativeOptions : null,
            ),
            'xls' => throw new UnsupportedByEngine(
                'The .xls (legacy binary) format is not supported by the OpenSpout engine. '
                .'Use .xlsx, .csv, or .ods instead.'
            ),
            default => throw new UnsupportedByEngine(
                "Unsupported file extension: .{$extension}"
            ),
        };
    }
}
