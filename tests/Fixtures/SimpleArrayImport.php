<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class SimpleArrayImport implements ToArray, WithHeadingRow
{
    public array $result = [];

    public function array(array $array): void
    {
        $this->result = $array;
    }
}
