<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Support;

use MrDellimore\SheetStream\Concerns\WithEvents;

final class EventBus
{
    /** @var array<class-string, list<callable>> */
    private array $listeners = [];

    public static function for(object $subject): ?self
    {
        if (! $subject instanceof WithEvents) {
            return null;
        }

        $bus = new self();

        foreach ($subject->registerEvents() as $eventClass => $listener) {
            $bus->listeners[$eventClass][] = $listener;
        }

        return $bus;
    }

    public function merge(object $subject): void
    {
        if (! $subject instanceof WithEvents) {
            return;
        }

        foreach ($subject->registerEvents() as $eventClass => $listener) {
            $this->listeners[$eventClass][] = $listener;
        }
    }

    public function dispatch(object $event): void
    {
        $class = get_class($event);

        foreach ($this->listeners[$class] ?? [] as $listener) {
            $listener($event);
        }
    }
}
