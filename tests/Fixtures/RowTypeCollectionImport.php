<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\RemembersChunkOffset;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

/**
 * Keeps what collection() was given, so a test can look at the rows themselves:
 * their type, and the row number each key adds up to.
 */
class RowTypeCollectionImport implements ToCollection, WithHeadingRow
{
    use RemembersChunkOffset;

    /** @var list<Collection<int, mixed>> the Collection of each collection() call */
    public array $calls = [];

    /** @var list<mixed> every row, in order */
    public array $rows = [];

    /** @var list<int> getChunkOffset() + key of every row, in order */
    public array $rowNumbers = [];

    public function collection(Collection $collection): void
    {
        $this->calls[] = $collection;

        foreach ($collection as $key => $row) {
            $this->rows[] = $row;
            $this->rowNumbers[] = $this->getChunkOffset() + $key;
        }
    }

    /** get_debug_type() of every row, de-duplicated. */
    public function rowTypes(): array
    {
        return array_values(array_unique(array_map(get_debug_type(...), $this->rows)));
    }

    /** The id column of every row, in order. */
    public function ids(): array
    {
        return array_map(fn (mixed $row): int => (int) $row['id'], $this->rows);
    }
}
