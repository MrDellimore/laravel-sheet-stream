<?php

/**
 * Sheet-scoped event routing for WithMultipleSheets imports.
 *
 * BeforeSheet / AfterSheet / AfterChunk go to the sub-import's own listener when it registers one
 * for that event class and to the parent's otherwise; BeforeImport / AfterImport / ImportFailed
 * only ever reach the parent. The rule must hold on every execution path and every reader driver:
 *
 *   sync      SheetStream::import()
 *   queued    QueuedImportJob::handle()
 *   parallel  WithParallelSheets: QueuedImportJob fans out one QueuedSheetImportJob per sheet
 *   staging   StagingProducerJob, then one StagingChunkProcessorJob per chunk
 *
 * The fanned-out jobs are captured with Bus::fake() and run after the dispatching job returns,
 * which is the order a real queue worker gives. (On the "sync" queue connection a chunk job runs
 * inline at dispatch, before the producer reaches AfterSheet.)
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeImport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\ImportFailed;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Jobs\QueuedImportJob;
use MrDellimore\SheetStream\Jobs\QueuedSheetImportJob;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Tests\Fixtures\CsvFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\EventRoutingListeningModelSheet;
use MrDellimore\SheetStream\Tests\Fixtures\EventRoutingLog;
use MrDellimore\SheetStream\Tests\Fixtures\EventRoutingModelSheet;
use MrDellimore\SheetStream\Tests\Fixtures\EventRoutingMultiSheetImport;
use MrDellimore\SheetStream\Tests\Fixtures\EventRoutingParallelImport;
use MrDellimore\SheetStream\Tests\Fixtures\EventRoutingSheet;
use MrDellimore\SheetStream\Tests\Fixtures\EventRoutingSilentImport;
use MrDellimore\SheetStream\Tests\Fixtures\EventRoutingSilentParallelImport;
use MrDellimore\SheetStream\Tests\Fixtures\EventTrackingImport;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetXlsxFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\StubModel;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

uses(RefreshDatabase::class);

dataset('routing paths', ['sync', 'queued', 'parallel', 'staging']);
dataset('routing drivers', ['openspout', 'rust']);

beforeEach(function () {
    EventRoutingLog::reset();

    Schema::create('stubs', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });

    $this->stagingPath = sys_get_temp_dir().'/sheet_stream_routing_'.uniqid();
    app()->singleton(StagingStore::class, fn () => new FileStagingStore($this->stagingPath));
});

afterEach(function () {
    File::deleteDirectory($this->stagingPath);
});

/** Select the reader driver for the test, skipping when the rust extension is missing. */
function routingUseDriver(string $driver): void
{
    if ($driver === 'rust' && ! RustReader::isAvailable()) {
        test()->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    config(['sheet-stream.default_reader' => $driver]);
}

/** Liens: 3 data rows, Claims: 2, Extras: 1. With chunks of 2 that is 2 + 1 + 1 chunks. */
function routingWorkbook(): MultiSheetXlsxFixtureBuilder
{
    return (new MultiSheetXlsxFixtureBuilder)->write([
        'Liens' => [['Name', 'Email'], ['L1', 'l1@example.com'], ['L2', 'l2@example.com'], ['L3', 'l3@example.com']],
        'Claims' => [['Name', 'Email'], ['Alice', 'alice@example.com'], ['Bob', 'bob@example.com']],
        'Extras' => [['Name', 'Email'], ['X1', 'x1@example.com']],
    ]);
}

/** The listening parent in the shape the path needs (WithParallelSheets for the parallel path). */
function routingParent(string $path, array|Closure $sheets, array $listeners = EventRoutingMultiSheetImport::ALL_EVENTS): EventRoutingMultiSheetImport
{
    return $path === 'parallel'
        ? new EventRoutingParallelImport($sheets, $listeners)
        : new EventRoutingMultiSheetImport($sheets, $listeners);
}

/**
 * Run an import over one path with 2-row chunks. Fanned-out jobs run after their dispatcher.
 *
 * @return array{errors: list<Throwable>, jobs: list<object>} what was thrown, and the fanned-out jobs
 */
function runRoutingPath(string $path, object $import, string $file, int $chunkSize = 2): array
{
    $errors = [];
    $jobs = [];

    $attempt = function (Closure $run) use (&$errors): void {
        try {
            $run();
        } catch (Throwable $e) {
            $errors[] = $e;
        }
    };

    if ($path === 'sync') {
        config(['sheet-stream.batch_size' => $chunkSize]);
        $attempt(fn () => SheetStream::import($import, $file));
    }

    if ($path === 'queued') {
        $attempt(fn () => new QueuedImportJob(import: $import, filePath: $file, batchSize: $chunkSize)->handle());
    }

    if ($path === 'parallel') {
        Bus::fake([QueuedSheetImportJob::class]);
        $attempt(fn () => new QueuedImportJob(import: $import, filePath: $file, batchSize: $chunkSize)->handle());
        $jobs = Bus::dispatched(QueuedSheetImportJob::class)->all();
    }

    if ($path === 'staging') {
        Bus::fake([StagingChunkProcessorJob::class]);
        $attempt(fn () => new StagingProducerJob(import: $import, filePath: $file, chunkSize: $chunkSize, insertBatchSize: 100)->handle());
        $jobs = Bus::dispatched(StagingChunkProcessorJob::class)->all();
    }

    foreach ($jobs as $job) {
        $attempt(fn () => $job->handle());
    }

    return ['errors' => $errors, 'jobs' => array_values($jobs)];
}

/**
 * Turn the log a sequential run produces into what $path produces:
 *  - parallel: the per-sheet jobs raise no BeforeImport / AfterImport (nothing spans the fan-out);
 *  - staging: AfterSheet fires when a sheet is staged, before its chunks are processed, so only
 *    the multiset is comparable. Sort both sides with routingComparable().
 *
 * @param  list<string>  $sequential
 * @return list<string>
 */
function routingExpected(string $path, array $sequential): array
{
    if ($path === 'parallel') {
        $sequential = array_values(array_filter(
            $sequential,
            fn (string $entry): bool => ! in_array($entry, ['parent:BeforeImport', 'parent:AfterImport'], true),
        ));
    }

    return routingComparable($path, $sequential);
}

/**
 * @param  list<string>  $entries
 * @return list<string> exact order, except on the staging path where the order is not comparable
 */
function routingComparable(string $path, array $entries): array
{
    if ($path === 'staging') {
        sort($entries);
    }

    return $entries;
}

// ---------------------------------------------------------------------------------------------
// 11. Exact log of the reference import per path.
// ---------------------------------------------------------------------------------------------

it('routes every event of the reference import to the right owner', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();
    $sheets = EventRoutingMultiSheetImport::standardSheets();
    $import = routingParent($path, $sheets);

    $run = runRoutingPath($path, $import, $fixture->path());

    expect($run['errors'])->toBe([]);

    // Sheet 0 owns AfterChunk + AfterSheet, sheet 1 has no listeners (parent fallback),
    // sheet 2 ("Extras", mapped by name) owns BeforeSheet only.
    $sequential = [
        'parent:BeforeImport',
        'parent:BeforeSheet:0',
        'sheet0:AfterChunk:0#0',
        'sheet0:AfterChunk:0#1',
        'sheet0:AfterSheet:0',
        'parent:BeforeSheet:1',
        'parent:AfterChunk:1#0',
        'parent:AfterSheet:1',
        'sheet2:BeforeSheet:2',
        'parent:AfterChunk:2#0',
        'parent:AfterSheet:2',
        'parent:AfterImport',
    ];

    $expected = match ($path) {
        'sync', 'queued' => $sequential,
        // One job per sheet: sheet events in sheet order, no import-level events.
        'parallel' => array_slice($sequential, 1, -1),
        // Same events; AfterSheet precedes that sheet's AfterChunk, so compare as a multiset.
        'staging' => routingComparable('staging', $sequential),
    };

    expect(routingComparable($path, EventRoutingLog::$entries))->toBe($expected)
        ->and($sheets[0]->rowCount)->toBe(3)
        ->and(StubModel::pluck('name')->all())->toBe(['Alice', 'Bob'])
        ->and($sheets['Extras']->rowCount)->toBe(1);
})->with('routing paths')->with('routing drivers');

