<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\WithHeadings;

class QueuedCollectionExport implements FromCollection, ShouldQueue, WithHeadings
{
    public function __construct(
        private array $rows = [],
    ) {}

    public function collection(): Collection
    {
        return new Collection($this->rows);
    }

    public function headings(): array
    {
        return ['Name', 'Email'];
    }
}
