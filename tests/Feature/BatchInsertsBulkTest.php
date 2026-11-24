<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\ImportFailed;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Jobs\QueuedImportJob;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Support\RowHelper;
use MrDellimore\SheetStream\Tests\Fixtures\BulkPerson;
use MrDellimore\SheetStream\Tests\Fixtures\BulkPlain;
use MrDellimore\SheetStream\Tests\Fixtures\BulkSecondary;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

/*
 * Laravel Excel parity (ModelManager::massFlush): an import implementing WithBatchInserts
 * writes one query-builder INSERT per model class per batch and fires no model events.
 * Without the concern every model is save()d, as before.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

    Schema::create('bulk_people', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('nickname')->default('none');
        $table->text('meta')->nullable();
        $table->date('born_on')->nullable();
        $table->dateTime('seen_at')->nullable();
        $table->boolean('active')->nullable();
        $table->timestamps();
    });

    $plain = function ($table) {
        $table->id();
        $table->string('name')->unique();
        $table->string('email')->nullable();
    };

    Schema::create('bulk_plain', $plain);
    Schema::create('bulk_plain_archive', $plain);
    Schema::connection('secondary')->create('bulk_plain', $plain);
    Schema::connection('secondary')->create('bulk_secondary', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamp('created_at')->nullable();
    });

    BulkPerson::$events = [];

    // Every INSERT into a bulk_* table, on any connection: [connection, table, rows].
    $this->inserts = [];
    DB::listen(function (QueryExecuted $query) {
        if (preg_match('/^insert into "(bulk_\w+)"/', $query->sql, $match) === 1) {
            $this->inserts[] = [$query->connectionName, $match[1], substr_count($query->sql, '), (') + 1];
        }
    });

    $this->stagingPath = sys_get_temp_dir().'/sheet_stream_bulk_'.uniqid();
    mkdir($this->stagingPath, 0755, true);
    app()->singleton(StagingStore::class, fn () => new FileStagingStore($this->stagingPath));
});

afterEach(function () {
    Carbon::setTestNow();
    DB::purge('secondary');

    foreach (glob($this->stagingPath.'/*/*') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob($this->stagingPath.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        @rmdir($dir);
    }
    @rmdir($this->stagingPath);
});

/**
 * A ToModel import built from a row => model closure. With a batch size it implements
 * WithBatchInserts; with null it does not.
 */
function bulkImport(Closure $factory, ?int $batchSize, bool $staged = false): object
{
    return match ([$batchSize !== null, $staged]) {
        [true, false] => new readonly class($factory, (int) $batchSize) implements ToModel, WithBatchInserts, WithHeadingRow
        {
            public function __construct(private Closure $factory, private int $batchSize) {}

            public function model(array $row): ?Model
            {
                return ($this->factory)($row);
            }

            public function batchSize(): int
            {
                return $this->batchSize;
            }
        },
        [false, false] => new readonly class($factory) implements ToModel, WithHeadingRow
        {
            public function __construct(private Closure $factory) {}

            public function model(array $row): ?Model
            {
                return ($this->factory)($row);
            }
        },
        [true, true] => new readonly class($factory, (int) $batchSize) implements ShouldQueue, ToModel, UsesStagingTable, WithBatchInserts, WithHeadingRow
        {
            public function __construct(private Closure $factory, private int $batchSize) {}

            public function model(array $row): ?Model
            {
                return ($this->factory)($row);
            }

            public function batchSize(): int
            {
                return $this->batchSize;
            }
        },
        [false, true] => new readonly class($factory) implements ShouldQueue, ToModel, UsesStagingTable, WithHeadingRow
        {
            public function __construct(private Closure $factory) {}

            public function model(array $row): ?Model
            {
                return ($this->factory)($row);
            }
        },
    };
}

/** An .xlsx with a "name,email" heading row and one row per name. */
function bulkNamesFixture(array $names): XlsxFixtureBuilder
{
    return (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ...array_map(fn (string $name): array => [$name, strtoupper($name).'@Example.com'], $names),
    ]);
}

