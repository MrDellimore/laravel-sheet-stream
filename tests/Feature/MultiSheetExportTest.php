<?php

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithTitle;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeExport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\BeforeWriting;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\EventTrackingExport;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetExport;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetImport;

it('exports multiple sheets with titles and round-trips correctly', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';

    try {
        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run(new MultiSheetExport, $writer);
        $writer->close();

        // Round-trip: re-import with multi-sheet import
        $import = new MultiSheetImport;
        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->usersImport->result)->toHaveCount(1)
            ->and($import->usersImport->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
            ->and($import->ordersImport->result)->toHaveCount(1)
            ->and($import->ordersImport->result[0])->toMatchArray(['product' => 'Widget', 'qty' => 5]);
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
});

/*
 * Sheet-scoped events on multi-sheet exports: BeforeSheet / AfterSheet go to the sub-export's
 * own listener when it registers one for that event class, to the parent's otherwise.
 * BeforeExport / BeforeWriting stay on the parent.
 */

dataset('routed export writers', ['openspout', 'phpspreadsheet', 'rust']);

/** Run a multi-sheet export through ExportRunner; returns the sheet names of the written file. */
function runRoutedExport(string $driver, Closure $buildExport): array
{
    if ($driver === 'rust' && ! RustReader::isAvailable()) {
        test()->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('routed_export_', true).'.xlsx';

    try {
        $writer = EngineFactory::writer($driver, 'xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run($buildExport($writer), $writer);
        $writer->close();

        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        $names = [];
        foreach ($reader->sheets() as $sheet) {
            $names[] = $sheet->name().'='.count(iterator_to_array($sheet->rows(), false));
        }
        $reader->close();

        return $names;
    } finally {
        @unlink($tmp);
    }
}

/** "<owner>:<Event>[:<sheetIndex>:<title of the sheet the writer is on>]" */
function routedExportListeners(string $owner, array $eventClasses, ArrayObject $log, object $writer): array
{
    $listeners = [];

    foreach ($eventClasses as $eventClass) {
        $listeners[$eventClass] = function (object $event) use ($owner, $log, $writer): void {
            $entry = $owner.':'.class_basename($event);

            if (property_exists($event, 'sheetIndex')) {
                $native = $writer->currentNativeSheet();
                $entry .= ':'.$event->sheetIndex.':'.(method_exists($native, 'getTitle') ? $native->getTitle() : $native->getName());
            }

            $log[] = $entry;
        };
    }

    return $listeners;
}

function routedExportSheet(string $title, ?array $listeners): object
{
    if ($listeners === null) {
        return new readonly class($title) implements FromCollection, WithTitle
        {
            public function __construct(private string $title) {}

            public function collection(): Collection
            {
                return new Collection([[$this->title, 1], [$this->title, 2]]);
            }

            public function title(): string
            {
                return $this->title;
            }
        };
    }

    return new readonly class($title, $listeners) implements FromCollection, WithEvents, WithTitle
    {
        public function __construct(private string $title, private array $listeners) {}

        public function collection(): Collection
        {
            return new Collection([[$this->title, 1], [$this->title, 2]]);
        }

        public function title(): string
        {
            return $this->title;
        }

        public function registerEvents(): array
        {
            return $this->listeners;
        }
    };
}

function routedExportParent(array $sheets, ?array $listeners): object
{
    if ($listeners === null) {
        return new readonly class($sheets) implements WithMultipleSheets
        {
            public function __construct(private array $sheets) {}

            public function sheets(): array
            {
                return $this->sheets;
            }
        };
    }

    return new readonly class($sheets, $listeners) implements WithEvents, WithMultipleSheets
    {
        public function __construct(private array $sheets, private array $listeners) {}

        public function sheets(): array
        {
            return $this->sheets;
        }

        public function registerEvents(): array
        {
            return $this->listeners;
        }
    };
}

const EVERY_EXPORT_RUNNER_EVENT = [BeforeExport::class, BeforeSheet::class, AfterSheet::class, BeforeWriting::class];

it('delivers a sub-export AfterSheet to its own listener while it is the current sheet', function (string $driver) {
    $log = new ArrayObject;
    $sheets = [];

    $names = runRoutedExport($driver, function (object $writer) use ($log, &$sheets): object {
        $sheets = [
            // Registers export-scoped events too: those must never reach a sub-export.
            routedExportSheet('Users', routedExportListeners('users', [AfterSheet::class, BeforeExport::class, BeforeWriting::class], $log, $writer)),
            routedExportSheet('Orders', null),
            routedExportSheet('Totals', routedExportListeners('totals', [BeforeSheet::class], $log, $writer)),
        ];

        return routedExportParent($sheets, routedExportListeners('parent', EVERY_EXPORT_RUNNER_EVENT, $log, $writer));
    });

    expect($log->getArrayCopy())->toBe([
        'parent:BeforeExport',
        'parent:BeforeSheet:0:Users',
        'users:AfterSheet:0:Users',
        'parent:BeforeSheet:1:Orders',
        'parent:AfterSheet:1:Orders',
        'totals:BeforeSheet:2:Totals',
        'parent:AfterSheet:2:Totals',
        'parent:BeforeWriting',
    ])->and($names)->toBe(['Users=2', 'Orders=2', 'Totals=2']);
})->with('routed export writers');

it('passes the sub-export itself to its own sheet listeners', function (string $driver) {
    $received = [];
    $sheets = [];

    runRoutedExport($driver, function () use (&$received, &$sheets): object {
        $record = function (object $event) use (&$received): void {
            $received[] = [class_basename($event), $event->sheetIndex, $event->sheetName, $event->sheet];
        };

        $sheets = [
            routedExportSheet('Users', [BeforeSheet::class => $record, AfterSheet::class => $record]),
            routedExportSheet('Orders', [AfterSheet::class => $record]),
        ];

        return routedExportParent($sheets, null);
    });

    // The parent has no WithEvents at all: the sheets' listeners still fire.
    expect($received)->toBe([
        ['BeforeSheet', 0, 'Users', $sheets[0]],
        ['AfterSheet', 0, 'Users', $sheets[0]],
        ['AfterSheet', 1, 'Orders', $sheets[1]],
    ]);
})->with('routed export writers');

it('still fires the parent sheet listeners for sub-exports that register none', function (string $driver) {
    $log = new ArrayObject;

    $names = runRoutedExport($driver, fn (object $writer): object => routedExportParent(
        [
            routedExportSheet('Users', null),
            // WithEvents, but nothing sheet-scoped: the parent keeps both sheet events.
            routedExportSheet('Orders', routedExportListeners('orders', [BeforeExport::class, BeforeWriting::class], $log, $writer)),
        ],
        routedExportListeners('parent', EVERY_EXPORT_RUNNER_EVENT, $log, $writer),
    ));

    expect($log->getArrayCopy())->toBe([
        'parent:BeforeExport',
        'parent:BeforeSheet:0:Users',
        'parent:AfterSheet:0:Users',
        'parent:BeforeSheet:1:Orders',
        'parent:AfterSheet:1:Orders',
        'parent:BeforeWriting',
    ])->and($names)->toBe(['Users=2', 'Orders=2']);
})->with('routed export writers');

it('leaves the events of a single-sheet export on the export itself', function (string $driver) {
    $export = new EventTrackingExport([['Name' => 'Alice', 'Email' => 'alice@example.com']]);

    runRoutedExport($driver, fn (): object => $export);

    expect(array_column($export->firedEvents, 'class'))->toBe(EVERY_EXPORT_RUNNER_EVENT);
})->with('routed export writers');
