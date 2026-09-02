<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeExport;
use MrDellimore\SheetStream\Events\BeforeImport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\BeforeWriting;
use MrDellimore\SheetStream\Events\ImportFailed;

trait RegistersEventListeners
{
    public function registerEvents(): array
    {
        $events = [];

        $map = [
            BeforeImport::class => 'beforeImport',
            AfterImport::class => 'afterImport',
            ImportFailed::class => 'importFailed',
            BeforeSheet::class => 'beforeSheet',
            AfterSheet::class => 'afterSheet',
            AfterChunk::class => 'afterChunk',
            BeforeExport::class => 'beforeExport',
            BeforeWriting::class => 'beforeWriting',
        ];

        foreach ($map as $eventClass => $method) {
            if (method_exists($this, $method)) {
                $events[$eventClass] = [$this, $method];
            }
        }

        return $events;
    }
}