it('routes the same way once the fanned-out jobs have been serialized for a queue', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();
    $import = routingParent($path, EventRoutingMultiSheetImport::standardSheets());

    if ($path === 'parallel') {
        Bus::fake([QueuedSheetImportJob::class]);
        new QueuedImportJob(import: $import, filePath: $fixture->path(), batchSize: 2)->handle();
        $jobs = Bus::dispatched(QueuedSheetImportJob::class);
    } else {
        Bus::fake([StagingChunkProcessorJob::class]);
        new StagingProducerJob(import: $import, filePath: $fixture->path(), chunkSize: 2, insertBatchSize: 100)->handle();
        $jobs = Bus::dispatched(StagingChunkProcessorJob::class);
    }

    // Each worker gets its own copy of the parent and the sub-import.
    foreach ($jobs as $job) {
        unserialize(serialize($job))->handle();
    }

    $sheetEvents = [
        'parent:BeforeSheet:0',
        'sheet0:AfterChunk:0#0',
        'sheet0:AfterChunk:0#1',
        'sheet0:AfterSheet:0',
        'parent:BeforeSheet:1',
        'parent:AfterChunk:1#0',
        'parent:AfterSheet:1',
        'sheet2:BeforeSheet:2',
        'parent:AfterChunk:2#0',
        'parent:AfterSheet:2',
    ];

    expect(routingComparable($path, EventRoutingLog::$entries))->toBe(match ($path) {
        'parallel' => $sheetEvents,
        'staging' => routingComparable('staging', ['parent:BeforeImport', ...$sheetEvents, 'parent:AfterImport']),
    })->and(StubModel::pluck('name')->all())->toBe(['Alice', 'Bob']);
})->with(['parallel', 'staging'])->with('routing drivers');

