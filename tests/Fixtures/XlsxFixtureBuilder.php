<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Builds temporary .xlsx fixture files using the OpenSpout writer.
 * Files are written to a temp location and deleted after each test.
 */
class XlsxFixtureBuilder
{
    private readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('sheet_fixture_', true).'.xlsx';
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
        @unlink($this->path);
    }
}
