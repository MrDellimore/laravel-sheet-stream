<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class SimpleCollectionImport implements ToCollection, WithHeadingRow
{
    public Collection $result;

    public function __construct()
    {
        $this->result = new Collection;
    }

    public function collection(Collection $collection): void
    {
        $this->result = $collection;
    }
}