// ---------------------------------------------------------------------------------------------
// 12. HHCPLRP / BRGPLRP shape: parent and both sheets advance the same progress counter.
// ---------------------------------------------------------------------------------------------

it('counts each chunk once when the parent and its sheets all register AfterChunk', function (string $path, string $driver) {
    routingUseDriver($driver);

    // Claims: 5 rows -> 3 chunks of 2. Liens: 4 rows -> exactly 2 chunks.
    $fixture = (new MultiSheetXlsxFixtureBuilder)->write([
        'Claims' => [
            ['Name', 'Email'],
            ['C1', 'c1@example.com'], ['C2', 'c2@example.com'], ['C3', 'c3@example.com'],
            ['C4', 'c4@example.com'], ['C5', 'c5@example.com'],
        ],
        'Liens' => [
            ['Name', 'Email'],
            ['L1', 'l1@example.com'], ['L2', 'l2@example.com'], ['L3', 'l3@example.com'], ['L4', 'l4@example.com'],
        ],
    ]);

    $progress = new stdClass;
    $progress->currentChunk = 0;
    $afterChunk = function () use ($progress): void {
        $progress->currentChunk++;
    };

    // Like LitPortal's importers: the parent and every sheet register the same sheet-level
    // listeners, each expecting to be the only one that runs for a sheet.
    $sheetListeners = [BeforeSheet::class, AfterSheet::class, AfterChunk::class => $afterChunk];
    $claims = new EventRoutingListeningModelSheet('claims', $sheetListeners);
    $liens = new EventRoutingSheet('liens', $sheetListeners);

    $import = routingParent($path, [0 => $claims, 1 => $liens], [
        BeforeImport::class,
        BeforeSheet::class,
        AfterSheet::class,
        AfterChunk::class => $afterChunk,
        AfterImport::class,
    ]);

    $run = runRoutingPath($path, $import, $fixture->path());

    $totalChunks = (int) ceil(5 / 2) + (int) ceil(4 / 2);

    expect($run['errors'])->toBe([])
        ->and($progress->currentChunk)->toBe($totalChunks)
        ->and(routingComparable('staging', EventRoutingLog::named('AfterChunk')))->toBe([
            'claims:AfterChunk:0#0',
            'claims:AfterChunk:0#1',
            'claims:AfterChunk:0#2',
            'liens:AfterChunk:1#0',
            'liens:AfterChunk:1#1',
        ])
        // The parent's sheet-level listeners are replaced for both sheets, not added to.
        ->and(EventRoutingLog::of('parent'))->toBe(
            $path === 'parallel' ? [] : ['parent:BeforeImport', 'parent:AfterImport']
        )
        ->and(StubModel::count())->toBe(5)
        ->and($liens->rowCount)->toBe(4);
})->with('routing paths')->with('routing drivers');