/** Run the staging producer and then each chunk job it dispatched, in order. */
function runBulkStaging(object $import, string $path, int $chunkSize): void
{
    Bus::fake([StagingChunkProcessorJob::class]);

    new StagingProducerJob(import: $import, filePath: $path, chunkSize: $chunkSize)->handle();

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }
}

dataset('reader drivers', ['openspout', 'phpspreadsheet', 'rust']);

// ── Statement count ──────────────────────────────────────────────────────────

it('writes one INSERT per batch under WithBatchInserts', function (string $driver) {
    if ($driver === 'rust' && ! RustReader::isAvailable()) {
        $this->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    config()->set('sheet-stream.default_reader', $driver);
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie', 'Dana', 'Eve']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPlain(['name' => $row['name']]), 2), $fixture->path());

    // 5 models, batch size 2: three statements of 2, 2 and 1 rows, not five.
    expect($this->inserts)->toBe([
        ['testing', 'bulk_plain', 2],
        ['testing', 'bulk_plain', 2],
        ['testing', 'bulk_plain', 1],
    ])
        ->and(DB::table('bulk_plain')->orderBy('id')->pluck('name', 'id')->all())
        ->toBe([1 => 'Alice', 2 => 'Bob', 3 => 'Charlie', 4 => 'Dana', 5 => 'Eve']);
})->with('reader drivers');

it('writes no trailing statement when the row count is an exact multiple of the batch size', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie', 'Dana']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPlain(['name' => $row['name']]), 2), $fixture->path());

    expect($this->inserts)->toBe([
        ['testing', 'bulk_plain', 2],
        ['testing', 'bulk_plain', 2],
    ]);
});

it('writes one statement for a whole sheet smaller than the batch', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPlain(['name' => $row['name']]), 500), $fixture->path());

    expect($this->inserts)->toBe([['testing', 'bulk_plain', 3]]);
});

it('runs no statement for a sheet without data rows', function (array $rows) {
    $fixture = (new XlsxFixtureBuilder)->write($rows);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPlain(['name' => $row['name']]), 2), $fixture->path());

    expect($this->inserts)->toBe([])
        ->and(DB::table('bulk_plain')->count())->toBe(0);
})->with([
    'heading row only' => [[['Name', 'Email']]],
    'empty sheet' => [[]],
]);

it('does not count rows whose model() returned null towards the batch', function () {
    $fixture = bulkNamesFixture(['Alice', 'skip', 'Bob', 'skip', 'Charlie']);

    SheetStream::import(
        bulkImport(fn (array $row) => $row['name'] === 'skip' ? null : new BulkPlain(['name' => $row['name']]), 2),
        $fixture->path(),
    );

    expect($this->inserts)->toBe([
        ['testing', 'bulk_plain', 2],
        ['testing', 'bulk_plain', 1],
    ])
        ->and(DB::table('bulk_plain')->orderBy('id')->pluck('name')->all())->toBe(['Alice', 'Bob', 'Charlie']);
});

it('runs no statement when every model() call returned null', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob']);

    SheetStream::import(bulkImport(fn (array $row) => null, 2), $fixture->path());

    expect($this->inserts)->toBe([]);
});

it('flushes nothing for an empty batch', function () {
    $import = bulkImport(fn (array $row) => null, 2);

    RowHelper::flushModels([], $import);
    RowHelper::flushModels([]);

    expect($this->inserts)->toBe([]);
});

// ── Model events: the regression pin ─────────────────────────────────────────

it('still saves each model and fires its events without WithBatchInserts', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPerson(['name' => $row['name']]), null), $fixture->path());

    expect($this->inserts)->toBe([
        ['testing', 'bulk_people', 1],
        ['testing', 'bulk_people', 1],
        ['testing', 'bulk_people', 1],
    ])
        ->and(BulkPerson::$events)->toBe([
            'saving:Alice', 'creating:Alice', 'created:Alice', 'saved:Alice',
            'saving:Bob', 'creating:Bob', 'created:Bob', 'saved:Bob',
            'saving:Charlie', 'creating:Charlie', 'created:Charlie', 'saved:Charlie',
        ])
        ->and(BulkPerson::count())->toBe(3);
});

