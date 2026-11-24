<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Concerns\OnEachRow;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use MrDellimore\SheetStream\Staging\DatabaseStagingStore;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Tests\Fixtures\BlankCellsFixtures;
use MrDellimore\SheetStream\Tests\Fixtures\StagingArrayImport;

/*
 * The staging pipeline normalizes blank cells in the producer, as each row is staged:
 * the stored row is what the chunk job later hands to the import.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stagingPath = sys_get_temp_dir().'/sheet_stream_blank_'.uniqid();
    mkdir($this->stagingPath, 0755, true);

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
    });
});

afterEach(function () {
    BlankCellsFixtures::cleanup();

    foreach (glob($this->stagingPath.'/*/*') ?: [] as $file) {
        @unlink($file);
    }
    foreach (glob($this->stagingPath.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        @rmdir($dir);
    }
    @rmdir($this->stagingPath);
});

function useBlankCellsStagingStore(string $store, string $path): void
{
    app()->singleton(StagingStore::class, fn () => $store === 'file'
        ? new FileStagingStore($path)
        : new DatabaseStagingStore('sheet_stream_staging'));
}

/** @return array{0: string, 1: object|null} */
function blankCellsStagingFile(string $format): array
{
    if ($format === 'csv') {
        return [BlankCellsFixtures::csv(), null];
    }

    $fixture = BlankCellsFixtures::xlsx();

    return [$fixture->path(), $fixture];
}

/** Run the producer, then every chunk job it dispatched, on the same import instance. */
function runBlankCellsStaging(object $import, string $path, ?bool $blankCellsAsNull = null, int $chunkSize = 1): void
{
    Bus::fake([StagingChunkProcessorJob::class]);

    new StagingProducerJob(
        import: $import,
        filePath: $path,
        chunkSize: $chunkSize,
        insertBatchSize: 1,
        blankCellsAsNull: $blankCellsAsNull,
    )->handle();

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }
}

dataset('blank cell staging stores', ['file', 'database']);
dataset('blank cell drivers and formats', BlankCellsFixtures::driversAndFormats());
dataset('blank cell settings', ['null (default)' => [null], "'' (opt-out)" => ['']]);

