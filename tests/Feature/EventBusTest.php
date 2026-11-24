<?php

use MrDellimore\SheetStream\Concerns\RegistersEventListeners;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\AfterWriting;
use MrDellimore\SheetStream\Events\BeforeExport;
use MrDellimore\SheetStream\Events\BeforeImport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\BeforeWriting;
use MrDellimore\SheetStream\Events\ImportFailed;
use MrDellimore\SheetStream\Support\EventBus;

it('returns null when subject does not implement WithEvents', function () {
    $subject = new class {};

    expect(EventBus::for($subject))->toBeNull();
});

it('creates bus from WithEvents and dispatches events', function () {
    $fired = [];

    $subject = new class($fired) implements WithEvents
    {
        public function __construct(private array &$fired) {}

        public function registerEvents(): array
        {
            return [
                BeforeImport::class => function (BeforeImport $event) {
                    $this->fired[] = 'before';
                },
                AfterImport::class => function (AfterImport $event) {
                    $this->fired[] = 'after';
                },
            ];
        }
    };

    $bus = EventBus::for($subject);

    expect($bus)->toBeInstanceOf(EventBus::class);

    $bus->dispatch(new BeforeImport($subject));
    $bus->dispatch(new AfterImport($subject));

    expect($fired)->toBe(['before', 'after']);
});

it('does nothing when dispatching an unregistered event', function () {
    $fired = [];

    $subject = new class($fired) implements WithEvents
    {
        public function __construct(private array &$fired) {}

        public function registerEvents(): array
        {
            return [
                BeforeImport::class => function () {
                    $this->fired[] = 'before';
                },
            ];
        }
    };

    $bus = EventBus::for($subject);
    $bus->dispatch(new AfterImport($subject));

    expect($fired)->toBe([]);
});

// merge() is @deprecated in favour of forSheet(); the merge tests pin that it still works for callers that use it.
it('merges listeners from another subject', function () {
    $fired = [];

    $parent = new class($fired) implements WithEvents
    {
        public function __construct(private array &$fired) {}

        public function registerEvents(): array
        {
            return [
                BeforeImport::class => function () {
                    $this->fired[] = 'parent';
                },
            ];
        }
    };

    $child = new class($fired) implements WithEvents
    {
        public function __construct(private array &$fired) {}

        public function registerEvents(): array
        {
            return [
                BeforeImport::class => function () {
                    $this->fired[] = 'child';
                },
            ];
        }
    };

    $bus = EventBus::for($parent);
    $bus->merge($child);
    $bus->dispatch(new BeforeImport($parent));

    expect($fired)->toBe(['parent', 'child']);
});

it('merge skips non-WithEvents subjects', function () {
    $fired = [];

    $parent = new class($fired) implements WithEvents
    {
        public function __construct(private array &$fired) {}

        public function registerEvents(): array
        {
            return [
                BeforeImport::class => function () {
                    $this->fired[] = 'parent';
                },
            ];
        }
    };

    $plain = new class {};

    $bus = EventBus::for($parent);
    $bus->merge($plain);
    $bus->dispatch(new BeforeImport($parent));

    expect($fired)->toBe(['parent']);
});

it('calls multiple listeners for the same event in order', function () {
    $fired = [];

    $subject = new class($fired) implements WithEvents
    {
        public function __construct(private array &$fired) {}

        public function registerEvents(): array
        {
            return [
                BeforeImport::class => function () {
                    $this->fired[] = 'first';
                },
            ];
        }
    };

    $second = new class($fired) implements WithEvents
    {
        public function __construct(private array &$fired) {}

        public function registerEvents(): array
        {
            return [
                BeforeImport::class => function () {
                    $this->fired[] = 'second';
                },
            ];
        }
    };

    $bus = EventBus::for($subject);
    $bus->merge($second);
    $bus->dispatch(new BeforeImport($subject));

    expect($fired)->toBe(['first', 'second']);
});

/*
 * forSheet(): the bus for one sheet of a multi-sheet import or export.
 */

/** A WithEvents subject that logs "<owner>:<EventShortName>" and counts registerEvents() calls. */
function busSubject(string $owner, array $eventClasses, ArrayObject $log): WithEvents
{
    return new class($owner, $eventClasses, $log) implements WithEvents
    {
        public int $registerEventsCalls = 0;

        public function __construct(
            private readonly string $owner,
            private readonly array $eventClasses,
            private readonly ArrayObject $log,
        ) {}

        public function registerEvents(): array
        {
            $this->registerEventsCalls++;

            $listeners = [];
            foreach ($this->eventClasses as $eventClass) {
                $listeners[$eventClass] = function (object $event): void {
                    $this->log[] = $this->owner.':'.class_basename($event);
                };
            }

            return $listeners;
        }
    };
}

/** Dispatch one of every import and sheet event, in pipeline order. */
function dispatchEveryImportEvent(?EventBus $bus, object $parent, object $sheet): void
{
    $bus?->dispatch(new BeforeImport($parent));
    $bus?->dispatch(new BeforeSheet($sheet, 0, 'Sheet1'));
    $bus?->dispatch(new AfterChunk($sheet, 0, 0, 1));
    $bus?->dispatch(new AfterSheet($sheet, 0, 'Sheet1'));
    $bus?->dispatch(new AfterImport($parent));
    $bus?->dispatch(new ImportFailed($parent, new RuntimeException('boom')));
}

