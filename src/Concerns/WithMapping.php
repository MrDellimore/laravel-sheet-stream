<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithMapping
{
    public function map(mixed $row): array;
}
