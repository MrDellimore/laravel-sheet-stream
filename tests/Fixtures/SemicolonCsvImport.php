<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use OpenSpout\Reader\CSV\Options;

class SemicolonCsvImport implements ToArray, WithHeadingRow, WithReaderOptions
{
    public array $result = [];

    public function array(array $array): void
    {
        $this->result = $array;
    }

    public function readerOptions(): object
    {
        $options = new Options;
        $options->FIELD_DELIMITER = ';';

        return $options;
    }
}
