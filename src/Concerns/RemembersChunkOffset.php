<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

trait RemembersChunkOffset
{
    private int $currentChunkOffset = 0;

    public function setChunkOffset(int $offset): void
    {
        $this->currentChunkOffset = $offset;
    }

    public function getChunkOffset(): int
    {
        return $this->currentChunkOffset;
    }
}
