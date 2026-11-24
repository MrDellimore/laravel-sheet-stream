<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Events;

class AfterChunk
{
    public function __construct(
        public readonly object $sheet,
        public readonly int $sheetIndex,
        public readonly int $chunkNumber,
        public readonly int $rowsInChunk,
        /** Row number of the chunk's first row; null where it isn't known (the staging pipeline). */
        public readonly ?int $startRow = null,
    ) {}
}