it('stages and delivers blank cells as configured', function (string $driver, string $format, string $store, mixed $setting) {
    if (! BlankCellsFixtures::driverAvailable($driver)) {
        $this->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    config()->set('sheet-stream.default_reader', $driver);
    config()->set('sheet-stream.blank_cells', $setting);
    useBlankCellsStagingStore($store, $this->stagingPath);
    [$path, $keep] = blankCellsStagingFile($format);

    $import = new StagingArrayImport;
    runBlankCellsStaging($import, $path);

    expect($import->result)->toBe(BlankCellsFixtures::keyedRows($driver, $format, $setting === null));
})->with('blank cell drivers and formats')->with('blank cell staging stores')->with('blank cell settings');

it('delivers staged blank cells to every output mode', function (string $mode, bool $headingRow, string $store) {
    config()->set('sheet-stream.default_reader', 'openspout');
    useBlankCellsStagingStore($store, $this->stagingPath);
    $fixture = BlankCellsFixtures::xlsx();

    $import = match ([$mode, $headingRow]) {
        ['array', true] => new class implements ShouldQueue, ToArray, UsesStagingTable, WithHeadingRow
        {
            public array $rows = [];

            public function array(array $array): void
            {
                array_push($this->rows, ...$array);
            }
        },
        ['array', false] => new class implements ShouldQueue, ToArray, UsesStagingTable
        {
            public array $rows = [];

            public function array(array $array): void
            {
                array_push($this->rows, ...$array);
            }
        },
        ['collection', true] => new class implements ShouldQueue, ToCollection, UsesStagingTable, WithHeadingRow
        {
            public array $rows = [];

            public function collection(Collection $collection): void
            {
                foreach ($collection as $row) {
                    $this->rows[] = $row instanceof Collection ? $row->all() : $row;
                }
            }
        },
        ['collection', false] => new class implements ShouldQueue, ToCollection, UsesStagingTable
        {
            public array $rows = [];

            public function collection(Collection $collection): void
            {
                foreach ($collection as $row) {
                    $this->rows[] = $row instanceof Collection ? $row->all() : $row;
                }
            }
        },
        ['each-row', true] => new class implements OnEachRow, ShouldQueue, UsesStagingTable, WithHeadingRow
        {
            public array $rows = [];

            public function onRow(array $row): void
            {
                $this->rows[] = $row;
            }
        },
        ['each-row', false] => new class implements OnEachRow, ShouldQueue, UsesStagingTable
        {
            public array $rows = [];

            public function onRow(array $row): void
            {
                $this->rows[] = $row;
            }
        },
        ['model', true] => new class implements ShouldQueue, ToModel, UsesStagingTable, WithHeadingRow
        {
            public array $rows = [];

            public function model(array $row): ?Model
            {
                $this->rows[] = $row;

                return null;
            }
        },
        ['model', false] => new class implements ShouldQueue, ToModel, UsesStagingTable
        {
            public array $rows = [];

            public function model(array $row): ?Model
            {
                $this->rows[] = $row;

                return null;
            }
        },
    };

    runBlankCellsStaging($import, $fixture->path());

    expect($import->rows)->toBe($headingRow
        ? BlankCellsFixtures::keyedRows('openspout', 'xlsx', true)
        : [BlankCellsFixtures::XLSX_HEADINGS, ...BlankCellsFixtures::listRows('openspout', 'xlsx', true)]);
})->with(['array', 'collection', 'each-row', 'model'])->with([true, false])->with('blank cell staging stores');

it('stores the normalized row, so the staged data is what the import receives', function (mixed $setting) {
    Bus::fake([StagingChunkProcessorJob::class]);
    config()->set('sheet-stream.default_reader', 'openspout');
    config()->set('sheet-stream.blank_cells', $setting);
    useBlankCellsStagingStore('database', $this->stagingPath);

    $fixture = BlankCellsFixtures::xlsx();
    new StagingProducerJob(import: new StagingArrayImport, filePath: $fixture->path())->handle();

    $staged = DB::table('sheet_stream_staging')->orderBy('row_number')->pluck('row_data')
        ->map(fn (string $json) => json_decode($json, true))->all();

    expect($staged)->toBe(BlankCellsFixtures::keyedRows('openspout', 'xlsx', $setting === null));

    // The raw JSON distinguishes the two modes: null vs "".
    $first = DB::table('sheet_stream_staging')->orderBy('row_number')->value('row_data');
    expect($first)->toContain($setting === null ? '"gap":null' : '"gap":""');
})->with('blank cell settings');

it('captures the setting on the producer job at dispatch time', function (mixed $setting, bool $expected) {
    Bus::fake();
    config()->set('sheet-stream.blank_cells', $setting);

    $fixture = BlankCellsFixtures::xlsx();
    $pending = SheetStream::import(new StagingArrayImport, $fixture->path());
    unset($pending);

    Bus::assertDispatched(StagingProducerJob::class, fn (StagingProducerJob $job) => $job->blankCellsAsNull === $expected);
})->with([
    'null' => [null, true],
    "''" => ['', false],
]);

it('stages with the captured setting, not the config at run time', function (bool $captured) {
    config()->set('sheet-stream.default_reader', 'openspout');
    config()->set('sheet-stream.blank_cells', $captured ? '' : null);
    useBlankCellsStagingStore('file', $this->stagingPath);

    $fixture = BlankCellsFixtures::xlsx();
    $import = new StagingArrayImport;
    runBlankCellsStaging($import, $fixture->path(), blankCellsAsNull: $captured);

    expect($import->result)->toBe(BlankCellsFixtures::keyedRows('openspout', 'xlsx', $captured));
})->with([true, false]);

it('lets a producer payload queued before the option existed follow the config at run time', function (mixed $setting) {
    Bus::fake([StagingChunkProcessorJob::class]);
    config()->set('sheet-stream.default_reader', 'openspout');
    config()->set('sheet-stream.blank_cells', $setting);
    useBlankCellsStagingStore('file', $this->stagingPath);

    $fixture = BlankCellsFixtures::xlsx();
    $state = new StagingProducerJob(import: new StagingArrayImport, filePath: $fixture->path())->__serialize();
    $state = array_filter($state, fn (string $key) => ! str_contains($key, 'blankCellsAsNull'), ARRAY_FILTER_USE_KEY);

    $job = new ReflectionClass(StagingProducerJob::class)->newInstanceWithoutConstructor();
    $job->__unserialize($state);

    expect($job->blankCellsAsNull)->toBeNull();

    $job->handle();

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $chunkJob) {
        $chunkJob->handle();
    }

    expect($job->import->result)->toBe(BlankCellsFixtures::keyedRows('openspout', 'xlsx', $setting === null));
})->with('blank cell settings');

it('fails the producer loudly on an invalid blank_cells value', function () {
    config()->set('sheet-stream.blank_cells', 'null');
    useBlankCellsStagingStore('database', $this->stagingPath);

    $fixture = BlankCellsFixtures::xlsx();
    $job = new StagingProducerJob(import: new StagingArrayImport, filePath: $fixture->path());

    expect(fn () => $job->handle())->toThrow(InvalidArgumentException::class, 'Unsupported sheet-stream.blank_cells value')->and(DB::table('sheet_stream_staging')->count())->toBe(0);
});

it('validates staged blank cells as null in the chunk job', function (string $store) {
    config()->set('sheet-stream.default_reader', 'openspout');
    useBlankCellsStagingStore($store, $this->stagingPath);

    $path = BlankCellsFixtures::csv("name,qty,note\nAlice,,first\nBob,2,second\n");

    $import = new class implements ShouldQueue, SkipsOnFailure, ToArray, UsesStagingTable, WithHeadingRow, WithValidation
    {
        public array $rows = [];

        public array $failures = [];

        public function array(array $array): void
        {
            array_push($this->rows, ...$array);
        }

        public function rules(): array
        {
            return ['qty' => 'integer'];
        }

        public function onFailure(Collection $failures): void
        {
            array_push($this->failures, ...$failures->all());
        }
    };

    runBlankCellsStaging($import, $path, chunkSize: 10);

    expect($import->failures)->toHaveCount(1)
        ->and($import->failures[0]->row())->toBe(1)
        ->and(array_keys($import->failures[0]->errors()))->toBe(['qty'])
        ->and($import->failures[0]->values())->toBe(['name' => 'Alice', 'qty' => null, 'note' => 'first'])
        ->and($import->rows)->toBe([['name' => 'Bob', 'qty' => '2', 'note' => 'second']]);
})->with('blank cell staging stores');