it('fires no model events under WithBatchInserts', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPerson(['name' => $row['name']]), 2), $fixture->path());

    expect(BulkPerson::$events)->toBe([])
        ->and($this->inserts)->toBe([
            ['testing', 'bulk_people', 2],
            ['testing', 'bulk_people', 1],
        ])
        ->and(BulkPerson::orderBy('id')->pluck('name')->all())->toBe(['Alice', 'Bob', 'Charlie']);
});

it('saves per model when batchSize() is 1, as Laravel Excel does', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPerson(['name' => $row['name']]), 1), $fixture->path());

    expect(BulkPerson::$events)->toBe([
        'saving:Alice', 'creating:Alice', 'created:Alice', 'saved:Alice',
        'saving:Bob', 'creating:Bob', 'created:Bob', 'saved:Bob',
    ])
        ->and($this->inserts)->toBe([
            ['testing', 'bulk_people', 1],
            ['testing', 'bulk_people', 1],
        ]);
});

it('saves per model when flushModels is called without an import', function () {
    RowHelper::flushModels([new BulkPerson(['name' => 'Alice']), new BulkPerson(['name' => 'Bob'])]);

    expect(BulkPerson::$events)->toHaveCount(8)
        ->and($this->inserts)->toHaveCount(2);
});

// ── Timestamps ───────────────────────────────────────────────────────────────

it('fills created_at and updated_at like save() does', function () {
    Carbon::setTestNow('2026-03-04 05:06:07');
    $fixture = bulkNamesFixture(['Alice', 'Bob']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPerson(['name' => $row['name']]), 10), $fixture->path());

    expect(DB::table('bulk_people')->orderBy('id')->get(['name', 'created_at', 'updated_at'])->map(fn ($row) => (array) $row)->all())->toBe([
        ['name' => 'Alice', 'created_at' => '2026-03-04 05:06:07', 'updated_at' => '2026-03-04 05:06:07'],
        ['name' => 'Bob', 'created_at' => '2026-03-04 05:06:07', 'updated_at' => '2026-03-04 05:06:07'],
    ]);
});

it('keeps a timestamp the model provided and still writes one statement', function () {
    Carbon::setTestNow('2026-03-04 05:06:07');
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPerson(match ($row['name']) {
        'Alice' => ['name' => 'Alice', 'created_at' => '2001-02-03 04:05:06'],
        'Bob' => ['name' => 'Bob', 'updated_at' => Carbon::parse('2002-03-04 05:06:07')],
        default => ['name' => $row['name']],
    }), 10), $fixture->path());

    // Same columns in a different order per model is still one attribute set.
    expect($this->inserts)->toBe([['testing', 'bulk_people', 3]])
        ->and(DB::table('bulk_people')->orderBy('id')->get(['name', 'created_at', 'updated_at'])->map(fn ($row) => (array) $row)->all())->toBe([
            ['name' => 'Alice', 'created_at' => '2001-02-03 04:05:06', 'updated_at' => '2026-03-04 05:06:07'],
            ['name' => 'Bob', 'created_at' => '2026-03-04 05:06:07', 'updated_at' => '2002-03-04 05:06:07'],
            ['name' => 'Charlie', 'created_at' => '2026-03-04 05:06:07', 'updated_at' => '2026-03-04 05:06:07'],
        ]);
});

