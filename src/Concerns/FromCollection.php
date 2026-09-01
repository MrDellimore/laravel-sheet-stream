<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Support\Collection;

interface FromCollection
{
    public function collection(): Collection;
}
