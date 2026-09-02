<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeExport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\BeforeWriting;

class EventTrackingExport implements FromCollection, WithEvents, WithHeadings
{
    public array $firedEvents = [];

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

    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                $this->firedEvents[] = ['class' => BeforeExport::class, 'event' => $event];
            },
            BeforeSheet::class => function (BeforeSheet $event) {
                $this->firedEvents[] = ['class' => BeforeSheet::class, 'event' => $event];
            },
            AfterSheet::class => function (AfterSheet $event) {
                $this->firedEvents[] = ['class' => AfterSheet::class, 'event' => $event];
            },
            BeforeWriting::class => function (BeforeWriting $event) {
                $this->firedEvents[] = ['class' => BeforeWriting::class, 'event' => $event];
            },
        ];
    }
}