it('adds no timestamp columns for a model with timestamps disabled', function () {
    $fixture = bulkNamesFixture(['Alice']);
    $sql = [];
    DB::listen(function (QueryExecuted $query) use (&$sql) {
        if (str_starts_with($query->sql, 'insert into "bulk_plain"')) {
            $sql[] = $query->sql;
        }
    });

    // bulk_plain has no created_at / updated_at columns: adding them would fail the INSERT.
    SheetStream::import(bulkImport(fn (array $row) => new BulkPlain(['name' => $row['name']]), 10), $fixture->path());

    expect($sql)->toBe(['insert into "bulk_plain" ("name") values (?)'])
        ->and(DB::table('bulk_plain')->pluck('name')->all())->toBe(['Alice']);
});

it('honours a model without an updated_at column', function () {
    Carbon::setTestNow('2026-03-04 05:06:07');
    $fixture = bulkNamesFixture(['Alice', 'Bob']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkSecondary(['name' => $row['name']]), 10), $fixture->path());

    expect(DB::connection('secondary')->table('bulk_secondary')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe([
        ['id' => 1, 'name' => 'Alice', 'created_at' => '2026-03-04 05:06:07'],
        ['id' => 2, 'name' => 'Bob', 'created_at' => '2026-03-04 05:06:07'],
    ]);
});

// ── Casts and mutators ───────────────────────────────────────────────────────

it('stores exactly what save() stores for casts, dates and mutators', function () {
    Carbon::setTestNow('2026-03-04 05:06:07');
    $fixture = bulkNamesFixture(['Alice', 'Bob']);

    $build = fn (string $name, string $email): BulkPerson => new BulkPerson([
        'name' => $name,
        'email' => $email,                                    // mutator lowercases
        'meta' => ['tags' => ['a', 'b'], 'score' => 1.5],    // array cast → JSON
        'born_on' => Carbon::parse('1990-05-06 13:14:15'),   // date cast: stored as given, like save()
        'seen_at' => new DateTimeImmutable('2024-12-31 23:59:58'),
        'active' => false,
    ]);

    SheetStream::import(bulkImport(fn (array $row) => $build($row['name'], $row['email']), 10), $fixture->path());

    // The reference rows: the same models written by Eloquent itself.
    $build('Alice', 'ALICE@Example.com')->save();
    $build('Bob', 'BOB@Example.com')->save();

    $rows = DB::table('bulk_people')->orderBy('id')->get()->map(fn ($row) => array_diff_key((array) $row, ['id' => 0]))->all();

    expect($this->inserts[0])->toBe(['testing', 'bulk_people', 2])
        ->and($rows)->toHaveCount(4)
        ->and($rows[0])->toBe($rows[2])
        ->and($rows[1])->toBe($rows[3])
        ->and($rows[0])->toBe([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'nickname' => 'none',
            'meta' => '{"tags":["a","b"],"score":1.5}',
            'born_on' => '1990-05-06 13:14:15',
            'seen_at' => '2024-12-31 23:59:58',
            'active' => 0,
            'created_at' => '2026-03-04 05:06:07',
            'updated_at' => '2026-03-04 05:06:07',
        ]);

    $alice = BulkPerson::find(1);

    expect($alice->meta)->toBe(['tags' => ['a', 'b'], 'score' => 1.5])
        ->and($alice->born_on->toDateString())->toBe('1990-05-06')
        ->and($alice->seen_at->toDateTimeString())->toBe('2024-12-31 23:59:58')
        ->and($alice->active)->toBeFalse();
});

// ── Connections and tables ───────────────────────────────────────────────────

it('inserts into the connection the model class declares', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkSecondary(['name' => $row['name']]), 2), $fixture->path());

    expect($this->inserts)->toBe([
        ['secondary', 'bulk_secondary', 2],
        ['secondary', 'bulk_secondary', 1],
    ])
        ->and(DB::connection('secondary')->table('bulk_secondary')->orderBy('id')->pluck('name')->all())->toBe(['Alice', 'Bob', 'Charlie'])
        ->and(Schema::hasTable('bulk_secondary'))->toBeFalse();
});

