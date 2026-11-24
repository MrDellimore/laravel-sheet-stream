<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;
use MrDellimore\SheetStream\Concerns\WithChunkReading;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithParallelSheets;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Jobs\QueuedImportJob;
use MrDellimore\SheetStream\Jobs\QueuedSheetImportJob;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Support\CollectionRows;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedFixture;
use MrDellimore\SheetStream\Tests\Fixtures\ReaderDrivers;
use MrDellimore\SheetStream\Tests\Fixtures\RowTypeCollectionImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

/*
 * ToCollection rows are Illuminate\Support\Collection instances, as in Laravel Excel,
 * unless collection_rows is 'array'. ToArray rows are always plain arrays.
 *
 * The id of a fixture row is its spreadsheet row number (the heading is row 1).
 */

/** A RowTypeCollectionImport, read whole or in chunks of 2, with any extra concerns the test needs. */
function rowTypeImport(bool $chunked): RowTypeCollectionImport
{
    return $chunked
        ? new class extends RowTypeCollectionImport implements WithChunkReading
        {
            public function chunkSize(): int
            {
                return 2;
            }
        }
    : new RowTypeCollectionImport;
}

dataset('chunk modes', ['whole sheet' => [false], 'chunked' => [true]]);

// --- the default: Collection rows ---

it('hands ToCollection a Collection of Collections', function (bool $chunked, string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = ReaderDrivers::import($driver, rowTypeImport($chunked), $fixture->path());

    expect($import->calls)->toHaveCount($chunked ? 2 : 1)
        ->and($import->rowTypes())->toBe([Collection::class])
        ->and($import->rows)->toHaveCount(3);

    foreach ($import->calls as $call) {
        expect($call)->toBeInstanceOf(Collection::class);
    }

    // Everything Laravel Excel importers do with a row.
    $row = $import->rows[1];
    $seen = [];
    foreach ($row as $heading => $value) {
        $seen[$heading] = $value;
    }

    expect($row->toArray())->toBe(['id' => 3, 'name' => 'B'])
        ->and($row->get('name'))->toBe('B')
        ->and($row->get('missing', 'fallback'))->toBe('fallback')
        ->and($row['name'])->toBe('B')
        ->and(isset($row['name']))->toBeTrue()
        ->and(isset($row['missing']))->toBeFalse()
        ->and($row->keys()->all())->toBe(['id', 'name'])
        ->and($seen)->toBe(['id' => 3, 'name' => 'B'])
        ->and($import->calls[0]->first()->toArray())->toBe(['id' => 2, 'name' => 'A'])
        ->and($import->calls[0]->pluck('name')->all())->toBe($chunked ? ['A', 'B'] : ['A', 'B', 'C']);
})->with('chunk modes')->with(ReaderDrivers::ALL);

it('makes rows without a heading row Collections of the cell values', function (bool $chunked, string $driver) {
    $import = $chunked
        ? new class implements ToCollection, WithChunkReading
        {
            public array $rows = [];

            public function collection(Collection $collection): void
            {
                array_push($this->rows, ...$collection->values()->all());
            }

            public function chunkSize(): int
            {
                return 2;
            }
        }
    : new class implements ToCollection
    {
        public array $rows = [];

        public function collection(Collection $collection): void
        {
            array_push($this->rows, ...$collection->values()->all());
        }
    };

    $fixture = (new XlsxFixtureBuilder)->write([['A', 1], ['B', 2], ['C', 3]]);
    ReaderDrivers::import($driver, $import, $fixture->path());

    expect(array_map(get_debug_type(...), $import->rows))->toBe(array_fill(0, 3, Collection::class))
        ->and(array_map(fn (Collection $row): array => $row->all(), $import->rows))->toBe([['A', 1], ['B', 2], ['C', 3]]);
})->with('chunk modes')->with(ReaderDrivers::ALL);

it('keys whole-sheet rows 0..n-1 and still calls collection() for an empty sheet', function (string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = ReaderDrivers::import($driver, rowTypeImport(false), $fixture->path());

    expect($import->calls[0]->keys()->all())->toBe([0, 1, 2]);

    $empty = LoggedFixture::xlsx([]);
    $import = ReaderDrivers::import($driver, rowTypeImport(false), $empty->path());

    expect($import->calls)->toHaveCount(1)
        ->and($import->calls[0])->toBeInstanceOf(Collection::class)
        ->and($import->calls[0]->all())->toBe([]);
})->with(ReaderDrivers::ALL);

