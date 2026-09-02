<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Events;

class AfterImport
{
    public function __construct(
        public readonly object $import,
    ) {}
}
