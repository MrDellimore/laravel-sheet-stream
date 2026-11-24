<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

/** Sheet 1 of the reference workbook: a ToModel sheet with no WithEvents. */
class EventRoutingModelSheet implements ToModel, WithHeadingRow
{
    /** A row whose name equals this makes model() throw. */
    public ?string $throwOnName = null;

    public function model(array $row): ?Model
    {
        if ($this->throwOnName !== null && $row['name'] === $this->throwOnName) {
            throw new \RuntimeException("Cannot import {$row['name']}");
        }

        return new StubModel([
            'name' => $row['name'],
            'email' => $row['email'],
        ]);
    }
}
