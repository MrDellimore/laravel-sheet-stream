<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds temporary .xlsx fixture files containing formulas.
 *
 * Uses PhpSpreadsheet to write the file so that both the formula (<f>)
 * and the cached computed value (<v>) are present in the XML — exactly
 * like a file saved from Excel or Google Sheets.
 */
class FormulaXlsxFixtureBuilder
{
    private string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('sheet_formula_fixture_', true).'.xlsx';
    }

    /**
     * Build a spreadsheet with numeric data and formulas.
     *
     * Layout:
     *   A1: "label"   B1: "value"   C1: "formula_result"
     *   A2: "alpha"   B2: 10        C2: =B2*2           (cached: 20)
     *   A3: "beta"    B3: 25        C3: =B3+5           (cached: 30)
     *   A4: "total"   B4: =SUM(B2:B3)  C4: =SUM(C2:C3) (cached: 35, 50)
     */
    public function build(): static
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Headings
        $sheet->setCellValue('A1', 'label');
        $sheet->setCellValue('B1', 'value');
        $sheet->setCellValue('C1', 'formula_result');

        // Data rows
        $sheet->setCellValue('A2', 'alpha');
        $sheet->setCellValue('B2', 10);
        $sheet->setCellValue('C2', '=B2*2');

        $sheet->setCellValue('A3', 'beta');
        $sheet->setCellValue('B3', 25);
        $sheet->setCellValue('C3', '=B3+5');

        // Totals with formulas
        $sheet->setCellValue('A4', 'total');
        $sheet->setCellValue('B4', '=SUM(B2:B3)');
        $sheet->setCellValue('C4', '=SUM(C2:C3)');

        // Force calculation so cached values are written to the file
        $spreadsheet->getActiveSheet()->calculateColumnWidths();

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(true);
        $writer->save($this->path);

        $spreadsheet->disconnectWorksheets();

        return $this;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        @unlink($this->path);
    }
}
