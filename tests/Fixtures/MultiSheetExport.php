<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithTitle;

class MultiSheetExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new UsersSheet,
            new OrdersSheet,
        ];
    }
}

class UsersSheet implements FromCollection, WithHeadings, WithTitle
{
    public function collection(): Collection
    {
        return new Collection([
            ['Name' => 'Alice', 'Email' => 'alice@example.com'],
        ]);
    }

    public function headings(): array
    {
        return ['Name', 'Email'];
    }

    public function title(): string
    {
        return 'Users';
    }
}

class OrdersSheet implements FromCollection, WithHeadings, WithTitle
{
    public function collection(): Collection
    {
        return new Collection([
            ['Product' => 'Widget', 'Qty' => 5],
        ]);
    }

    public function headings(): array
    {
        return ['Product', 'Qty'];
    }

    public function title(): string
    {
        return 'Orders';
    }
}
