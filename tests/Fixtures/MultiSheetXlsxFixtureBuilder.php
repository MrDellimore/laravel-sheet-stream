<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class MultiSheetXlsxFixtureBuilder
{
    private readonly string $path;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'sheet_fixture_').'.xlsx';
    }

    /** @param array<string, array<int, array<int, scalar|null>>> $sheets keyed by sheet name */
    public function write(array $sheets): static
    {
        $writer = new Writer;
        $writer->openToFile($this->path);

        $first = true;

        foreach ($sheets as $name => $rows) {
            if ($first) {
                $writer->getCurrentSheet()->setName($name);
                $first = false;
            } else {
                $sheet = $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($name);
            }

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $this;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }
}
