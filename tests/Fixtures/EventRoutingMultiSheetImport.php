<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Closure;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeImport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\ImportFailed;

/**
 * Parent import for the event-routing tests. Registers every import event by default and logs
 * each one as "parent:…" in EventRoutingLog.
 */
class EventRoutingMultiSheetImport implements WithEvents, WithMultipleSheets
{
    public const ALL_EVENTS = [
        BeforeImport::class,
        BeforeSheet::class,
        AfterChunk::class,
        AfterSheet::class,
        AfterImport::class,
        ImportFailed::class,
    ];

    public int $sheetsCalls = 0;

    public int $registerEventsCalls = 0;

    /**
     * @param  array<int|string, object>|Closure(): array<int|string, object>  $sheets  the sheet map, or a factory called on every sheets() call
     * @param  array<int|class-string, class-string|Closure|null>  $listeners  see EventRoutingLog::listeners()
     */
    public function __construct(
        private readonly array|Closure $sheets,
        private readonly array $listeners = self::ALL_EVENTS,
    ) {}

    /**
     * The plan's reference workbook: sheet 0 (ToCollection) listens for AfterChunk + AfterSheet,
     * sheet 1 (ToModel) has no events, sheet "Extras" is mapped by name and listens for
     * BeforeSheet through the RegistersEventListeners trait.
     *
     * @return array<int|string, object>
     */
    public static function standardSheets(): array
    {
        return [
            0 => new EventRoutingCollectionSheet,
            1 => new EventRoutingModelSheet,
            'Extras' => new EventRoutingNamedSheet,
        ];
    }

    public function sheets(): array
    {
        $this->sheetsCalls++;

        return $this->sheets instanceof Closure ? ($this->sheets)() : $this->sheets;
    }

    public function registerEvents(): array
    {
        $this->registerEventsCalls++;

        return EventRoutingLog::listeners('parent', $this->listeners);
    }
}
