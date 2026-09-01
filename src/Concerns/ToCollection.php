<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Support\Collection;

interface ToCollection
{
    public function collection(Collection $collection): void;
}
