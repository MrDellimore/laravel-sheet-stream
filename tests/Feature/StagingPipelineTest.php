<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use MrDellimore\SheetStream\Tests\Fixtures\StagingArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
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
});

it('producer inserts all data rows into the staging table', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name', 'email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
        ['Charlie', 'charlie@example.com'],
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new StagingArrayImport;
    $producer = new StagingProducerJob(
        import: $import,
        filePath: $fixture->path(),
        chunkSize: 10,
        insertBatchSize: 100,
    );
    $producer->handle();

    expect(DB::table('sheet_stream_staging')->count())->toBe(3);

    $rows = DB::table('sheet_stream_staging')->orderBy('row_number')->get();
    expect(json_decode($rows[0]->row_data, true))->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com']);
    expect(json_decode($rows[1]->row_data, true))->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);
    expect(json_decode($rows[2]->row_data, true))->toMatchArray(['name' => 'Charlie', 'email' => 'charlie@example.com']);
});

it('producer assigns correct chunk numbers based on chunk size', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name'],
        ['A'], ['B'], ['C'], ['D'], ['E'], // 5 rows
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new StagingArrayImport;
    $producer = new StagingProducerJob(
        import: $import,
        filePath: $fixture->path(),
        chunkSize: 2,   // chunk 0 = rows 1-2, chunk 1 = rows 3-4, chunk 2 = row 5
        insertBatchSize: 100,
    );
    $producer->handle();

    $staged = DB::table('sheet_stream_staging')->orderBy('row_number')->get();

    expect($staged[0]->chunk_number)->toBe(0)
        ->and($staged[1]->chunk_number)->toBe(0)
        ->and($staged[2]->chunk_number)->toBe(1)
        ->and($staged[3]->chunk_number)->toBe(1)
        ->and($staged[4]->chunk_number)->toBe(2);
});

it('producer dispatches one chunk job per chunk', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name'],
        ['A'], ['B'], ['C'], ['D'], ['E'], // 5 rows, chunk_size=2 → 3 chunks
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new StagingArrayImport;
    $producer = new StagingProducerJob(
        import: $import,
        filePath: $fixture->path(),
        chunkSize: 2,
        insertBatchSize: 100,
    );
    $producer->handle();

    Bus::assertDispatched(StagingChunkProcessorJob::class, 3);
});

it('chunk processor reads and delivers its assigned rows', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name', 'email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new StagingArrayImport;
    $producer = new StagingProducerJob(
        import: $import,
        filePath: $fixture->path(),
        chunkSize: 10,
        insertBatchSize: 100,
    );
    $producer->handle();

    // Run chunk jobs synchronously on the same import instance
    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('chunk processor marks rows as processed', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name'],
        ['Alice'], ['Bob'],
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new StagingArrayImport;
    $producer = new StagingProducerJob(
        import: $import,
        filePath: $fixture->path(),
        chunkSize: 10,
        insertBatchSize: 100,
    );
    $producer->handle();

    expect(DB::table('sheet_stream_staging')->whereNull('processed_at')->count())->toBe(2);

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    expect(DB::table('sheet_stream_staging')->whereNotNull('processed_at')->count())->toBe(2)
        ->and(DB::table('sheet_stream_staging')->whereNull('processed_at')->count())->toBe(0);
});

it('full staging pipeline delivers same result as direct ImportRunner', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name', 'email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
        ['Charlie', 'charlie@example.com'],
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new StagingArrayImport;
    $producer = new StagingProducerJob(
        import: $import,
        filePath: $fixture->path(),
        chunkSize: 2,      // split across 2 chunks deliberately
        insertBatchSize: 1, // tiny insert batch to exercise flushing
    );
    $producer->handle();

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    expect($import->result)->toHaveCount(3)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com'])
        ->and($import->result[2])->toMatchArray(['name' => 'Charlie', 'email' => 'charlie@example.com']);
});

it('staging pipeline skips empty rows before inserting', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Alice'],
        [null],  // empty — should not be staged
        [''],    // empty — should not be staged
        ['Bob'],
    ]);

    // Use a no-heading import that also skips empty rows
    $import = new class implements
        \MrDellimore\SheetStream\Concerns\ShouldQueue,
        \MrDellimore\SheetStream\Concerns\ToArray,
        \MrDellimore\SheetStream\Concerns\UsesStagingTable,
        \MrDellimore\SheetStream\Concerns\SkipsEmptyRows
    {
        public array $result = [];

        public function array(array $rows): void
        {
            array_push($this->result, ...$rows);
        }
    };

    Bus::fake([StagingChunkProcessorJob::class]);

    $producer = new StagingProducerJob(
        import: $import,
        filePath: $fixture->path(),
        chunkSize: 10,
        insertBatchSize: 100,
    );
    $producer->handle();

    // Only 2 non-empty rows should be staged
    expect(DB::table('sheet_stream_staging')->count())->toBe(2);

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0][0])->toBe('Alice')
        ->and($import->result[1][0])->toBe('Bob');
});
