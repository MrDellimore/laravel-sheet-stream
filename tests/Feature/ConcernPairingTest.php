<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use MrDellimore\SheetStream\Concerns\OnEachRow;
use MrDellimore\SheetStream\Concerns\RemembersChunkOffset;
use MrDellimore\SheetStream\Concerns\RemembersRowNumber;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithChunkOffset;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithRowNumber;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Tests\Fixtures\ReaderDrivers;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

/*
 * The Remembers* traits work without their WithRowNumber / WithChunkOffset interface
 * (Laravel Excel detects the setters by name), and SkipsOnFailure may be declared
 * without WithValidation.
 */

function pairingFixture(): XlsxFixtureBuilder
{
    return (new XlsxFixtureBuilder)->write([
        ['Name'],  // row 1
        ['A'],     // row 2
        ['B'],     // row 3
        ['C'],     // row 4
        ['D'],     // row 5
        ['E'],     // row 6
    ]);
}

/** OnEachRow imports recording [name, row number, chunk offset] per row; a chunk is 2 rows. */
function pairingImport(string $variant): object
{
    return match ($variant) {
        'traits alone' => new class implements OnEachRow, WithBatchInserts, WithHeadingRow
        {
            use RemembersChunkOffset, RemembersRowNumber;

            public array $seen = [];

            public function onRow(array $row): void
            {
                $this->seen[] = [$row['name'], $this->getRowNumber(), $this->getChunkOffset()];
            }

            public function batchSize(): int
            {
                return 2;
            }
        },
        'traits with their interfaces' => new class implements OnEachRow, WithBatchInserts, WithChunkOffset, WithHeadingRow, WithRowNumber
        {
            use RemembersChunkOffset, RemembersRowNumber;

            public array $seen = [];

            public function onRow(array $row): void
            {
                $this->seen[] = [$row['name'], $this->getRowNumber(), $this->getChunkOffset()];
            }

            public function batchSize(): int
            {
                return 2;
            }
        },
    };
}

/** The same two variants for the staging pipeline. */
function pairingStagingImport(string $variant): object
{
    return match ($variant) {
        'traits alone' => new class implements OnEachRow, ShouldQueue, UsesStagingTable, WithHeadingRow
        {
            use RemembersChunkOffset, RemembersRowNumber;

            public array $seen = [];

            public function onRow(array $row): void
            {
                $this->seen[] = [$row['name'], $this->getRowNumber(), $this->getChunkOffset()];
            }
        },
        'traits with their interfaces' => new class implements OnEachRow, ShouldQueue, UsesStagingTable, WithChunkOffset, WithHeadingRow, WithRowNumber
        {
            use RemembersChunkOffset, RemembersRowNumber;

            public array $seen = [];

            public function onRow(array $row): void
            {
                $this->seen[] = [$row['name'], $this->getRowNumber(), $this->getChunkOffset()];
            }
        },
    };
}

/** Stage a file with the file store and run every chunk job inline. */
function runPairingStagingPipeline(object $import, string $path): void
{
    $stagingPath = sys_get_temp_dir().'/sheet_stream_pairing_'.uniqid();
    mkdir($stagingPath, 0755, true);
    app()->singleton(StagingStore::class, fn () => new FileStagingStore($stagingPath));

    Bus::fake([StagingChunkProcessorJob::class]);
    new StagingProducerJob(import: $import, filePath: $path, chunkSize: 2, insertBatchSize: 100)->handle();

    foreach (Bus::dispatched(StagingChunkProcessorJob::class) as $job) {
        $job->handle();
    }

    array_map(rmdir(...), glob($stagingPath.'/*', GLOB_ONLYDIR) ?: []);
    rmdir($stagingPath);
}

dataset('pairing variants', ['traits alone', 'traits with their interfaces']);

it('sets the row number and chunk offset through the runner', function (string $variant, string $driver) {
    $fixture = pairingFixture();
    $import = ReaderDrivers::import($driver, pairingImport($variant), $fixture->path());

    expect($import->seen)->toBe([
        ['A', 2, 2],
        ['B', 3, 2],
        ['C', 4, 4],
        ['D', 5, 4],
        ['E', 6, 6],
    ]);
})->with('pairing variants')->with(ReaderDrivers::ALL);

it('sets the row number and chunk offset in the staging chunk processor', function (string $variant) {
    $fixture = pairingFixture();
    $import = pairingStagingImport($variant);

    runPairingStagingPipeline($import, $fixture->path());

    // The staging pipeline numbers data rows from 1 (the heading row is not counted).
    expect($import->seen)->toBe([
        ['A', 1, 1],
        ['B', 2, 1],
        ['C', 3, 3],
        ['D', 4, 3],
        ['E', 5, 5],
    ]);
})->with('pairing variants');

it('leaves an import without the setters alone', function (string $driver) {
    $import = new class implements OnEachRow, WithHeadingRow
    {
        public array $seen = [];

        public function onRow(array $row): void
        {
            $this->seen[] = $row['name'];
        }
    };

    $fixture = pairingFixture();
    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->seen)->toBe(['A', 'B', 'C', 'D', 'E']);
})->with(ReaderDrivers::ALL);

it('allows SkipsOnFailure without WithValidation and never calls onFailure', function (string $driver) {
    $import = new class implements SkipsOnFailure, ToArray, WithHeadingRow
    {
        public array $rows = [];

        public int $onFailureCalls = 0;

        public function array(array $array): void
        {
            $this->rows = $array;
        }

        public function onFailure(Collection $failures): void
        {
            $this->onFailureCalls++;
        }
    };

    // Nothing validates, so even a row that looks wrong is delivered.
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'not-an-email'],
        ['Bob', 'bob@example.com'],
    ]);

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->onFailureCalls)->toBe(0)
        ->and($import->rows)->toBe([
            ['name' => 'Alice', 'email' => 'not-an-email'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
        ]);
})->with(ReaderDrivers::ALL);

it('allows SkipsOnFailure without WithValidation in the staging pipeline', function () {
    $import = new class implements ShouldQueue, SkipsOnFailure, ToArray, UsesStagingTable, WithHeadingRow
    {
        public array $rows = [];

        public int $onFailureCalls = 0;

        public function array(array $array): void
        {
            array_push($this->rows, ...$array);
        }

        public function onFailure(Collection $failures): void
        {
            $this->onFailureCalls++;
        }
    };

    $fixture = pairingFixture();

    runPairingStagingPipeline($import, $fixture->path());

    expect($import->onFailureCalls)->toBe(0)
        ->and(array_column($import->rows, 'name'))->toBe(['A', 'B', 'C', 'D', 'E']);
});