const EVERY_IMPORT_EVENT = [
    BeforeImport::class,
    BeforeSheet::class,
    AfterChunk::class,
    AfterSheet::class,
    AfterImport::class,
    ImportFailed::class,
];

it('lists exactly the sheet-scoped events', function () {
    expect(EventBus::SHEET_EVENTS)->toBe([BeforeSheet::class, AfterSheet::class, AfterChunk::class]);
});

it('forSheet returns the parent bus itself when the sheet is the parent', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', EVERY_IMPORT_EVENT, $log);
    $parentBus = EventBus::for($parent);

    expect(EventBus::forSheet($parentBus, $parent, $parent))->toBe($parentBus)
        ->and($parent->registerEventsCalls)->toBe(1);

    $plain = new stdClass;
    expect(EventBus::forSheet(null, $plain, $plain))->toBeNull();
});

it('forSheet fires only the sheet listener when parent and sheet both register AfterChunk', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', [AfterChunk::class], $log);
    $sheet = busSubject('sheet', [AfterChunk::class], $log);

    $bus = EventBus::forSheet(EventBus::for($parent), $parent, $sheet);
    $bus->dispatch(new AfterChunk($sheet, 0, 0, 10));

    expect($log->getArrayCopy())->toBe(['sheet:AfterChunk']);
});

it('forSheet falls back to the parent listener when only the parent registers AfterSheet', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', [AfterSheet::class], $log);
    $sheet = busSubject('sheet', [AfterChunk::class], $log);

    $bus = EventBus::forSheet(EventBus::for($parent), $parent, $sheet);
    $bus->dispatch(new AfterSheet($sheet, 0, 'Sheet1'));

    expect($log->getArrayCopy())->toBe(['parent:AfterSheet']);
});

it('forSheet falls back to the parent bus for a sheet without WithEvents', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', EVERY_IMPORT_EVENT, $log);
    $sheet = new stdClass;

    $bus = EventBus::forSheet(EventBus::for($parent), $parent, $sheet);
    dispatchEveryImportEvent($bus, $parent, $sheet);

    expect($log->getArrayCopy())->toBe([
        'parent:BeforeImport',
        'parent:BeforeSheet',
        'parent:AfterChunk',
        'parent:AfterSheet',
        'parent:AfterImport',
        'parent:ImportFailed',
    ]);
});

it('forSheet returns a bus for the sheet when the parent has no listeners at all', function () {
    $log = new ArrayObject;
    $parent = new stdClass;
    $sheet = busSubject('sheet', [BeforeSheet::class, AfterChunk::class, AfterSheet::class], $log);

    expect(EventBus::for($parent))->toBeNull();

    $bus = EventBus::forSheet(null, $parent, $sheet);

    expect($bus)->toBeInstanceOf(EventBus::class);

    dispatchEveryImportEvent($bus, $parent, $sheet);

    expect($log->getArrayCopy())->toBe(['sheet:BeforeSheet', 'sheet:AfterChunk', 'sheet:AfterSheet']);
});

it('forSheet returns null when neither the parent nor the sheet has listeners', function () {
    expect(EventBus::forSheet(EventBus::for(new stdClass), new stdClass, new stdClass))->toBeNull();
});

it('forSheet never delivers import-scoped events to the sheet', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', [BeforeImport::class, AfterImport::class, ImportFailed::class], $log);
    $sheet = busSubject('sheet', EVERY_IMPORT_EVENT, $log);

    $bus = EventBus::forSheet(EventBus::for($parent), $parent, $sheet);
    dispatchEveryImportEvent($bus, $parent, $sheet);

    expect($log->getArrayCopy())->toBe([
        'parent:BeforeImport',
        'sheet:BeforeSheet',
        'sheet:AfterChunk',
        'sheet:AfterSheet',
        'parent:AfterImport',
        'parent:ImportFailed',
    ]);
});

it('forSheet ignores a sheet that only registers import-scoped events', function () {
    $log = new ArrayObject;
    $parent = new stdClass;
    $sheet = busSubject('sheet', [BeforeImport::class, AfterImport::class, ImportFailed::class], $log);

    // Nothing the sheet registered can ever be raised on its bus.
    expect(EventBus::forSheet(null, $parent, $sheet))->toBeNull();

    $listening = busSubject('parent', [AfterSheet::class], $log);
    $parentBus = EventBus::for($listening);

    expect(EventBus::forSheet($parentBus, $listening, $sheet))->toBe($parentBus);
});

