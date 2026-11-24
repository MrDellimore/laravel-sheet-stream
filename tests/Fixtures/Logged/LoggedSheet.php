<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\RemembersChunkOffset;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

/** One sheet of a LoggedMultiSheetImport. It registers no events of its own. */
class LoggedSheet implements ToCollection, WithHeadingRow
{
    use RemembersChunkOffset;

    public function __construct(
        private readonly LoggedMultiSheetImport $parent,
        private readonly int $index,
        public int $chunkSize = 2,
    ) {}

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    public function collection(Collection $collection): void
    {
        $ids = [];

        foreach ($collection as $key => $row) {
            $ids[$key] = (int) $row['id'];
        }

        $this->parent->log[] = ['collection', $this->index, $this->getChunkOffset(), $ids];
    }
}
