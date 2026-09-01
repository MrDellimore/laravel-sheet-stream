<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\WithMultipleSheets;

class MultiSheetImport implements WithMultipleSheets
{
    public SimpleArrayImport $usersImport;

    public SimpleArrayImport $ordersImport;

    public function __construct()
    {
        $this->usersImport = new SimpleArrayImport;
        $this->ordersImport = new SimpleArrayImport;
    }

    public function sheets(): array
    {
        return [
            0 => $this->usersImport,
            1 => $this->ordersImport,
        ];
    }
}
