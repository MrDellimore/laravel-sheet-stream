<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Support;

use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeSheet;

final class EventBus
{
    /**
     * Events raised for one sheet. A sub-import (or sub-export) that registers a listener for
     * one of these takes it over from the parent for its own sheet; see forSheet(). Every other
     * event is import-scoped and only ever reaches the parent.
     *
     * @var list<class-string>
     */
    public const array SHEET_EVENTS = [
        BeforeSheet::class,
        AfterSheet::class,
        AfterChunk::class,
    ];

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

    /**
     * The bus for one sheet of a WithMultipleSheets import or export.
     *
     * Per sheet-scoped event class (SHEET_EVENTS): when the sheet registers a listener, only the
     * sheet's listener fires; otherwise the parent's does. Import-scoped listeners always come
     * from the parent, and the sheet's own are ignored. This is Laravel Excel's behaviour wherever
     * a sheet listens, with the parent as a fallback where it does not.
     *
     * The parent's listeners are taken from $parentBus (its registerEvents() is not called again);
     * the sheet's registerEvents() is called once per call. Returns null when there is nothing to
     * dispatch to.
     */
    public static function forSheet(?self $parentBus, object $parent, object $sheet): ?self
    {
        if ($sheet === $parent || ! $sheet instanceof WithEvents) {
            return $parentBus;
        }

        $sheetListeners = array_intersect_key($sheet->registerEvents(), array_flip(self::SHEET_EVENTS));

        if ($sheetListeners === []) {
            return $parentBus;
        }

        $bus = new self;
        $bus->listeners = $parentBus instanceof self ? $parentBus->listeners : [];

        foreach ($sheetListeners as $eventClass => $listener) {
            $bus->listeners[$eventClass] = [$listener];
        }

        return $bus;
    }

    /**
     * @deprecated Appends every listener of $subject, so a parent and its sheet both fire and the
     *             sheet receives import-scoped events. Use forSheet() instead.
     */
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
