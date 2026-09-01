<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithMultipleSheets
{
    public function sheets(): array;
}
