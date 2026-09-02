<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Events;

class BeforeImport
{
    public function __construct(
        public readonly object $import,
    ) {}
}
