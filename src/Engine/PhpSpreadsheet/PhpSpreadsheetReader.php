<?php

namespace MrDellimore\SheetStream\Engine\PhpSpreadsheet;

use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * PhpSpreadsheet reader driver.
 *
 * This driver loads the entire workbook into memory — it is NOT streaming.
 * Use it only when you need capabilities that OpenSpout cannot provide:
 * .xls (legacy binary), formula evaluation, charts, etc.
 */
final class PhpSpreadsheetReader implements Reader
{
    private Spreadsheet $spreadsheet;

    private readonly bool $calculateFormulas;

    public function __construct(
        private array $options = [],
    ) {
        $this->calculateFormulas = (bool) ($options['calculateFormulas'] ?? false);
    }

    public function open(string $path): void
    {
        $reader = IOFactory::createReaderForFile($path);

        if (! $this->calculateFormulas) {
            $reader->setReadDataOnly(true);
        }

        $this->spreadsheet = $reader->load($path);
    }

    /** @return iterable<int, SheetReader> */
    public function sheets(): iterable
    {
        $tz = $this->options['dates']['timezone'] ?? null;

        foreach ($this->spreadsheet->getAllSheets() as $worksheet) {
            yield new PhpSpreadsheetSheetReader($worksheet, $tz, $this->calculateFormulas);
        }
    }

    public function close(): void
    {
        $this->spreadsheet->disconnectWorksheets();
        unset($this->spreadsheet);
    }
}
