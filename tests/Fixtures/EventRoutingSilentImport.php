<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\WithMultipleSheets;

/** A multi-sheet parent that does not implement WithEvents at all. */
class EventRoutingSilentImport implements WithMultipleSheets
{
    public int $sheetsCalls = 0;

    /** @param array<int|string, object> $sheets */
    public function __construct(private readonly array $sheets) {}

    public function sheets(): array
    {
        $this->sheetsCalls++;

        return $this->sheets;
    }
}
