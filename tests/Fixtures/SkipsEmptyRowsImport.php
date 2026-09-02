<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\ToArray;

class SkipsEmptyRowsImport implements SkipsEmptyRows, ToArray
{
    public array $result = [];

    public function array(array $array): void
    {
        $this->result = $array;
    }
}