// --- chunked keys with Collection rows ---

it('keys chunked Collection rows by row number minus chunk offset', function (string $driver, array $expectedRowNumbers, array $expectedKeys) {
    //                                row: 2    3     4      5    6     7    8
    $fixture = LoggedFixture::xlsx(['A', null, 'BAD', 'B', null, 'C', 'D']);
    $import = new class extends RowTypeCollectionImport implements SkipsEmptyRows, SkipsOnFailure, WithChunkReading, WithValidation
    {
        public array $failedRows = [];

        public function chunkSize(): int
        {
            return 2;
        }

        public function rules(): array
        {
            return ['name' => ['not_in:BAD']];
        }

        public function onFailure(Collection $failures): void
        {
            array_push($this->failedRows, ...$failures->map(fn ($f): int => $f->row())->all());
        }
    };

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->rowTypes())->toBe([Collection::class])
        ->and($import->ids())->toBe([2, 5, 7, 8])
        ->and($import->rowNumbers)->toBe($expectedRowNumbers)
        ->and(array_map(fn (Collection $call): array => $call->keys()->all(), $import->calls))->toBe($expectedKeys);
})->with([
    // Source row numbers: offset + key is the real row, and row 4's failure leaves a gap.
    'rust' => ['rust', [2, 5, 7, 8], [[0], [0, 2], [0]]],
    'phpspreadsheet' => ['phpspreadsheet', [2, 5, 7, 8], [[0], [0, 2], [0]]],
    // Arrival order: the two blank rows shift the numbers, but the keys still follow the same rule.
    'openspout' => ['openspout', [2, 4, 5, 6], [[0], [0, 1], [0]]],
]);

it('releases the row Collections of a chunk before reading the next', function (string $driver) {
    $import = new class implements ToCollection, WithChunkReading
    {
        /** @var list<WeakReference<Collection>> */
        public array $rows = [];

        /** Per collection() call: how many rows of earlier chunks were still alive. */
        public array $aliveBefore = [];

        public function collection(Collection $collection): void
        {
            $this->aliveBefore[] = count(array_filter($this->rows, fn (WeakReference $ref): bool => $ref->get() !== null));

            foreach ($collection as $row) {
                $this->rows[] = WeakReference::create($row);
            }
        }

        public function chunkSize(): int
        {
            return 3;
        }
    };

    // No WithHeadingRow, so the heading counts: 10 rows in chunks of 3, 3, 3, 1.
    $fixture = LoggedFixture::xlsx(array_fill(0, 9, 'A'));
    ReaderDrivers::import($driver, $import, $fixture->path());

    // A runner that kept its buffer would show 0, 3, 6, 9.
    expect($import->rows)->toHaveCount(10)
        ->and($import->aliveBefore)->toBe([0, 0, 0, 0]);
})->with(ReaderDrivers::ALL);

// --- collection_rows => 'array' ---

it('hands ToCollection plain array rows when the runner is told to', function (bool $chunked, string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = ReaderDrivers::import($driver, rowTypeImport($chunked), $fixture->path(), new ImportRunner(collectionRows: 'array'));

    expect($import->rowTypes())->toBe(['array'])
        ->and($import->rows)->toBe([
            ['id' => 2, 'name' => 'A'],
            ['id' => 3, 'name' => 'B'],
            ['id' => 4, 'name' => 'C'],
        ])
        ->and($import->calls)->toHaveCount($chunked ? 2 : 1)
        // The keys do not depend on the row type.
        ->and($import->rowNumbers)->toBe([2, 3, 4])
        ->and(array_map(fn (Collection $call): array => $call->keys()->all(), $import->calls))
        ->toBe($chunked ? [[0, 1], [0]] : [[0, 1, 2]]);
})->with('chunk modes')->with(ReaderDrivers::ALL);

