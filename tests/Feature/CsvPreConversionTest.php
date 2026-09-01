<?php

/**
 * Tests for the WithCsvPreConversion concern.
 *
 * These tests verify that the staging producer job can pre-convert
 * XLSX files to CSV before reading, using ssconvert (Gnumeric).
 */

use Illuminate\Support\Facades\Bus;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;
use MrDellimore\SheetStream\Concerns\WithCsvPreConversion;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Support\CsvConverter;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

beforeEach(function () {
    $this->stagingPath = sys_get_temp_dir().'/sheet_stream_csv_test_'.uniqid();
    mkdir($this->stagingPath, 0755, true);
    app()->singleton(StagingStore::class, fn () => new FileStagingStore($this->stagingPath));
});

afterEach(function () {
    $files = glob($this->stagingPath.'/**/*.ndjson') ?: [];
    foreach ($files as $file) {
        @unlink($file);
    }
    $dirs = glob($this->stagingPath.'/*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $dir) {
        @rmdir($dir);
    }
    @rmdir($this->stagingPath);
});

it('pre-converts xlsx to csv and produces identical staging output', function () {
    $converter = new CsvConverter;
    if (! $converter->isAvailable()) {
        $this->markTestSkipped('No CSV converter binary (ssconvert or xlsx2csv) found on this system.');
    }

    $fixture = (new XlsxFixtureBuilder)->write([
        ['name', 'email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
        ['Charlie', 'charlie@example.com'],
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new class implements ShouldQueue, ToArray, UsesStagingTable, WithCsvPreConversion, WithHeadingRow
    {
        public array $result = [];

        public function array(array $rows): void
        {
            array_push($this->result, ...$rows);
        }
    };

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

    expect($import->result)->toHaveCount(3)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com'])
        ->and($import->result[2])->toMatchArray(['name' => 'Charlie', 'email' => 'charlie@example.com']);
});

it('falls back to normal reader when converter is not available', function () {
    // Force converter to be unavailable by setting a nonexistent binary
    config()->set('sheet-stream.csv_converter.binary', '/nonexistent/binary');

    $fixture = (new XlsxFixtureBuilder)->write([
        ['name', 'email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new class implements ShouldQueue, ToArray, UsesStagingTable, WithCsvPreConversion, WithHeadingRow
    {
        public array $result = [];

        public function array(array $rows): void
        {
            array_push($this->result, ...$rows);
        }
    };

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

    // Still works via OpenSpout fallback
    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com']);
});

it('skips pre-conversion for csv files even with the concern', function () {
    // Create a CSV fixture directly
    $csvPath = sys_get_temp_dir().'/sheet_stream_csv_fixture_'.uniqid().'.csv';
    file_put_contents($csvPath, "name,email\nAlice,alice@example.com\nBob,bob@example.com\n");

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new class implements ShouldQueue, ToArray, UsesStagingTable, WithCsvPreConversion, WithHeadingRow
    {
        public array $result = [];

        public function array(array $rows): void
        {
            array_push($this->result, ...$rows);
        }
    };

    $producer = new StagingProducerJob(
        import: $import,
        filePath: $csvPath,
        chunkSize: 10,
        insertBatchSize: 100,
    );
    $producer->handle();

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com']);

    @unlink($csvPath);
});

it('handles chunking correctly with pre-conversion', function () {
    $converter = new CsvConverter;
    if (! $converter->isAvailable()) {
        $this->markTestSkipped('No CSV converter binary (ssconvert or xlsx2csv) found on this system.');
    }

    $fixture = (new XlsxFixtureBuilder)->write([
        ['name'],
        ['A'], ['B'], ['C'], ['D'], ['E'], // 5 rows
    ]);

    Bus::fake([StagingChunkProcessorJob::class]);

    $import = new class implements ShouldQueue, ToArray, UsesStagingTable, WithCsvPreConversion, WithHeadingRow
    {
        public array $result = [];

        public function array(array $rows): void
        {
            array_push($this->result, ...$rows);
        }
    };

    $producer = new StagingProducerJob(
        import: $import,
        filePath: $fixture->path(),
        chunkSize: 2,
        insertBatchSize: 100,
    );
    $producer->handle();

    // 5 rows with chunkSize=2 → 3 chunks → 3 jobs
    Bus::assertDispatched(StagingChunkProcessorJob::class, 3);

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    expect($import->result)->toHaveCount(5)
        ->and($import->result[0])->toMatchArray(['name' => 'A'])
        ->and($import->result[4])->toMatchArray(['name' => 'E']);
});

it('CsvConverter extracts sheet names from xlsx', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name'],
        ['Alice'],
    ]);

    $converter = new CsvConverter;
    if (! $converter->isAvailable()) {
        $this->markTestSkipped('No CSV converter binary (ssconvert or xlsx2csv) found on this system.');
    }

    $result = $converter->convert($fixture->path());

    expect($result->csvPaths)->not->toBeEmpty()
        ->and($result->sheetNames)->not->toBeEmpty()
        ->and($result->sheetNames[0])->toBeString();

    $result->cleanup();
});
