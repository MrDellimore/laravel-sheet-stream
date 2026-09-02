<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use MrDellimore\SheetStream\Concerns\RemembersRowNumber;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class RowNumberTrackingImport implements ToModel, WithBatchInserts, WithHeadingRow
{
    use RemembersRowNumber;

    /** @var array<int, int> */
    public array $observedRowNumbers = [];

    public function model(array $row): ?Model
    {
        $this->observedRowNumbers[] = $this->getRowNumber();

        return new StubModel([
            'name' => $row['name'],
            'email' => $row['email'],
        ]);
    }

    public function batchSize(): int
    {
        return 2;
    }
}
