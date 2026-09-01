<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithMapping;

class MappedExport implements FromCollection, WithHeadings, WithMapping
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
        return ['Full Name', 'Email Address'];
    }

    public function map(mixed $row): array
    {
        return [
            strtoupper($row['name']),
            strtolower($row['email']),
        ];
    }
}