it('inserts each model where save() would put it when instances override connection or table', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie', 'Dana']);

    SheetStream::import(bulkImport(fn (array $row) => match ($row['name']) {
        'Bob' => new BulkPlain(['name' => 'Bob'])->setConnection('secondary'),
        'Charlie' => new BulkPlain(['name' => 'Charlie'])->setTable('bulk_plain_archive'),
        default => new BulkPlain(['name' => $row['name']]),
    }, 10), $fixture->path());

    expect($this->inserts)->toBe([
        ['testing', 'bulk_plain', 2],
        ['secondary', 'bulk_plain', 1],
        ['testing', 'bulk_plain_archive', 1],
    ])
        ->and(DB::table('bulk_plain')->orderBy('id')->pluck('name')->all())->toBe(['Alice', 'Dana'])
        ->and(DB::connection('secondary')->table('bulk_plain')->pluck('name')->all())->toBe(['Bob'])
        ->and(DB::table('bulk_plain_archive')->pluck('name')->all())->toBe(['Charlie']);
});

// ── Mixed batches ────────────────────────────────────────────────────────────

it('writes one INSERT per model class per batch when classes are mixed', function () {
    Carbon::setTestNow('2026-03-04 05:06:07');
    $fixture = bulkNamesFixture(['p1', 'x1', 'p2', 's1', 'x2', 'p3', 'p4', 'x3']);

    SheetStream::import(bulkImport(fn (array $row) => match ($row['name'][0]) {
        'p' => new BulkPerson(['name' => $row['name']]),
        'x' => new BulkPlain(['name' => $row['name']]),
        default => new BulkSecondary(['name' => $row['name']]),
    }, 6), $fixture->path());

    // Batch 1 holds p1 x1 p2 s1 x2 p3 (three classes); batch 2 holds p4 x3.
    expect($this->inserts)->toBe([
        ['testing', 'bulk_people', 3],
        ['testing', 'bulk_plain', 2],
        ['secondary', 'bulk_secondary', 1],
        ['testing', 'bulk_people', 1],
        ['testing', 'bulk_plain', 1],
    ])
        ->and(BulkPerson::orderBy('id')->pluck('name')->all())->toBe(['p1', 'p2', 'p3', 'p4'])
        ->and(BulkPlain::orderBy('id')->pluck('name')->all())->toBe(['x1', 'x2', 'x3'])
        ->and(BulkSecondary::orderBy('id')->pluck('name')->all())->toBe(['s1'])
        ->and(BulkPerson::$events)->toBe([]);
});

it('splits models of one class with different attribute sets into one INSERT per set', function () {
    Carbon::setTestNow('2026-03-04 05:06:07');
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie', 'Dana', 'Eve']);

    // Alice/Dana: name + email. Bob/Eve: name + nickname (same column count, different
    // columns — a single multi-row INSERT would file Bob's nickname under "email").
    // Charlie: name only, so the column default must apply.
    SheetStream::import(bulkImport(fn (array $row) => new BulkPerson(match ($row['name']) {
        'Alice', 'Dana' => ['name' => $row['name'], 'email' => $row['email']],
        'Bob', 'Eve' => ['name' => $row['name'], 'nickname' => 'nick-'.$row['name']],
        default => ['name' => $row['name']],
    }), 10), $fixture->path());

    expect($this->inserts)->toBe([
        ['testing', 'bulk_people', 2],
        ['testing', 'bulk_people', 2],
        ['testing', 'bulk_people', 1],
    ])
        ->and(DB::table('bulk_people')->orderBy('name')->get(['name', 'email', 'nickname'])->map(fn ($row) => (array) $row)->all())->toBe([
            ['name' => 'Alice', 'email' => 'alice@example.com', 'nickname' => 'none'],
            ['name' => 'Bob', 'email' => null, 'nickname' => 'nick-Bob'],
            ['name' => 'Charlie', 'email' => null, 'nickname' => 'none'],
            ['name' => 'Dana', 'email' => 'dana@example.com', 'nickname' => 'none'],
            ['name' => 'Eve', 'email' => null, 'nickname' => 'nick-Eve'],
        ]);
});

