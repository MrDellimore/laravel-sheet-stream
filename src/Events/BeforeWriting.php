<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Events;

class BeforeWriting
{
    public function __construct(
        public readonly object $export,
    ) {}
}
