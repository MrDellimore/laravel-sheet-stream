<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Events\AfterChunk;

/**
 * A two-sheet import whose sheets (LoggedSheet) log into the parent:
 *   ['collection', sheet index, chunk offset, [key => id]]
 *   ['AfterChunk', sheet index, chunk number, rows in chunk]
 *
 * Only the parent registers events and the sheets register none, so the log does not
 * depend on how listeners are routed between a parent and its sheets.
 */
class LoggedMultiSheetImport implements WithEvents, WithMultipleSheets
{
    /** @var list<array<int, mixed>> */
    public array $log = [];

    /** Source row of each chunk's first row, as reported by AfterChunk::$startRow. */
    public array $chunkStartRows = [];

    /** @var array<int, object> */
    public array $sheets;

    public function __construct(public int $chunkSize = 2)
    {
        $this->sheets = [new LoggedSheet($this, 0), new LoggedSheet($this, 1)];
    }

    public function sheets(): array
    {
        return $this->sheets;
    }

    public function chunkSize(): int
    {
        return $this->chunkSize;
    }

    public function registerEvents(): array
    {
        return [
            AfterChunk::class => function (AfterChunk $event): void {
                $this->log[] = ['AfterChunk', $event->sheetIndex, $event->chunkNumber, $event->rowsInChunk];
                $this->chunkStartRows[] = $event->startRow ?? null;
            },
        ];
    }
}
