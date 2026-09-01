<?php

namespace MrDellimore\SheetStream\Engine\OpenSpout;

use MrDellimore\SheetStream\Engine\Contracts\Writer;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\AbstractWriterMultiSheets;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\ODS\Options as OdsOptions;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

final class OpenSpoutWriter implements Writer
{
    private WriterInterface $writer;

    private bool $firstSheet = true;

    public function __construct(string $extension = 'xlsx', ?object $nativeOptions = null)
    {
        $this->writer = $this->createWriterForExtension(
            strtolower($extension),
            $nativeOptions,
        );
    }

    public function openToFile(string $path): void
    {
        $this->writer->openToFile($path);
    }

    public function addSheet(?string $name): void
    {
        if ($this->firstSheet) {
            $this->firstSheet = false;

            if ($name !== null && $this->writer instanceof AbstractWriterMultiSheets) {
                $this->writer->getCurrentSheet()->setName($name);
            }

            return;
        }

        if (! $this->writer instanceof AbstractWriterMultiSheets) {
            throw new UnsupportedByEngine('The CSV format does not support multiple sheets.');
        }

        $sheet = $this->writer->addNewSheetAndMakeItCurrent();

        if ($name !== null) {
            $sheet->setName($name);
        }
    }

    public function addRow(array $cells, ?object $rowStyle = null, array $columnStyles = []): void
    {
        if ($columnStyles !== []) {
            $this->writer->addRow(Row::fromValuesWithStyles($cells, $rowStyle, $columnStyles));
        } elseif ($rowStyle !== null) {
            $this->writer->addRow(Row::fromValues($cells, $rowStyle));
        } else {
            $this->writer->addRow(Row::fromValues($cells));
        }
    }

    public function close(): void
    {
        $this->writer->close();
    }

    private function createWriterForExtension(string $extension, ?object $nativeOptions): WriterInterface
    {
        return match ($extension) {
            'xlsx' => new XlsxWriter(
                $nativeOptions instanceof XlsxOptions ? $nativeOptions : null,
            ),
            'csv', 'tsv' => new CsvWriter(
                $nativeOptions instanceof CsvOptions ? $nativeOptions : null,
            ),
            'ods' => new OdsWriter(
                $nativeOptions instanceof OdsOptions ? $nativeOptions : null,
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
