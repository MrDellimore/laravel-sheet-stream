<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithHeadings
{
    public function headings(): array;
}