it('reads collection_rows from the config for SheetStream::import()', function (?string $setting, string $expectedType, bool $chunked, string $driver) {
    ReaderDrivers::configure($driver);
    config()->set('sheet-stream.collection_rows', $setting);

    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = rowTypeImport($chunked);
    SheetStream::import($import, $fixture->path());

    expect($import->rowTypes())->toBe([$expectedType])
        ->and($import->ids())->toBe([2, 3, 4]);
})->with([
    'default' => ['collection', Collection::class],
    'opt-out' => ['array', 'array'],
    // A config file published before the key existed.
    'key missing' => [null, Collection::class],
])->with('chunk modes')->with(ReaderDrivers::ALL);

it('ships collection_rows as collection', function () {
    $shipped = require __DIR__.'/../../config/sheet-stream.php';

    expect($shipped['collection_rows'])->toBe('collection')
        ->and(config('sheet-stream.collection_rows'))->toBe('collection');
});

// --- ToArray is untouched ---

it('keeps ToArray rows plain arrays whatever collection_rows says', function (string $setting, string $driver) {
    ReaderDrivers::configure($driver);
    config()->set('sheet-stream.collection_rows', $setting);

    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);

    $whole = new LoggedArrayImport;
    $chunked = new ChunkedArrayImport;
    SheetStream::import($whole, $fixture->path());
    SheetStream::import($chunked, $fixture->path());

    expect($whole->rowsWerePlainArrays)->toBeTrue()
        ->and($whole->entries('array'))->toBe([[2, [0 => 2, 1 => 3, 2 => 4]]])
        ->and($chunked->rowsWerePlainArrays)->toBeTrue()
        ->and($chunked->entries('array'))->toBe([[2, [0 => 2, 1 => 3]], [4, [0 => 4]]]);
})->with(['collection', 'array'])->with(ReaderDrivers::ALL);

// --- an invalid value fails loudly ---

it('rejects an unknown collection_rows value', function () {
    $message = "Unsupported collection_rows value: 'collections'. Supported values: collection, array.";

    expect(fn () => new ImportRunner(collectionRows: 'collections'))->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => CollectionRows::wrap([['a']], 'collections'))->toThrow(InvalidArgumentException::class, $message);

    config()->set('sheet-stream.collection_rows', 'collections');
    $fixture = LoggedFixture::xlsx(['A']);

    // Before anything is read or queued, for every kind of import.
    $sync = rowTypeImport(false);
    $array = new LoggedArrayImport;
    $queued = new class extends RowTypeCollectionImport implements ShouldQueue {};
    $staged = new class extends RowTypeCollectionImport implements ShouldQueue, UsesStagingTable {};

    Bus::fake();

    foreach ([$sync, $array, $queued, $staged] as $import) {
        expect(fn () => SheetStream::import($import, $fixture->path()))->toThrow(InvalidArgumentException::class, $message);
    }

    expect($sync->calls)->toBe([])
        ->and($array->log)->toBe([]);
    Bus::assertNothingDispatched();
});

it('rejects an unknown collection_rows value when a job is built', function () {
    $import = new RowTypeCollectionImport;
    $message = "Unsupported collection_rows value: 'collections'.";

    expect(fn () => new QueuedImportJob($import, 'file.xlsx', collectionRows: 'collections'))->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => new QueuedSheetImportJob($import, $import, 0, 'file.xlsx', collectionRows: 'collections'))->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => new StagingProducerJob($import, 'file.xlsx', collectionRows: 'collections'))->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => new StagingChunkProcessorJob($import, $import, 'id', 0, 0, collectionRows: 'collections'))->toThrow(InvalidArgumentException::class, $message);
});

it('fails a staging chunk job whose payload carries an unknown collection_rows value', function () {
    $path = stagingPathForRowTypes();
    $import = new class extends RowTypeCollectionImport implements ShouldQueue, UsesStagingTable {};
    $fixture = LoggedFixture::xlsx(['A']);

    Bus::fake([StagingChunkProcessorJob::class]);
    new StagingProducerJob(import: $import, filePath: $fixture->path())->handle();

    // As if the payload had been written by something else: the constructor never saw it.
    $job = Bus::dispatched(StagingChunkProcessorJob::class)->first();
    $job->collectionRows = 'collections';

    expect(fn () => $job->handle())->toThrow(InvalidArgumentException::class, 'Unsupported collection_rows value')
        ->and($import->calls)->toBe([]);

    removeStagingPathForRowTypes($path);
});

// --- queued imports carry the setting ---

