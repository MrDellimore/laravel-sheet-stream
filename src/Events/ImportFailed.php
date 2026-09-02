<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Events;

class ImportFailed
{
    public function __construct(
        public readonly object $import,
        public readonly \Throwable $exception,
    ) {}
}
