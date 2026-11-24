<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Closure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

/** A ToArray sheet with configurable, logged listeners. */
class EventRoutingSheet implements ToArray, WithEvents, WithHeadingRow
{
    public int $rowCount = 0;

    public int $registerEventsCalls = 0;

    /** @param array<int|class-string, class-string|Closure|null> $listeners see EventRoutingLog::listeners() */
    public function __construct(
        private readonly string $owner,
        private readonly array $listeners,
    ) {}

    public function array(array $rows): void
    {
        $this->rowCount += count($rows);
    }

    public function registerEvents(): array
    {
        $this->registerEventsCalls++;

        return EventRoutingLog::listeners($this->owner, $this->listeners);
    }
}