it('treats the same attributes set in a different order as one attribute set', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie']);

    SheetStream::import(bulkImport(fn (array $row) => new BulkPlain(
        $row['name'] === 'Bob'
            ? ['email' => $row['email'], 'name' => $row['name']]
            : ['name' => $row['name'], 'email' => $row['email']]
    ), 10), $fixture->path());

    expect($this->inserts)->toBe([['testing', 'bulk_plain', 3]])
        ->and(DB::table('bulk_plain')->orderBy('id')->pluck('email', 'name')->all())->toBe([
            'Alice' => 'ALICE@Example.com',
            'Bob' => 'BOB@Example.com',
            'Charlie' => 'CHARLIE@Example.com',
        ]);
});

it('updates a model that already exists instead of inserting it again', function () {
    $existing = BulkPerson::create(['name' => 'Bob', 'email' => 'old@example.com']);
    BulkPerson::$events = [];
    $this->inserts = [];

    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie']);

    SheetStream::import(bulkImport(fn (array $row) => $row['name'] === 'Bob'
        ? BulkPerson::find($existing->id)->fill(['email' => 'new@example.com'])
        : new BulkPerson(['name' => $row['name']]), 10), $fixture->path());

    expect($this->inserts)->toBe([['testing', 'bulk_people', 2]])
        ->and(BulkPerson::$events)->toBe(['saving:Bob', 'updating:Bob', 'updated:Bob', 'saved:Bob'])
        ->and(BulkPerson::orderBy('id')->pluck('email', 'name')->all())->toBe([
            'Bob' => 'new@example.com',
            'Alice' => null,
            'Charlie' => null,
        ]);
});

it('lets Eloquent write a model that has no attributes at all', function () {
    Schema::create('bulk_defaults', function ($table) {
        $table->id();
        $table->string('name')->default('unnamed');
    });

    $model = new class extends Model
    {
        public $timestamps = false;

        protected $table = 'bulk_defaults';
    };

    $fixture = bulkNamesFixture(['Alice', 'Bob']);
    SheetStream::import(bulkImport(fn (array $row) => $model->newInstance(), 10), $fixture->path());

    expect(DB::table('bulk_defaults')->pluck('name')->all())->toBe(['unnamed', 'unnamed']);
});

// ── Failures ─────────────────────────────────────────────────────────────────

it('surfaces a failing INSERT as an exception and writes none of that batch', function () {
    // The second "Bob" violates the unique index.
    $fixture = bulkNamesFixture(['Alice', 'Zed', 'Bob', 'Carl', 'Bob', 'Dana', 'Eve']);

    $import = new class implements ToModel, WithBatchInserts, WithEvents, WithHeadingRow
    {
        public array $events = [];

        public function model(array $row): Model
        {
            return new BulkPlain(['name' => $row['name']]);
        }

        public function batchSize(): int
        {
            return 2;
        }

        public function registerEvents(): array
        {
            return [
                AfterChunk::class => function (AfterChunk $event): void {
                    $this->events[] = 'chunk';
                },
                ImportFailed::class => function (ImportFailed $event): void {
                    $this->events[] = 'failed:'.($event->exception instanceof QueryException ? 'query' : 'other');
                },
            ];
        }
    };

    // Batches of 2: [Alice, Zed] and [Bob, Carl] are written, [Bob, Dana] fails as a
    // whole (Dana is not written either) and [Eve] is never reached.
    expect(fn () => SheetStream::import($import, $fixture->path()))->toThrow(QueryException::class);

    expect(DB::table('bulk_plain')->orderBy('id')->pluck('name')->all())->toBe(['Alice', 'Zed', 'Bob', 'Carl'])
        ->and($import->events)->toBe(['chunk', 'chunk', 'failed:query']);
});

