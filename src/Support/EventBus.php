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

        $bus = new self;
        $bus->addListenersFrom($subject);

        return $bus;
    }

    public function merge(object $subject): void
    {
        if ($subject instanceof WithEvents) {
            $this->addListenersFrom($subject);
        }
    }

    private function addListenersFrom(WithEvents $subject): void
    {
        foreach ($subject->registerEvents() as $eventClass => $listener) {
            $this->listeners[$eventClass][] = $listener;
        }
    }

    public function dispatch(object $event): void
    {
        $class = $event::class;

        foreach ($this->listeners[$class] ?? [] as $listener) {
            $listener($event);
        }
    }
}
