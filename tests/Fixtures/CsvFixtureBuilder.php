<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer;

class CsvFixtureBuilder
{
    private string $path;

    public function __construct()
    {
        $this->path = tempnam(sys_get_temp_dir(), 'sheet_fixture_').'.csv';
    }

    /** @param array<int, array<int, scalar|null>> $rows */
    public function write(array $rows): static
    {
        $writer = new Writer;
        $writer->openToFile($this->path);

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
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
