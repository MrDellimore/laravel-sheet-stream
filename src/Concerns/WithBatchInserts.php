<?php

namespace MrDellimore\SheetStream\Concerns;

interface WithBatchInserts
{
    public function batchSize(): int;
}
