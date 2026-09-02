<?php

use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\BeforeImport;
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