it('carries collection_rows into a QueuedImportJob', function (string $setting, string $expectedType, bool $chunked, string $driver) {
    ReaderDrivers::configure($driver);
    config()->set('sheet-stream.collection_rows', $setting);
    Bus::fake();

    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = $chunked
        ? new class extends RowTypeCollectionImport implements ShouldQueue, WithChunkReading
        {
            public function chunkSize(): int
            {
                return 2;
            }
        }
    : new class extends RowTypeCollectionImport implements ShouldQueue {};

    $pending = SheetStream::import($import, $fixture->path());
    unset($pending);

    $job = Bus::dispatched(QueuedImportJob::class)->first();
    expect($job->collectionRows)->toBe($setting);

    // The worker's config must not matter: the job carries the value it was queued with.
    config()->set('sheet-stream.collection_rows', $setting === 'array' ? 'collection' : 'array');
    $job->handle();

    expect($import->rowTypes())->toBe([$expectedType])
        ->and($import->ids())->toBe([2, 3, 4])
        ->and($import->calls)->toHaveCount($chunked ? 2 : 1);
})->with([
    'default' => ['collection', Collection::class],
    'opt-out' => ['array', 'array'],
])->with('chunk modes')->with(ReaderDrivers::ALL);

it('carries collection_rows into parallel QueuedSheetImportJobs', function (string $setting, string $expectedType, string $driver) {
    ReaderDrivers::configure($driver);
    config()->set('sheet-stream.collection_rows', $setting);
    Bus::fake();

    $fixture = LoggedFixture::multiSheetXlsx(['First' => ['A', 'B'], 'Second' => ['C']]);
    $import = new class implements ShouldQueue, WithMultipleSheets, WithParallelSheets
    {
        public array $sheets;

        public function __construct()
        {
            $this->sheets = [new RowTypeCollectionImport, new RowTypeCollectionImport];
        }

        public function sheets(): array
        {
            return $this->sheets;
        }
    };

    $pending = SheetStream::import($import, $fixture->path());
    unset($pending);

    Bus::dispatched(QueuedImportJob::class)->first()->handle();
    $sheetJobs = Bus::dispatched(QueuedSheetImportJob::class);

    expect($sheetJobs)->toHaveCount(2);

    foreach ($sheetJobs as $job) {
        expect($job->collectionRows)->toBe($setting);
        $job->handle();
    }

    expect($import->sheets[0]->rowTypes())->toBe([$expectedType])
        ->and($import->sheets[0]->ids())->toBe([2, 3])
        ->and($import->sheets[1]->rowTypes())->toBe([$expectedType])
        ->and($import->sheets[1]->ids())->toBe([2]);
})->with([
    'default' => ['collection', Collection::class],
    'opt-out' => ['array', 'array'],
])->with(ReaderDrivers::ALL);

// --- the staging pipeline ---

function stagingPathForRowTypes(): string
{
    $path = sys_get_temp_dir().'/sheet_stream_row_types_'.uniqid();
    mkdir($path, 0755, true);
    app()->singleton(StagingStore::class, fn () => new FileStagingStore($path));

    return $path;
}

function removeStagingPathForRowTypes(string $path): void
{
    array_map(unlink(...), glob($path.'/*/*') ?: []);
    array_map(rmdir(...), glob($path.'/*', GLOB_ONLYDIR) ?: []);
    rmdir($path);
}

/** Queue a staged import through the manager and run the producer and every chunk job inline. */
function runStagedRowTypeImport(object $import, string $path): array
{
    Bus::fake();

    $pending = SheetStream::import($import, $path);
    unset($pending);

    $producer = Bus::dispatched(StagingProducerJob::class)->first();
    $producer->handle();

    $chunkJobs = Bus::dispatched(StagingChunkProcessorJob::class)->all();

    foreach ($chunkJobs as $job) {
        $job->handle();
    }

    return [$producer, $chunkJobs];
}

