<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Closure;
use MrDellimore\SheetStream\Concerns\WithEvents;

/** A ToModel sheet with configurable, logged listeners. */
class EventRoutingListeningModelSheet extends EventRoutingModelSheet implements WithEvents
{
    /** @param array<int|class-string, class-string|Closure|null> $listeners see EventRoutingLog::listeners() */
    public function __construct(
        private readonly string $owner,
        private readonly array $listeners,
    ) {}

    public function registerEvents(): array
    {
        return EventRoutingLog::listeners($this->owner, $this->listeners);
    }
}
