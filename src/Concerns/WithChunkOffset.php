<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithChunkOffset
{
    public function setChunkOffset(int $offset): void;

    public function getChunkOffset(): int;
}
