<?php

/**
 * Tests for the file-based staging driver.
 *
 * These mirror the core StagingPipelineTest assertions but run against
 * FileStagingStore instead of the database staging table. The staging
 * table is NOT created — proving the file driver is fully DB-free.
 */

use Illuminate\Support\Facades\Bus;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Tests\Fixtures\StagingArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

beforeEach(function () {
    $this->stagingPath = sys_get_temp_dir().'/sheet_stream_test_'.uniqid();
    mkdir($this->stagingPath, 0755, true);

    // Swap in the file staging store for all tests in this file.
    app()->singleton(StagingStore::class, fn () => new FileStagingStore($this->stagingPath));
});

afterEach(function () {
    // Clean up any leftover staging files.
    $files = glob($this->stagingPath.'/**/*.ndjson') ?: [];
    foreach ($files as $file) {
        @unlink($file);
    }
    // Remove subdirectories.
    $dirs = glob($this->stagingPath.'/*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
        @rmdir($dir);
    }
    @rmdir($this->stagingPath);
});

it('file producer writes all data rows to chunk files', function () {
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

    // Verify chunk files were created
    $files = glob($this->stagingPath.'/*/*.ndjson') ?: [];
    expect($files)->toHaveCount(1);

    // Verify the file contains 3 data rows
    $lines = array_filter(explode("\n", file_get_contents($files[0])), fn ($l) => $l !== '');
    expect($lines)->toHaveCount(3);

    $firstRow = json_decode($lines[0], true);
    expect($firstRow['row_data'])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com']);
});

it('file producer assigns correct chunk numbers by creating separate files', function () {
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

    // Should create 3 chunk files
    $files = glob($this->stagingPath.'/*/*.ndjson') ?: [];
    expect($files)->toHaveCount(3);

    // Verify file naming matches expected chunks
    $basenames = array_map('basename', $files);
    sort($basenames);
    expect($basenames)->toBe(['s0_c0.ndjson', 's0_c1.ndjson', 's0_c2.ndjson']);
});

it('file producer dispatches one chunk job per chunk', function () {
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

it('file chunk processor reads and delivers its assigned rows', function () {
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

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('file chunk processor cleans up chunk file after processing', function () {
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

    // Files exist before processing
    $files = glob($this->stagingPath.'/*/*.ndjson') ?: [];
    expect($files)->toHaveCount(1);

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    // Files cleaned up after processing
    $files = glob($this->stagingPath.'/*/*.ndjson') ?: [];
    expect($files)->toHaveCount(0);
});

it('file full staging pipeline delivers same result as database pipeline', function () {
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

it('file staging pipeline skips empty rows before writing', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Alice'],
        [null],  // empty — should not be staged
        [''],    // empty — should not be staged
        ['Bob'],
    ]);

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

    // Verify only 2 non-empty rows in the chunk file
    $files = glob($this->stagingPath.'/*/*.ndjson') ?: [];
    $lines = array_filter(explode("\n", file_get_contents($files[0])), fn ($l) => $l !== '');
    expect($lines)->toHaveCount(2);

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0][0])->toBe('Alice')
        ->and($import->result[1][0])->toBe('Bob');
});

it('file driver handles batch that spans chunk boundaries', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name'],
        ['A'], ['B'], ['C'], ['D'], ['E'], // 5 rows
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new StagingArrayImport;
    $producer = new StagingProducerJob(
        import: $import,
        filePath: $fixture->path(),
        chunkSize: 2,
        insertBatchSize: 3,  // batch of 3 spans chunk boundary (rows 1-2 = chunk 0, row 3 = chunk 1)
    );
    $producer->handle();

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    expect($import->result)->toHaveCount(5)
        ->and($import->result[0])->toMatchArray(['name' => 'A'])
        ->and($import->result[4])->toMatchArray(['name' => 'E']);
});
