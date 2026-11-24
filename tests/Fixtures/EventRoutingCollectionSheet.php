<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterSheet;

/** Sheet 0 of the reference workbook: listens for AfterChunk and AfterSheet with closures. */
class EventRoutingCollectionSheet implements ToCollection, WithEvents, WithHeadingRow
{
    public int $rowCount = 0;

    public int $registerEventsCalls = 0;

    public function collection(Collection $rows): void
    {
        $this->rowCount += $rows->count();
    }

    public function registerEvents(): array
    {
        $this->registerEventsCalls++;

        return [
            AfterChunk::class => function (AfterChunk $event): void {
                EventRoutingLog::record('sheet0', $event);
            },
            AfterSheet::class => function (AfterSheet $event): void {
                EventRoutingLog::record('sheet0', $event);
            },
        ];
    }
}