// ---------------------------------------------------------------------------------------------
// 13. SPClaimImport shape: the sheet's AfterSheet still sees the context the import set.
// ---------------------------------------------------------------------------------------------

it('runs a sheet AfterSheet before the parent can clear the context it depends on', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();

    $context = new stdClass;
    $context->user = null;
    $seen = [];

    // The parent sets a context for each sheet and clears it afterwards; the sheet finishes its
    // work in its own AfterSheet and needs the context while doing so.
    $sheet = new EventRoutingSheet('sp', [
        AfterSheet::class => function () use ($context, &$seen): void {
            $seen[] = $context->user;
        },
    ]);

    $import = routingParent($path, [0 => $sheet, 1 => new EventRoutingModelSheet], [
        BeforeSheet::class => function () use ($context): void {
            $context->user = 'importer';
        },
        AfterSheet::class => function () use ($context): void {
            $context->user = null;
        },
    ]);

    $run = runRoutingPath($path, $import, $fixture->path());

    expect($run['errors'])->toBe([])
        ->and($seen)->toBe(['importer'])
        ->and(EventRoutingLog::named('AfterSheet'))->toBe(['sp:AfterSheet:0', 'parent:AfterSheet:1'])
        ->and(EventRoutingLog::named('BeforeSheet'))->toBe(['parent:BeforeSheet:0', 'parent:BeforeSheet:1'])
        // Sheet 1 has no listeners, so the parent still clears the context after it.
        ->and($context->user)->toBeNull();
})->with('routing paths')->with('routing drivers');

it('keeps the context intact when the parent and the sheet both wrap BeforeSheet and AfterSheet', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();

    $context = new stdClass;
    $context->user = null;
    $setBy = [];
    $seen = [];

    // LitPortal's registerQueuedImportEvents(): BeforeSheet sets the audit context, AfterSheet
    // runs the class's own listener and then clears it. Parent and sheets all use the wrapper.
    $wrap = function (string $owner, ?Closure $custom = null) use ($context, &$setBy): array {
        return [
            BeforeSheet::class => function () use ($context, &$setBy, $owner): void {
                $context->user = 'importer';
                $setBy[] = $owner;
            },
            AfterSheet::class => function () use ($context, $custom): void {
                if ($custom instanceof Closure) {
                    $custom();
                }
                $context->user = null;
            },
            AfterChunk::class,
        ];
    };

    $updateAuditStatus = function () use ($context, &$seen): void {
        $seen[] = $context->user;
    };

    $spClaims = new EventRoutingSheet('sp', $wrap('sp', $updateAuditStatus));
    $troveris = new EventRoutingListeningModelSheet('troveris', $wrap('troveris'));
    $import = routingParent($path, [0 => $spClaims, 1 => $troveris], $wrap('parent'));

    $run = runRoutingPath($path, $import, $fixture->path());

    expect($run['errors'])->toBe([])
        // A merged parent + sheet bus runs the parent's AfterSheet first and yields [null] here.
        ->and($seen)->toBe(['importer'])
        ->and($setBy)->toBe(['sp', 'troveris'])
        ->and($context->user)->toBeNull()
        ->and(EventRoutingLog::of('parent'))->toBe([]);
})->with('routing paths')->with('routing drivers');

// ---------------------------------------------------------------------------------------------
// 14. A sheet's listeners only ever see their own sheet.
// ---------------------------------------------------------------------------------------------

