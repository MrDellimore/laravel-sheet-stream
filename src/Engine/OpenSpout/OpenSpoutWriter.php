<?php

namespace MrDellimore\SheetStream\Engine\OpenSpout;

use DateTimeInterface;
use MrDellimore\SheetStream\Engine\Contracts\Writer;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
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
    private readonly WriterInterface $writer;

    private bool $firstSheet = true;

    private ?Style $dateStyle = null;

    private ?Style $dateTimeStyle = null;

    /**
     * @param  string  $extension  File extension (xlsx, csv, ods)
     * @param  object|null  $nativeOptions  OpenSpout native writer options
     * @param  string  $dateFormat  Excel number format for date-only values
     * @param  string  $dateTimeFormat  Excel number format for date+time values
     */
    public function __construct(
        string $extension = 'xlsx',
        ?object $nativeOptions = null,
        private readonly string $dateFormat = 'yyyy-mm-dd',
        private readonly string $dateTimeFormat = 'yyyy-mm-dd hh:mm:ss',
    ) {
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
        $style = $rowStyle instanceof Style ? $rowStyle : null;

        // Single-pass: build typed Cell objects, applying date format styles as needed.
        // This avoids a separate containsDates() scan followed by a second buildRowWithDates() pass.
        $hasDates = false;
        $cellObjects = [];

        foreach (array_values($cells) as $index => $value) {
            $colStyle = $columnStyles[$index] ?? $style;

            if ($value instanceof DateTimeInterface) {
                $hasDates = true;
                $cellObjects[] = new Cell\DateTimeCell($value, $this->resolveDateStyle($value, $colStyle));
            } elseif ($colStyle instanceof Style) {
                $cellObjects[] = Cell::fromValue($value, $colStyle);
            } else {
                $cellObjects[] = Cell::fromValue($value);
            }
        }

        if ($hasDates || $columnStyles !== [] || $style instanceof Style) {
            $this->writer->addRow(new Row($cellObjects));
        } else {
            $this->writer->addRow(Row::fromValues($cells));
        }
    }

    /**
     * Resolve the Style to use for a DateTimeInterface cell.
     * If the user provided a column style with a format, use that. Otherwise, use the
     * default date/datetime format based on whether the value has a non-zero time part.
     */
    private function resolveDateStyle(DateTimeInterface $value, ?object $colStyle): Style
    {
        if ($colStyle instanceof Style && $colStyle->getFormat() !== null) {
            return $colStyle;
        }

        $hasTime = $value->format('H:i:s') !== '00:00:00';

        if ($hasTime) {
            return $this->dateTimeStyle ??= (new Style)->setFormat($this->dateTimeFormat);
        }

        return $this->dateStyle ??= (new Style)->setFormat($this->dateFormat);
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
