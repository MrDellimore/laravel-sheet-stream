<?php

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Support\Collection;

interface FromCollection
{
    public function collection(): Collection;
}
