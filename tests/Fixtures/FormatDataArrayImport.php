<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithFormatData;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class FormatDataArrayImport implements ToArray, WithFormatData, WithHeadingRow
{
    public array $result = [];

    public function array(array $array): void
    {
        $this->result = $array;
    }
}
