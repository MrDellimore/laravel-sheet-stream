<?php

namespace MrDellimore\SheetStream\Engine\PhpSpreadsheet;

use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Support\CellNormalizer;
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

    private readonly bool $needsStyles;

    public function __construct(
        private readonly array $options = [],
    ) {
        $this->calculateFormulas = (bool) ($options['calculateFormulas'] ?? false);

        // Rendering a cell through its number format, or recognising a
        // date-styled cell, needs the workbook's style table, which
        // "data only" mode discards.
        $this->needsStyles = (bool) ($options['formatData'] ?? false)
            || ($options['dates']['import_as'] ?? CellNormalizer::DATES_AS_SERIAL) === CellNormalizer::DATES_AS_DATETIME;
    }

    public function open(string $path): void
    {
        $reader = IOFactory::createReaderForFile($path);

        if (! $this->calculateFormulas && ! $this->needsStyles) {
            $reader->setReadDataOnly(true);
        }

        $this->spreadsheet = $reader->load($path);
    }

    /** @return iterable<int, SheetReader> */
    public function sheets(): iterable
    {
        foreach ($this->spreadsheet->getAllSheets() as $worksheet) {
            yield new PhpSpreadsheetSheetReader($worksheet, $this->options);
        }
    }

    public function close(): void
    {
        $this->spreadsheet->disconnectWorksheets();
        unset($this->spreadsheet);
    }
}
