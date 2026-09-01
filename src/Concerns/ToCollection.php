<?php

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Support\Collection;

interface ToCollection
{
    public function collection(Collection $collection): void;
}
