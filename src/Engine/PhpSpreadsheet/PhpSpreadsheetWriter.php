<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\PhpSpreadsheet;

use MrDellimore\SheetStream\Engine\Contracts\Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Html;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

/**
 * PhpSpreadsheet writer driver.
 *
 * Buffers the entire workbook in memory, then writes on close().
 * Use when you need .xls output or PhpSpreadsheet-specific features.
 */
final class PhpSpreadsheetWriter implements Writer
{
    private readonly Spreadsheet $spreadsheet;

    private string $path = '';

    private readonly string $extension;

    private int $currentRow = 1;

    private bool $firstSheet = true;

    private Worksheet $currentSheet;

    public function __construct(string $extension = 'xlsx')
    {
        $this->extension = strtolower($extension);
        $this->spreadsheet = new Spreadsheet;
        $this->currentSheet = $this->spreadsheet->getActiveSheet();
    }

    public function openToFile(string $path): void
    {
        $this->path = $path;
    }

    public function addSheet(?string $name): void
    {
        if ($this->firstSheet) {
            $this->firstSheet = false;
            $this->currentRow = 1;

            if ($name !== null) {
                $this->currentSheet->setTitle($name);
            }

            return;
        }

        $this->currentSheet = $this->spreadsheet->createSheet();
        $this->spreadsheet->setActiveSheetIndex($this->spreadsheet->getIndex($this->currentSheet));
        $this->currentRow = 1;

        if ($name !== null) {
            $this->currentSheet->setTitle($name);
        }
    }

    public function addRow(array $cells, ?object $rowStyle = null, array $columnStyles = []): void
    {
        $col = 1;

        foreach ($cells as $value) {
            $this->currentSheet->setCellValue([$col, $this->currentRow], $value);
            $col++;
        }

        $this->currentRow++;
    }

    public function loadHtml(string $html): void
    {
        $sheetIndex = $this->spreadsheet->getIndex($this->currentSheet);

        $tmpFile = tempnam(sys_get_temp_dir(), 'sheet_stream_html_').'.html';
        file_put_contents($tmpFile, $html);

        try {
            /** @var Html $reader */
            $reader = IOFactory::createReader('Html');
            $reader->setSheetIndex($sheetIndex);
            $reader->loadIntoExisting($tmpFile, $this->spreadsheet);
        } finally {
            @unlink($tmpFile);
        }

        // The Html reader may have changed the active sheet; restore our reference.
        $this->currentSheet = $this->spreadsheet->getSheet($sheetIndex);
    }

    public function close(): void
    {
        $writerType = match ($this->extension) {
            'xlsx' => 'Xlsx',
            'xls' => 'Xls',
            'csv', 'tsv' => 'Csv',
            'ods' => 'Ods',
            default => 'Xlsx',
        };

        $writer = IOFactory::createWriter($this->spreadsheet, $writerType);

        if ($writerType === 'Csv' && $this->extension === 'tsv') {
            /** @var Csv $writer */
            $writer->setDelimiter("\t");
        }

        $writer->save($this->path);
        $this->spreadsheet->disconnectWorksheets();
    }
}
