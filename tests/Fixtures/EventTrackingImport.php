<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeImport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\ImportFailed;

class EventTrackingImport implements ToArray, WithEvents, WithHeadingRow
{
    public array $result = [];

    public array $firedEvents = [];

    public function array(array $array): void
    {
        $this->result = $array;
    }

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function (BeforeImport $event) {
                $this->firedEvents[] = ['class' => BeforeImport::class, 'event' => $event];
            },
            AfterImport::class => function (AfterImport $event) {
                $this->firedEvents[] = ['class' => AfterImport::class, 'event' => $event];
            },
            BeforeSheet::class => function (BeforeSheet $event) {
                $this->firedEvents[] = ['class' => BeforeSheet::class, 'event' => $event];
            },
            AfterSheet::class => function (AfterSheet $event) {
                $this->firedEvents[] = ['class' => AfterSheet::class, 'event' => $event];
            },
            AfterChunk::class => function (AfterChunk $event) {
                $this->firedEvents[] = ['class' => AfterChunk::class, 'event' => $event];
            },
            ImportFailed::class => function (ImportFailed $event) {
                $this->firedEvents[] = ['class' => ImportFailed::class, 'event' => $event];
            },
        ];
    }
}
