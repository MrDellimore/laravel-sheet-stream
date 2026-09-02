<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use MrDellimore\SheetStream\Concerns\RemembersChunkOffset;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithChunkOffset;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class ChunkOffsetTrackingImport implements ToModel, WithBatchInserts, WithChunkOffset, WithHeadingRow
{
    use RemembersChunkOffset;

    /** @var array<int, int> */
    public array $observedChunkOffsets = [];

    public function model(array $row): ?Model
    {
        $this->observedChunkOffsets[] = $this->getChunkOffset();

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