it('surfaces a failing INSERT from the final partial batch', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Alice']);

    expect(fn () => SheetStream::import(bulkImport(fn (array $row) => new BulkPlain(['name' => $row['name']]), 10), $fixture->path()))
        ->toThrow(QueryException::class);

    expect(DB::table('bulk_plain')->count())->toBe(0);
});

// ── Queued and staged imports ────────────────────────────────────────────────

it('bulk inserts from a queued import job', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie']);
    $import = bulkImport(fn (array $row) => new BulkPerson(['name' => $row['name']]), 2);

    new QueuedImportJob(import: $import, filePath: $fixture->path())->handle();

    expect($this->inserts)->toBe([
        ['testing', 'bulk_people', 2],
        ['testing', 'bulk_people', 1],
    ])
        ->and(BulkPerson::$events)->toBe([]);
});

it('bulk inserts per batch inside each staging chunk job', function () {
    Carbon::setTestNow('2026-03-04 05:06:07');
    $fixture = bulkNamesFixture(['p1', 'p2', 'x1', 'p3', 'p4', 'p5', 'p6']);

    $import = bulkImport(fn (array $row) => $row['name'][0] === 'p'
        ? new BulkPerson(['name' => $row['name'], 'meta' => ['n' => $row['name']]])
        : new BulkPlain(['name' => $row['name']]), 3, staged: true);

    // Chunks of 5 rows: [p1 p2 x1 p3 p4] and [p5 p6]; batches of 3 models inside each.
    runBulkStaging($import, $fixture->path(), chunkSize: 5);

    expect($this->inserts)->toBe([
        ['testing', 'bulk_people', 2],   // chunk 0, batch [p1 p2 x1]
        ['testing', 'bulk_plain', 1],
        ['testing', 'bulk_people', 2],   // chunk 0, leftover [p3 p4]
        ['testing', 'bulk_people', 2],   // chunk 1, [p5 p6]
    ])
        ->and(BulkPerson::$events)->toBe([])
        ->and(DB::table('bulk_people')->orderBy('id')->get(['name', 'meta', 'created_at'])->map(fn ($row) => (array) $row)->first())
        ->toBe(['name' => 'p1', 'meta' => '{"n":"p1"}', 'created_at' => '2026-03-04 05:06:07'])
        ->and(BulkPerson::orderBy('id')->pluck('name')->all())->toBe(['p1', 'p2', 'p3', 'p4', 'p5', 'p6'])
        ->and(BulkPlain::pluck('name')->all())->toBe(['x1']);
});

it('still saves each model with events in a staging chunk job without WithBatchInserts', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Charlie']);
    $import = bulkImport(fn (array $row) => new BulkPerson(['name' => $row['name']]), null, staged: true);

    runBulkStaging($import, $fixture->path(), chunkSize: 2);

    expect($this->inserts)->toBe([
        ['testing', 'bulk_people', 1],
        ['testing', 'bulk_people', 1],
        ['testing', 'bulk_people', 1],
    ])
        ->and(BulkPerson::$events)->toBe([
            'saving:Alice', 'creating:Alice', 'created:Alice', 'saved:Alice',
            'saving:Bob', 'creating:Bob', 'created:Bob', 'saved:Bob',
            'saving:Charlie', 'creating:Charlie', 'created:Charlie', 'saved:Charlie',
        ]);
});

it('fails the staging chunk job loudly when its INSERT fails', function () {
    $fixture = bulkNamesFixture(['Alice', 'Bob', 'Alice']);
    $import = bulkImport(fn (array $row) => new BulkPlain(['name' => $row['name']]), 10, staged: true);

    Bus::fake([StagingChunkProcessorJob::class]);
    new StagingProducerJob(import: $import, filePath: $fixture->path(), chunkSize: 10)->handle();
    $jobs = Bus::dispatched(StagingChunkProcessorJob::class);

    expect($jobs)->toHaveCount(1)
        ->and(fn () => $jobs[0]->handle())->toThrow(QueryException::class)
        ->and(DB::table('bulk_plain')->count())->toBe(0);
});