it('forSheet keeps export-scoped events on the parent', function () {
    $log = new ArrayObject;
    $exportEvents = [BeforeExport::class, BeforeWriting::class, AfterWriting::class];
    $parent = busSubject('parent', [...$exportEvents, AfterSheet::class], $log);
    $sheet = busSubject('sheet', [...$exportEvents, AfterSheet::class], $log);

    $bus = EventBus::forSheet(EventBus::for($parent), $parent, $sheet);
    $bus->dispatch(new BeforeExport($parent));
    $bus->dispatch(new AfterSheet($sheet, 0, 'Sheet1'));
    $bus->dispatch(new BeforeWriting($parent));
    $bus->dispatch(new AfterWriting($parent, '/tmp/out.xlsx'));

    expect($log->getArrayCopy())->toBe([
        'parent:BeforeExport',
        'sheet:AfterSheet',
        'parent:BeforeWriting',
        'parent:AfterWriting',
    ]);
});

it('forSheet decides per event class', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', [BeforeSheet::class, AfterChunk::class, AfterSheet::class], $log);
    $sheet = busSubject('sheet', [AfterSheet::class], $log);

    $bus = EventBus::forSheet(EventBus::for($parent), $parent, $sheet);
    $bus->dispatch(new BeforeSheet($sheet, 0, 'Sheet1'));
    $bus->dispatch(new AfterChunk($sheet, 0, 0, 10));
    $bus->dispatch(new AfterSheet($sheet, 0, 'Sheet1'));

    expect($log->getArrayCopy())->toBe(['parent:BeforeSheet', 'parent:AfterChunk', 'sheet:AfterSheet']);
});

it('forSheet gives every sheet its own bus and leaves the parent bus untouched', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', [AfterChunk::class, AfterSheet::class], $log);
    $sheetA = busSubject('a', [AfterChunk::class], $log);
    $sheetB = busSubject('b', [AfterSheet::class], $log);

    $parentBus = EventBus::for($parent);
    $busA = EventBus::forSheet($parentBus, $parent, $sheetA);
    $busB = EventBus::forSheet($parentBus, $parent, $sheetB);

    expect($busA)->not->toBe($parentBus)->and($busB)->not->toBe($parentBus)->and($busA)->not->toBe($busB);

    $busA->dispatch(new AfterChunk($sheetA, 0, 0, 1));
    $busA->dispatch(new AfterSheet($sheetA, 0, 'A'));
    $busB->dispatch(new AfterChunk($sheetB, 1, 0, 1));
    $busB->dispatch(new AfterSheet($sheetB, 1, 'B'));
    $parentBus->dispatch(new AfterChunk($parent, 2, 0, 1));
    $parentBus->dispatch(new AfterSheet($parent, 2, 'C'));

    expect($log->getArrayCopy())->toBe([
        'a:AfterChunk',
        'parent:AfterSheet',
        'parent:AfterChunk',
        'b:AfterSheet',
        'parent:AfterChunk',
        'parent:AfterSheet',
    ]);
});

it('forSheet does not accumulate listeners when one sheet object gets several buses', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', [AfterSheet::class], $log);
    $sheet = busSubject('sheet', [AfterSheet::class], $log);
    $parentBus = EventBus::for($parent);

    EventBus::forSheet($parentBus, $parent, $sheet);
    $second = EventBus::forSheet($parentBus, $parent, $sheet);
    $second->dispatch(new AfterSheet($sheet, 1, 'Sheet2'));

    expect($log->getArrayCopy())->toBe(['sheet:AfterSheet']);
});

it('forSheet calls the sheet registerEvents once per call and never the parent again', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', EVERY_IMPORT_EVENT, $log);
    $sheet = busSubject('sheet', [AfterChunk::class], $log);

    $parentBus = EventBus::for($parent);
    expect($parent->registerEventsCalls)->toBe(1)->and($sheet->registerEventsCalls)->toBe(0);

    EventBus::forSheet($parentBus, $parent, $sheet);
    expect($parent->registerEventsCalls)->toBe(1)->and($sheet->registerEventsCalls)->toBe(1);

    EventBus::forSheet($parentBus, $parent, $sheet);
    expect($parent->registerEventsCalls)->toBe(1)->and($sheet->registerEventsCalls)->toBe(2);
});

it('forSheet works with a sheet that uses the RegistersEventListeners trait', function () {
    $log = new ArrayObject;
    $parent = busSubject('parent', EVERY_IMPORT_EVENT, $log);

    $sheet = new readonly class($log) implements WithEvents
    {
        use RegistersEventListeners;

        public function __construct(private ArrayObject $log) {}

        public function beforeImport(BeforeImport $event): void
        {
            $this->log[] = 'sheet:BeforeImport';
        }

        public function afterSheet(AfterSheet $event): void
        {
            $this->log[] = 'sheet:AfterSheet';
        }

        public function importFailed(ImportFailed $event): void
        {
            $this->log[] = 'sheet:ImportFailed';
        }
    };

    $bus = EventBus::forSheet(EventBus::for($parent), $parent, $sheet);
    dispatchEveryImportEvent($bus, $parent, $sheet);

    expect($log->getArrayCopy())->toBe([
        'parent:BeforeImport',
        'parent:BeforeSheet',
        'parent:AfterChunk',
        'sheet:AfterSheet',
        'parent:AfterImport',
        'parent:ImportFailed',
    ]);
});
