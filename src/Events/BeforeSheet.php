<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Events;

class BeforeSheet
{
    public function __construct(
        public readonly object $sheetImport,
        public readonly int $sheetIndex,
        public readonly ?string $sheetName = null,
    ) {}
}