it('never delivers one sheet events to another sheet listeners', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();

    $everySheetEvent = [BeforeSheet::class, AfterChunk::class, AfterSheet::class];
    $sheets = [
        0 => new EventRoutingSheet('sheet0', $everySheetEvent),
        1 => new EventRoutingListeningModelSheet('sheet1', $everySheetEvent),
        'Extras' => new EventRoutingSheet('sheet2', $everySheetEvent),
    ];

    $run = runRoutingPath($path, routingParent($path, $sheets), $fixture->path());

    expect($run['errors'])->toBe([])->and(EventRoutingLog::$events)->toHaveCount($path === 'parallel' ? 10 : 12);

    $ownSheet = ['sheet0' => [0, $sheets[0]], 'sheet1' => [1, $sheets[1]], 'sheet2' => [2, $sheets['Extras']]];

    foreach (EventRoutingLog::$events as ['owner' => $owner, 'event' => $event]) {
        if ($owner === 'parent') {
            // Every sheet listens for every sheet event, so nothing sheet-level is left for the parent.
            expect($event::class)->toBeIn([BeforeImport::class, AfterImport::class]);

            continue;
        }

        expect($event->sheetIndex)->toBe($ownSheet[$owner][0])
            ->and($event->sheet)->toBe($ownSheet[$owner][1]);
    }

    foreach (['sheet0' => 0, 'sheet1' => 1, 'sheet2' => 2] as $owner => $index) {
        $chunks = $index === 0 ? ["{$owner}:AfterChunk:0#0", "{$owner}:AfterChunk:0#1"] : ["{$owner}:AfterChunk:{$index}#0"];

        expect(routingComparable('staging', EventRoutingLog::of($owner)))->toBe(routingComparable('staging', [
            "{$owner}:BeforeSheet:{$index}",
            ...$chunks,
            "{$owner}:AfterSheet:{$index}",
        ]));
    }
})->with('routing paths')->with('routing drivers');

// ---------------------------------------------------------------------------------------------
// 15. Failure inside a sheet.
// ---------------------------------------------------------------------------------------------

it('reports a failing sheet to the parent ImportFailed only', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();

    $failing = new EventRoutingListeningModelSheet('sheet1', [AfterChunk::class, AfterSheet::class, ImportFailed::class]);
    $failing->throwOnName = 'Bob';

    $import = routingParent($path, [
        0 => new EventRoutingSheet('sheet0', [AfterSheet::class]),
        1 => $failing,
    ]);

    $run = runRoutingPath($path, $import, $fixture->path());

    expect($run['errors'])->toHaveCount(1)
        ->and($run['errors'][0])->toBeInstanceOf(RuntimeException::class)
        ->and($run['errors'][0]->getMessage())->toBe('Cannot import Bob')
        ->and(EventRoutingLog::named('ImportFailed'))->toBe(['parent:ImportFailed'])
        ->and(EventRoutingLog::$entries)->toContain('sheet0:AfterSheet:0')
        ->and(EventRoutingLog::named('AfterChunk'))->toBe(['parent:AfterChunk:0#0', 'parent:AfterChunk:0#1'])
        ->and(EventRoutingLog::$entries)->not->toContain('parent:AfterSheet:1')
        ->and(StubModel::count())->toBe(0);

    $failed = collect(EventRoutingLog::$events)->pluck('event')->first(fn (object $event): bool => $event instanceof ImportFailed);

    expect($failed->import)->toBe($import)->and($failed->exception)->toBe($run['errors'][0]);

    if ($path === 'staging') {
        // The sheet was staged successfully (its AfterSheet fired then); it failed in a chunk job.
        expect(EventRoutingLog::$entries)->toContain('sheet1:AfterSheet:1')->toContain('parent:AfterImport');
    } else {
        expect(EventRoutingLog::$entries)->not->toContain('sheet1:AfterSheet:1')->not->toContain('parent:AfterImport');
    }
})->with('routing paths')->with('routing drivers');

// ---------------------------------------------------------------------------------------------
// 16. One sub-import instance mapped to two sheets.
// ---------------------------------------------------------------------------------------------

it('fires a shared sub-import listeners once per sheet it is mapped to', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();

    $shared = new EventRoutingSheet('shared', [BeforeSheet::class, AfterChunk::class, AfterSheet::class]);
    $import = routingParent($path, ['Liens' => $shared, 'Extras' => $shared]);

    $run = runRoutingPath($path, $import, $fixture->path());

    expect($run['errors'])->toBe([])
        ->and(routingComparable($path, EventRoutingLog::$entries))->toBe(routingExpected($path, [
            'parent:BeforeImport',
            'shared:BeforeSheet:0',
            'shared:AfterChunk:0#0',
            'shared:AfterChunk:0#1',
            'shared:AfterSheet:0',
            // "Claims" (index 1) is not mapped: no events at all.
            'shared:BeforeSheet:2',
            'shared:AfterChunk:2#0',
            'shared:AfterSheet:2',
            'parent:AfterImport',
        ]))
        ->and($shared->rowCount)->toBe(4);
})->with('routing paths')->with('routing drivers');

