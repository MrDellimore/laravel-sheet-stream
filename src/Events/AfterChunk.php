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
    ) {}
}
