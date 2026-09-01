<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithBatchInserts
{
    public function batchSize(): int;
}