// ---------------------------------------------------------------------------------------------
// 17. Single-sheet imports are untouched (same expectations as ImportEventsTest).
//     Staging is the one path that changes: the chunk job used to merge() the import into its
//     own bus, so every listener of a single-sheet staged import ran twice per AfterChunk.
// ---------------------------------------------------------------------------------------------

it('leaves the event log of a single-sheet import unchanged', function (string $path, string $driver, string $format) {
    routingUseDriver($driver);

    $rows = [['Name', 'Email'], ['Alice', 'alice@example.com'], ['Bob', 'bob@example.com']];
    $fixture = $format === 'csv' ? (new CsvFixtureBuilder)->write($rows) : (new XlsxFixtureBuilder)->write($rows);

    $import = new EventTrackingImport;
    $run = runRoutingPath($path, $import, $fixture->path());

    $classes = array_column($import->firedEvents, 'class');
    $expected = [BeforeImport::class, BeforeSheet::class, AfterChunk::class, AfterSheet::class, AfterImport::class];

    expect($run['errors'])->toBe([])
        ->and(routingComparable($path, $classes))->toBe(routingComparable($path, $expected))
        ->and($import->result)->toHaveCount(2);

    foreach ($import->firedEvents as ['event' => $event]) {
        expect($event instanceof BeforeImport || $event instanceof AfterImport ? $event->import : $event->sheet)->toBe($import);
    }
})->with(['sync', 'queued', 'staging'])->with('routing drivers')->with(['xlsx', 'csv']);

// ---------------------------------------------------------------------------------------------
// 18. Parent without WithEvents.
// ---------------------------------------------------------------------------------------------

it('fires the sheets own events when the parent does not implement WithEvents', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();
    $sheets = EventRoutingMultiSheetImport::standardSheets();

    $import = $path === 'parallel' ? new EventRoutingSilentParallelImport($sheets) : new EventRoutingSilentImport($sheets);

    $run = runRoutingPath($path, $import, $fixture->path());

    expect($run['errors'])->toBe([])
        ->and(routingComparable($path, EventRoutingLog::$entries))->toBe(routingComparable($path, [
            'sheet0:AfterChunk:0#0',
            'sheet0:AfterChunk:0#1',
            'sheet0:AfterSheet:0',
            'sheet2:BeforeSheet:2',
        ]))
        ->and($sheets[0]->rowCount)->toBe(3)
        ->and(StubModel::count())->toBe(2)
        ->and($sheets['Extras']->rowCount)->toBe(1);
})->with('routing paths')->with('routing drivers');

it('still reports nothing when a WithEvents-less parent has a failing sheet', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();

    $failing = new EventRoutingListeningModelSheet('sheet1', [ImportFailed::class, BeforeSheet::class]);
    $failing->throwOnName = 'Alice';
    $sheets = [1 => $failing];

    $import = $path === 'parallel' ? new EventRoutingSilentParallelImport($sheets) : new EventRoutingSilentImport($sheets);

    $run = runRoutingPath($path, $import, $fixture->path());

    // ImportFailed is import-scoped: a sub-import's listener for it is never called.
    expect($run['errors'])->toHaveCount(1)
        ->and(EventRoutingLog::$entries)->toBe(['sheet1:BeforeSheet:1']);
})->with('routing paths')->with('routing drivers');

// ---------------------------------------------------------------------------------------------
// Edge: a mapped sheet with a heading row and no data.
// ---------------------------------------------------------------------------------------------

