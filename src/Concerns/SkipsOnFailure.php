<?php

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Imports\Failure;

interface SkipsOnFailure
{
    /** @param Collection<int, Failure> $failures */
    public function onFailure(Collection $failures): void;
}
