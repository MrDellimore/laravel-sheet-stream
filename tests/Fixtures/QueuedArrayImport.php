<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class QueuedArrayImport implements ShouldQueue, ToArray, WithHeadingRow
{
    public array $result = [];

    public function array(array $array): void
    {
        $this->result = $array;
    }
}