it('raises BeforeSheet and AfterSheet but no AfterChunk on the listeners of an empty sheet', function (string $path, string $driver) {
    routingUseDriver($driver);

    $fixture = (new MultiSheetXlsxFixtureBuilder)->write([
        'Liens' => [['Name', 'Email']],
        'Claims' => [['Name', 'Email'], ['Alice', 'alice@example.com']],
    ]);

    $empty = new EventRoutingSheet('empty', [BeforeSheet::class, AfterChunk::class, AfterSheet::class]);
    $import = routingParent($path, [0 => $empty, 1 => new EventRoutingModelSheet]);

    $run = runRoutingPath($path, $import, $fixture->path());

    expect($run['errors'])->toBe([])
        ->and(routingComparable($path, EventRoutingLog::$entries))->toBe(routingExpected($path, [
            'parent:BeforeImport',
            'empty:BeforeSheet:0',
            'empty:AfterSheet:0',
            'parent:BeforeSheet:1',
            'parent:AfterChunk:1#0',
            'parent:AfterSheet:1',
            'parent:AfterImport',
        ]))
        ->and(StubModel::count())->toBe(1);
})->with('routing paths')->with('routing drivers');

// ---------------------------------------------------------------------------------------------
// 19. Staging: AfterSheet means "staged" (decision D1, documented in docs/staging-pipeline.md).
// ---------------------------------------------------------------------------------------------

it('fires a staged sheet AfterSheet before any of its chunks are processed', function (string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();

    $rowsAtAfterSheet = null;
    $claims = new EventRoutingListeningModelSheet('claims', [
        AfterChunk::class,
        AfterSheet::class => function () use (&$rowsAtAfterSheet): void {
            $rowsAtAfterSheet = StubModel::count();
        },
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);
    new StagingProducerJob(
        import: new EventRoutingMultiSheetImport([1 => $claims]),
        filePath: $fixture->path(),
        chunkSize: 1,
        insertBatchSize: 100,
    )->handle();

    // The producer is done: the sheet's own AfterSheet has fired, nothing has been imported yet.
    expect(EventRoutingLog::$entries)->toBe([
        'parent:BeforeImport',
        'parent:BeforeSheet:1',
        'claims:AfterSheet:1',
        'parent:AfterImport',
    ])
        ->and($rowsAtAfterSheet)->toBe(0)
        ->and(StubModel::count())->toBe(0);

    $jobs = Bus::dispatched(StagingChunkProcessorJob::class);
    expect($jobs)->toHaveCount(2);

    foreach ($jobs as $job) {
        $job->handle();
    }

    expect(array_slice(EventRoutingLog::$entries, 4))->toBe(['claims:AfterChunk:1#0', 'claims:AfterChunk:1#1'])
        ->and(StubModel::pluck('name')->all())->toBe(['Alice', 'Bob']);
})->with('routing drivers');

// ---------------------------------------------------------------------------------------------
// 20. sheets() is called once per import; a sheet's events and jobs share one sub-import.
// ---------------------------------------------------------------------------------------------

it('calls sheets() once and hands one sub-import instance to all of a sheet events', function (string $path, string $driver) {
    routingUseDriver($driver);
    $fixture = routingWorkbook();

    // A new set of sub-imports on every call, like an import that builds them in sheets().
    $everySheetEvent = [BeforeSheet::class, AfterChunk::class, AfterSheet::class];
    $import = routingParent($path, fn (): array => [
        0 => new EventRoutingSheet('sheet0', $everySheetEvent),
        1 => new EventRoutingListeningModelSheet('sheet1', $everySheetEvent),
        'Extras' => new EventRoutingSheet('sheet2', $everySheetEvent),
    ]);

    $run = runRoutingPath($path, $import, $fixture->path());

    expect($run['errors'])->toBe([])->and($import->sheetsCalls)->toBe(1);

    $instances = [];
    foreach (EventRoutingLog::$events as ['event' => $event]) {
        if (property_exists($event, 'sheetIndex')) {
            $instances[$event->sheetIndex][] = $event->sheet;
        }
    }

    // BeforeSheet + AfterSheet + the sheet's chunks (2, 1 and 1).
    expect(array_map(count(...), $instances))->toBe([0 => 4, 1 => 3, 2 => 3]);

    foreach ($instances as $sheetInstances) {
        foreach ($sheetInstances as $instance) {
            expect($instance)->toBe($sheetInstances[0]);
        }
    }

    foreach ($run['jobs'] as $job) {
        expect($job->sheetImport)->toBe($instances[$job->sheetIndex][0]);
    }

    expect($run['jobs'])->toHaveCount(match ($path) {
        'parallel' => 3,
        'staging' => 4,
        default => 0,
    });
})->with('routing paths')->with('routing drivers');