it('hands the staging pipeline rows of the configured type', function (?string $setting, string $carried, string $expectedType, string $stagingDriver, string $driver) {
    ReaderDrivers::configure($driver);
    config()->set('sheet-stream.collection_rows', $setting);
    config()->set('sheet-stream.chunk_size', 2);

    if ($stagingDriver === 'database') {
        config()->set('sheet-stream.staging.driver', 'database');
        Schema::create('sheet_stream_staging', function ($table) {
            $table->id();
            $table->char('import_id', 36);
            $table->unsignedSmallInteger('sheet_index')->default(0);
            $table->string('sheet_name')->default('');
            $table->unsignedInteger('chunk_number');
            $table->unsignedInteger('row_number');
            $table->longText('row_data');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['import_id', 'sheet_index', 'chunk_number'], 'sss_import_sheet_chunk');
        });
    } else {
        $path = stagingPathForRowTypes();
    }

    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = new class extends RowTypeCollectionImport implements ShouldQueue, UsesStagingTable {};

    [$producer, $chunkJobs] = runStagedRowTypeImport($import, $fixture->path());

    expect($producer->collectionRows)->toBe($carried)
        ->and($chunkJobs)->toHaveCount(2)
        ->and(array_map(fn (StagingChunkProcessorJob $job): string => $job->collectionRows, $chunkJobs))->toBe([$carried, $carried])
        ->and($import->calls)->toHaveCount(2)
        ->and($import->rowTypes())->toBe([$expectedType])
        ->and($import->ids())->toBe([2, 3, 4]);

    $second = $import->rows[1];

    expect($expectedType === 'array' ? $second : $second->toArray())->toBe(['id' => 3, 'name' => 'B']);

    if ($stagingDriver === 'file') {
        removeStagingPathForRowTypes($path);
    }
})->with([
    'default' => ['collection', 'collection', Collection::class],
    'opt-out' => ['array', 'array', 'array'],
    'key missing' => [null, 'collection', Collection::class],
])->with(['file', 'database'])->with(ReaderDrivers::ALL);

it('keeps staged ToArray rows plain arrays', function () {
    $path = stagingPathForRowTypes();
    config()->set('sheet-stream.chunk_size', 2);

    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = new class extends LoggedArrayImport implements ShouldQueue, UsesStagingTable {};

    runStagedRowTypeImport($import, $fixture->path());

    expect($import->rowsWerePlainArrays)->toBeTrue()
        ->and($import->entries('array'))->toHaveCount(2);

    removeStagingPathForRowTypes($path);
});

// --- jobs serialized before the setting existed ---

it('defaults collectionRows on a job built without its constructor, as an old payload is', function (string $jobClass) {
    // unserialize() skips the constructor and leaves a property the payload lacks at its
    // declared default; so does newInstanceWithoutConstructor().
    $job = new ReflectionClass($jobClass)->newInstanceWithoutConstructor();

    expect($job->collectionRows)->toBe('collection');
})->with([
    QueuedImportJob::class,
    QueuedSheetImportJob::class,
    StagingProducerJob::class,
    StagingChunkProcessorJob::class,
]);

it('defaults collectionRows when a job is constructed without it, and keeps it through serialization', function () {
    $import = new RowTypeCollectionImport;

    expect(new QueuedImportJob($import, 'file.xlsx')->collectionRows)->toBe('collection')
        ->and(new QueuedSheetImportJob($import, $import, 0, 'file.xlsx')->collectionRows)->toBe('collection')
        ->and(new StagingProducerJob($import, 'file.xlsx')->collectionRows)->toBe('collection')
        ->and(new StagingChunkProcessorJob($import, $import, 'id', 0, 0)->collectionRows)->toBe('collection');

    $restored = unserialize(serialize(new QueuedImportJob($import, 'file.xlsx', collectionRows: 'array')));

    expect($restored->collectionRows)->toBe('array');
});

// --- CollectionRows ---

it('wraps rows keeping their keys', function () {
    $rows = [0 => ['a' => 1], 2 => ['a' => 3]];

    $collections = CollectionRows::wrap($rows);
    $arrays = CollectionRows::wrap($rows, 'array');

    expect($collections->keys()->all())->toBe([0, 2])
        ->and($collections[2])->toBeInstanceOf(Collection::class)
        ->and($collections[2]->all())->toBe(['a' => 3])
        ->and($arrays->all())->toBe($rows)
        ->and(CollectionRows::wrap([])->all())->toBe([])
        ->and(CollectionRows::assertValid('collection'))->toBe('collection')
        ->and(CollectionRows::assertValid('array'))->toBe('array');
});
