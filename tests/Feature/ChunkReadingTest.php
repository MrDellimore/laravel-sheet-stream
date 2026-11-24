<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use MrDellimore\SheetStream\Concerns\RemembersChunkOffset;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\WithChunkReading;
use MrDellimore\SheetStream\Concerns\WithParallelSheets;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Jobs\QueuedImportJob;
use MrDellimore\SheetStream\Jobs\QueuedSheetImportJob;
use MrDellimore\SheetStream\Tests\Fixtures\CsvFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedCollectionImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedLoggedSheet;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedModelImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedMultiSheetImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedOnEachRowImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedCollectionImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedFixture;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedModelImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedMultiSheetImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedOnEachRowImport;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetXlsxFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\ReaderDrivers;
use MrDellimore\SheetStream\Tests\Fixtures\StubModel;

/*
 * WithChunkReading: a chunk is chunkSize() consecutive data rows, counted before validation.
 * At each boundary, and at the end of the sheet for a partial chunk:
 *   onFailure() → collection() / array() / save the remaining models → AfterChunk.
 *
 * The id of a fixture row is its spreadsheet row number (the heading is row 1), so in
 * every ['collection', offset, [key => id]] entry below, offset + key must equal id.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('stubs', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });
});

/** A chunked ToCollection or ToArray import, optionally validating (and skipping) rows. */
function chunkedBufferingImport(string $kind, string $validation = 'none', int $chunkSize = 2): ChunkedCollectionImport|ChunkedArrayImport
{
    return match ([$kind, $validation]) {
        ['collection', 'none'] => new ChunkedCollectionImport($chunkSize),
        ['collection', 'skips'] => new class($chunkSize) extends ChunkedCollectionImport implements SkipsOnFailure, WithValidation {},
        ['collection', 'throws'] => new class($chunkSize) extends ChunkedCollectionImport implements WithValidation {},
        ['array', 'none'] => new ChunkedArrayImport($chunkSize),
        ['array', 'skips'] => new class($chunkSize) extends ChunkedArrayImport implements SkipsOnFailure, WithValidation {},
        ['array', 'throws'] => new class($chunkSize) extends ChunkedArrayImport implements WithValidation {},
    };
}

dataset('chunked buffering kinds', ['collection', 'array']);

// --- collection() / array() per chunk (plan items 1-6, 12) ---

it('hands over each chunk as it fills, then fires AfterChunk', function (string $kind, string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D', 'E']);
    $import = ReaderDrivers::import($driver, chunkedBufferingImport($kind), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        [$kind, 2, [0 => 2, 1 => 3]],
        ['AfterChunk', 0, 2],
        [$kind, 4, [0 => 4, 1 => 5]],
        ['AfterChunk', 1, 2],
        [$kind, 6, [0 => 6]],
        ['AfterChunk', 2, 1],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 4, 6]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

it('makes no trailing call when the rows are an exact multiple of the chunk size', function (string $kind, string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D']);
    $import = ReaderDrivers::import($driver, chunkedBufferingImport($kind), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        [$kind, 2, [0 => 2, 1 => 3]],
        ['AfterChunk', 0, 2],
        [$kind, 4, [0 => 4, 1 => 5]],
        ['AfterChunk', 1, 2],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 4]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

it('makes no call and fires no AfterChunk for a sheet with only a heading', function (string $kind, string $driver) {
    $fixture = LoggedFixture::xlsx([]);
    $import = ReaderDrivers::import($driver, chunkedBufferingImport($kind), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['AfterSheet', 0],
    ]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

it('puts a sheet smaller than the chunk size in one chunk', function (string $kind, string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = ReaderDrivers::import($driver, chunkedBufferingImport($kind, chunkSize: 500), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        [$kind, 2, [0 => 2, 1 => 3, 2 => 4]],
        ['AfterChunk', 0, 3],
        ['AfterSheet', 0],
    ]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

it('makes a chunk of every row when the chunk size is 1', function (string $kind, string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = ReaderDrivers::import($driver, chunkedBufferingImport($kind, chunkSize: 1), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        [$kind, 2, [0 => 2]],
        ['AfterChunk', 0, 1],
        [$kind, 3, [0 => 3]],
        ['AfterChunk', 1, 1],
        [$kind, 4, [0 => 4]],
        ['AfterChunk', 2, 1],
        ['AfterSheet', 0],
    ]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

it('fires ceil(rows / chunkSize) AfterChunk events numbered from 0', function (int $rows, int $chunkSize, string $driver) {
    $fixture = LoggedFixture::xlsx(array_fill(0, $rows, 'A'));
    $import = ReaderDrivers::import($driver, new ChunkedCollectionImport($chunkSize), $fixture->path());

    $chunks = $import->entries('AfterChunk');
    $expected = (int) ceil($rows / $chunkSize);

    expect($chunks)->toHaveCount($expected)
        ->and(array_column($chunks, 0))->toBe(range(0, $expected - 1))
        ->and(array_sum(array_column($chunks, 1)))->toBe($rows)
        ->and($import->entries('collection'))->toHaveCount($expected)
        // Each chunk starts chunkSize rows after the last; the first data row is row 2.
        ->and($import->chunkStartRows)->toBe(range(2, $rows + 1, $chunkSize));
})->with([[7, 3], [9, 3], [10, 3], [1, 3], [25, 4]])->with(ReaderDrivers::ALL);

it('keeps ToArray rows plain arrays', function (string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = ReaderDrivers::import($driver, new ChunkedArrayImport, $fixture->path());

    expect($import->rowsWerePlainArrays)->toBeTrue()
        ->and($import->entries('array'))->toHaveCount(2);
})->with(ReaderDrivers::ALL);

it('ignores batchSize for collection() and array(): the chunk size alone sets the cadence', function (string $kind, string $driver) {
    // LitPortal's bulk importers declare batchSize 100 with chunkSize 500.
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D', 'E']);
    $import = $kind === 'collection' ? new ChunkedCollectionImport(chunkSize: 4, batchSize: 1) : new ChunkedArrayImport(chunkSize: 4, batchSize: 1);

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        [$kind, 2, [0 => 2, 1 => 3, 2 => 4, 3 => 5]],
        ['AfterChunk', 0, 4],
        [$kind, 6, [0 => 6]],
        ['AfterChunk', 1, 1],
        ['AfterSheet', 0],
    ]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

// --- validation (plan items 4, 7-10) ---

it('runs onFailure, then the rows, then AfterChunk for each chunk, with failed rows holding their slot', function (string $kind, string $driver) {
    //                                row: 2    3      4    5    6      7      8
    $fixture = LoggedFixture::xlsx(['A', 'BAD', 'C', 'D', 'BAD', 'BAD', 'E']);
    $import = ReaderDrivers::import($driver, chunkedBufferingImport($kind, 'skips'), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        // rows 2-3: row 3 failed but still filled the chunk
        ['onFailure', [3]],
        [$kind, 2, [0 => 2]],
        ['AfterChunk', 0, 2],
        // rows 4-5: no failures, so no onFailure
        [$kind, 4, [0 => 4, 1 => 5]],
        ['AfterChunk', 1, 2],
        // rows 6-7: every row failed, so nothing is handed over but the chunk still completes
        ['onFailure', [6, 7]],
        ['AfterChunk', 2, 2],
        // row 8
        [$kind, 8, [0 => 8]],
        ['AfterChunk', 3, 1],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 4, 6, 8]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

it('leaves a gap in the keys where a row failed, so offset + key is still the row number', function (string $kind, string $driver) {
    //                                row: 2      3    4      5    6
    $fixture = LoggedFixture::xlsx(['BAD', 'A', 'BAD', 'B', 'C']);
    $import = ReaderDrivers::import($driver, chunkedBufferingImport($kind, 'skips', chunkSize: 4), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['onFailure', [2, 4]],
        // The offset is the chunk's first row even though that row failed.
        [$kind, 2, [1 => 3, 3 => 5]],
        ['AfterChunk', 0, 4],
        [$kind, 6, [0 => 6]],
        ['AfterChunk', 1, 1],
        ['AfterSheet', 0],
    ]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

it('gives onFailure the Failure objects of its own chunk only', function (string $driver) {
    $import = new class(2) extends ChunkedCollectionImport implements SkipsOnFailure, WithValidation
    {
        public array $failureBatches = [];

        public function onFailure(Collection $failures): void
        {
            $this->failureBatches[] = $failures->map(fn ($f): array => [$f->row(), array_keys($f->errors()), $f->values()['name']])->all();
        }
    };

    //                                row: 2      3    4    5      6
    $fixture = LoggedFixture::xlsx(['BAD', 'A', 'B', 'BAD', 'C']);
    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->failureBatches)->toBe([
        [[2, ['name'], 'BAD']],
        [[5, ['name'], 'BAD']],
    ]);
})->with(ReaderDrivers::ALL);

it('throws at the first invalid row without SkipsOnFailure, after earlier chunks were delivered', function (string $kind, string $driver) {
    //                                row: 2    3    4    5      6
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'BAD', 'D']);
    $import = chunkedBufferingImport($kind, 'throws');

    expect(fn () => ReaderDrivers::import($driver, $import, $fixture->path()))
        ->toThrow(ValidationException::class);

    // Chunk 0 has run. Row 4 was read but its chunk never completed, so it was not delivered.
    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        [$kind, 2, [0 => 2, 1 => 3]],
        ['AfterChunk', 0, 2],
    ]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

// --- SkipsEmptyRows (plan item 11) ---

it('does not count rows skipped by SkipsEmptyRows toward a chunk', function (string $kind, string $driver) {
    // CSV rows of empty cells reach the runner on every driver.
    //                              row: 2    3   4    5   6    7
    $fixture = LoggedFixture::csv(['A', '', 'B', '', 'C', 'D']);
    $import = $kind === 'collection'
        ? new class extends ChunkedCollectionImport implements SkipsEmptyRows {}
    : new class extends ChunkedArrayImport implements SkipsEmptyRows {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        [$kind, 2, [0 => 2, 2 => 4]],   // the skipped row 3 leaves a gap
        ['AfterChunk', 0, 2],
        [$kind, 6, [0 => 6, 1 => 7]],
        ['AfterChunk', 1, 2],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 6]);
})->with('chunked buffering kinds')->with(ReaderDrivers::ALL);

it('counts an empty row toward the chunk when the import does not skip empty rows', function (string $driver) {
    //                              row: 2    3   4
    $fixture = LoggedFixture::csv(['A', '', 'B']);
    $import = ReaderDrivers::import($driver, new ChunkedCollectionImport, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['collection', 2, [0 => 2, 1 => null]],
        ['AfterChunk', 0, 2],
        ['collection', 4, [0 => 4]],
        ['AfterChunk', 1, 1],
        ['AfterSheet', 0],
    ]);
})->with(ReaderDrivers::ALL);

// --- ToModel (plan item 13) ---

it('saves ToModel batches within a chunk and the remainder at its boundary, firing AfterChunk per chunk', function (string $driver) {
    // 11 rows, batchSize 2, chunkSize 5 → saves of [2, 2, 1 | 2, 2, 1 | 1].
    $fixture = LoggedFixture::xlsx(array_map(fn (int $i): string => "M{$i}", range(1, 11)));
    $import = ReaderDrivers::import($driver, new ChunkedModelImport(chunkSize: 5, batchSize: 2), $fixture->path());

    // ['model', id, row number, chunk offset, models saved so far]; AfterChunk ends with the saved count.
    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['model', 2, 2, 2, 0],
        ['model', 3, 3, 2, 0],
        ['model', 4, 4, 2, 2],
        ['model', 5, 5, 2, 2],
        ['model', 6, 6, 2, 4],
        ['AfterChunk', 0, 5, 5],
        ['model', 7, 7, 7, 5],
        ['model', 8, 8, 7, 5],
        ['model', 9, 9, 7, 7],
        ['model', 10, 10, 7, 7],
        ['model', 11, 11, 7, 9],
        ['AfterChunk', 1, 5, 10],
        ['model', 12, 12, 12, 10],
        ['AfterChunk', 2, 1, 11],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 7, 12])
        ->and(StubModel::pluck('name')->all())->toBe(array_map(fn (int $i): string => "M{$i}", range(1, 11)));
})->with(ReaderDrivers::ALL);

it('saves a whole ToModel chunk at its boundary when the batch size is larger than the chunk', function (string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D', 'E']);
    $import = ReaderDrivers::import($driver, new ChunkedModelImport(chunkSize: 2, batchSize: 1000), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['model', 2, 2, 2, 0],
        ['model', 3, 3, 2, 0],
        ['AfterChunk', 0, 2, 2],
        ['model', 4, 4, 4, 2],
        ['model', 5, 5, 4, 2],
        ['AfterChunk', 1, 2, 4],
        ['model', 6, 6, 6, 4],
        ['AfterChunk', 2, 1, 5],
        ['AfterSheet', 0],
    ]);
})->with(ReaderDrivers::ALL);

it('counts ToModel rows that produce no model toward the chunk', function (string $driver) {
    //                                row: 2    3       4       5       6    7
    $fixture = LoggedFixture::xlsx(['A', 'SKIP', 'SKIP', 'SKIP', 'B', 'C']);
    $import = ReaderDrivers::import($driver, new ChunkedModelImport(chunkSize: 2, batchSize: 1000), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['model', 2, 2, 2, 0],
        ['model', 3, 3, 2, 0],
        ['AfterChunk', 0, 2, 1],
        // A chunk that produced no model at all still completes.
        ['model', 4, 4, 4, 1],
        ['model', 5, 5, 4, 1],
        ['AfterChunk', 1, 2, 1],
        ['model', 6, 6, 6, 1],
        ['model', 7, 7, 6, 1],
        ['AfterChunk', 2, 2, 3],
        ['AfterSheet', 0],
    ])->and(StubModel::pluck('name')->all())->toBe(['A', 'B', 'C']);
})->with(ReaderDrivers::ALL);

it('calls onFailure before saving the rest of a ToModel chunk', function (string $driver) {
    //                                row: 2    3      4    5      6
    $fixture = LoggedFixture::xlsx(['A', 'BAD', 'B', 'BAD', 'C']);
    $import = new class(chunkSize: 2, batchSize: 1000) extends ChunkedModelImport implements SkipsOnFailure, WithValidation
    {
        public function onFailure(Collection $failures): void
        {
            $this->log[] = ['onFailure', $failures->map(fn ($f): int => $f->row())->all(), StubModel::count()];
        }
    };

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['model', 2, 2, 2, 0],
        ['onFailure', [3], 0],
        ['AfterChunk', 0, 2, 1],
        ['model', 4, 4, 4, 1],
        ['onFailure', [5], 1],
        ['AfterChunk', 1, 2, 2],
        ['model', 6, 6, 6, 2],
        ['AfterChunk', 2, 1, 3],
        ['AfterSheet', 0],
    ]);
})->with(ReaderDrivers::ALL);

// --- OnEachRow (plan item 14) ---

it('fires AfterChunk every chunkSize OnEachRow rows', function (string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D', 'E', 'F', 'G']);
    $import = ReaderDrivers::import($driver, new ChunkedOnEachRowImport(chunkSize: 3, batchSize: 1), $fixture->path());

    // ['row', id, row number, chunk offset]
    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['row', 2, 2, 2],
        ['row', 3, 3, 2],
        ['row', 4, 4, 2],
        ['AfterChunk', 0, 3],
        ['row', 5, 5, 5],
        ['row', 6, 6, 5],
        ['row', 7, 7, 5],
        ['AfterChunk', 1, 3],
        ['row', 8, 8, 8],
        ['AfterChunk', 2, 1],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 5, 8]);
})->with(ReaderDrivers::ALL);

it('closes an OnEachRow chunk on a failed row and sets the offset before validation', function (string $driver) {
    //                                row: 2    3    4      5      6
    $fixture = LoggedFixture::xlsx(['A', 'B', 'BAD', 'BAD', 'C']);
    $import = new class(chunkSize: 3) extends ChunkedOnEachRowImport implements SkipsOnFailure, WithValidation {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['row', 2, 2, 2],
        ['row', 3, 3, 2],
        ['onFailure', [4]],
        ['AfterChunk', 0, 3],
        // Row 5 failed, but it is the chunk's first row and so its offset.
        ['row', 6, 6, 5],
        ['onFailure', [5]],
        ['AfterChunk', 1, 2],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 5]);
})->with(ReaderDrivers::ALL);

// --- without WithChunkReading (plan item 15; NonChunkedImportTest pins the rest) ---

it('changes nothing for an import that has a chunkSize() method but not WithChunkReading', function (string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D', 'E']);
    $import = ReaderDrivers::import($driver, new LoggedCollectionImport(chunkSize: 2, batchSize: 2), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['AfterChunk', 0, 2],
        ['AfterChunk', 1, 2],
        ['AfterChunk', 2, 1],
        ['collection', 6, [0 => 2, 1 => 3, 2 => 4, 3 => 5, 4 => 6]],
        ['AfterSheet', 0],
    ]);
})->with(ReaderDrivers::ALL);

it('reports the first delivered row of each batch as AfterChunk::$startRow without chunk reading', function (string $driver) {
    //                                row: 2    3    4      5    6
    $fixture = LoggedFixture::xlsx(['A', 'B', 'BAD', 'C', 'D']);

    $collection = new class(batchSize: 2) extends LoggedCollectionImport implements SkipsOnFailure, WithValidation {};
    $array = new class(batchSize: 2) extends LoggedArrayImport implements SkipsOnFailure, WithValidation {};
    $model = new class(batchSize: 2) extends LoggedModelImport implements SkipsOnFailure, WithValidation {};
    $onEachRow = new class(batchSize: 2) extends LoggedOnEachRowImport implements SkipsOnFailure, WithValidation {};

    foreach ([$collection, $array, $model, $onEachRow] as $import) {
        ReaderDrivers::import($driver, $import, $fixture->path());

        expect($import->chunkStartRows)->toBe([2, 5]);
    }
})->with(ReaderDrivers::ALL);

// --- chunkSize() validation (plan item 16) ---

it('rejects a chunk size below 1 before the import starts', function (int $chunkSize) {
    $fixture = LoggedFixture::xlsx(['A']);
    $import = new ChunkedCollectionImport($chunkSize);

    expect(fn () => ReaderDrivers::import('openspout', $import, $fixture->path()))
        ->toThrow(InvalidConcernCombination::class, "WithChunkReading::chunkSize() must be at least 1, got {$chunkSize}.");

    expect($import->log)->toBe([]);
})->with([0, -1]);

it('rejects a chunk size below 1 declared on the parent of a multi-sheet import', function () {
    $fixture = LoggedFixture::multiSheetXlsx(['First' => ['A'], 'Second' => ['B']]);
    $import = new ChunkedMultiSheetImport(0);

    expect(fn () => ReaderDrivers::import('openspout', $import, $fixture->path()))
        ->toThrow(InvalidConcernCombination::class, 'WithChunkReading::chunkSize() must be at least 1, got 0.');

    expect($import->log)->toBe([]);
});

it('accepts a bad parent chunk size when every sheet declares its own', function () {
    $fixture = LoggedFixture::multiSheetXlsx(['First' => ['A'], 'Second' => ['B']]);
    $import = new ChunkedMultiSheetImport(0);
    $import->sheets = [new ChunkedLoggedSheet($import, 0, 5), new ChunkedLoggedSheet($import, 1, 5)];

    ReaderDrivers::import('openspout', $import, $fixture->path());

    expect($import->log)->toBe([
        ['collection', 0, 2, [0 => 2]],
        ['AfterChunk', 0, 0, 1],
        ['collection', 1, 2, [0 => 2]],
        ['AfterChunk', 1, 0, 1],
    ]);
});

// --- multiple sheets (plan item 17) ---

/** Two sheets: three rows, then five. */
function chunkedTwoSheetFixture(): MultiSheetXlsxFixtureBuilder
{
    return LoggedFixture::multiSheetXlsx([
        'First' => ['A', 'B', 'C'],
        'Second' => ['D', 'E', 'F', 'G', 'H'],
    ]);
}

/** What ChunkedMultiSheetImport(2) logs for chunkedTwoSheetFixture(). */
function chunkedTwoSheetLog(): array
{
    // ['collection', sheet, chunk offset, [key => id]]; ['AfterChunk', sheet, chunk number, rows]
    return [
        ['collection', 0, 2, [0 => 2, 1 => 3]],
        ['AfterChunk', 0, 0, 2],
        ['collection', 0, 4, [0 => 4]],
        ['AfterChunk', 0, 1, 1],
        ['collection', 1, 2, [0 => 2, 1 => 3]],
        ['AfterChunk', 1, 0, 2],
        ['collection', 1, 4, [0 => 4, 1 => 5]],
        ['AfterChunk', 1, 1, 2],
        ['collection', 1, 6, [0 => 6]],
        ['AfterChunk', 1, 2, 1],
    ];
}

it('applies the parent chunk size to every sheet, restarting chunk numbers and offsets per sheet', function (string $driver) {
    $fixture = chunkedTwoSheetFixture();
    $import = ReaderDrivers::import($driver, new ChunkedMultiSheetImport(2), $fixture->path());

    expect($import->log)->toBe(chunkedTwoSheetLog())
        ->and($import->chunkStartRows)->toBe([2, 4, 2, 4, 6]);
})->with(ReaderDrivers::ALL);

it('lets a sheet override the parent chunk size', function (string $driver) {
    $fixture = chunkedTwoSheetFixture();
    $import = new ChunkedMultiSheetImport(2);
    $import->sheets[1] = new ChunkedLoggedSheet($import, 1, 3);

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        ['collection', 0, 2, [0 => 2, 1 => 3]],
        ['AfterChunk', 0, 0, 2],
        ['collection', 0, 4, [0 => 4]],
        ['AfterChunk', 0, 1, 1],
        ['collection', 1, 2, [0 => 2, 1 => 3, 2 => 4]],
        ['AfterChunk', 1, 0, 3],
        ['collection', 1, 5, [0 => 5, 1 => 6]],
        ['AfterChunk', 1, 1, 2],
    ]);
})->with(ReaderDrivers::ALL);

it('chunks only the sheet that asks for it when the parent declares no chunk size', function (string $driver) {
    $fixture = chunkedTwoSheetFixture();
    $import = new LoggedMultiSheetImport;
    $import->sheets[1] = new ChunkedLoggedSheet($import, 1, 3);

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        // Sheet 0 is not chunked: one AfterChunk for the batch, then one collection() call.
        ['AfterChunk', 0, 0, 3],
        ['collection', 0, 2, [0 => 2, 1 => 3, 2 => 4]],
        ['collection', 1, 2, [0 => 2, 1 => 3, 2 => 4]],
        ['AfterChunk', 1, 0, 3],
        ['collection', 1, 5, [0 => 5, 1 => 6]],
        ['AfterChunk', 1, 1, 2],
    ]);
})->with(ReaderDrivers::ALL);

// --- entry points (plan item 18) ---

it('chunks the same way through SheetStream::import()', function (string $driver) {
    ReaderDrivers::configure($driver);

    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D', 'E']);
    $viaRunner = ReaderDrivers::import($driver, new ChunkedCollectionImport, $fixture->path());

    $import = new ChunkedCollectionImport;
    SheetStream::import($import, $fixture->path());

    expect($import->log)->toBe($viaRunner->log)
        ->and($import->entries('collection'))->toHaveCount(3);

    $fixture = chunkedTwoSheetFixture();
    $multi = new ChunkedMultiSheetImport(2);
    SheetStream::import($multi, $fixture->path());

    expect($multi->log)->toBe(chunkedTwoSheetLog());
})->with(ReaderDrivers::ALL);

it('chunks the same way in a QueuedImportJob', function (string $driver) {
    ReaderDrivers::configure($driver);

    //                                row: 2    3      4    5    6
    $fixture = LoggedFixture::xlsx(['A', 'BAD', 'C', 'D', 'E']);
    $sync = new class extends ChunkedCollectionImport implements SkipsOnFailure, WithValidation {};
    $queued = new class extends ChunkedCollectionImport implements ShouldQueue, SkipsOnFailure, WithValidation {};

    ReaderDrivers::import($driver, $sync, $fixture->path());
    new QueuedImportJob(import: $queued, filePath: $fixture->path())->handle();

    expect($queued->log)->toBe($sync->log)
        ->and($queued->log)->toBe([
            ['BeforeSheet', 0],
            ['onFailure', [3]],
            ['collection', 2, [0 => 2]],
            ['AfterChunk', 0, 2],
            ['collection', 4, [0 => 4, 1 => 5]],
            ['AfterChunk', 1, 2],
            ['collection', 6, [0 => 6]],
            ['AfterChunk', 2, 1],
            ['AfterSheet', 0],
        ]);
})->with(ReaderDrivers::ALL);

it('applies the parent chunk size in a multi-sheet QueuedImportJob', function (string $driver) {
    ReaderDrivers::configure($driver);

    $fixture = chunkedTwoSheetFixture();
    $import = new class(2) extends ChunkedMultiSheetImport implements ShouldQueue {};

    new QueuedImportJob(import: $import, filePath: $fixture->path())->handle();

    expect($import->log)->toBe(chunkedTwoSheetLog());
})->with(ReaderDrivers::ALL);

it('applies the parent chunk size in parallel QueuedSheetImportJobs', function (string $driver) {
    ReaderDrivers::configure($driver);
    Bus::fake([QueuedSheetImportJob::class]);

    $fixture = chunkedTwoSheetFixture();
    $import = new class(2) extends ChunkedMultiSheetImport implements ShouldQueue, WithParallelSheets {};

    new QueuedImportJob(import: $import, filePath: $fixture->path())->handle();

    $jobs = Bus::dispatched(QueuedSheetImportJob::class);
    expect($jobs)->toHaveCount(2)
        ->and($import->log)->toBe([]);

    foreach ($jobs as $job) {
        $job->handle();
    }

    expect($import->log)->toBe(chunkedTwoSheetLog())
        ->and($import->chunkStartRows)->toBe([2, 4, 2, 4, 6]);
})->with(ReaderDrivers::ALL);

it('lets a sheet override the parent chunk size in a parallel QueuedSheetImportJob, and validates it there', function () {
    Bus::fake([QueuedSheetImportJob::class]);

    $fixture = chunkedTwoSheetFixture();
    $import = new class(2) extends ChunkedMultiSheetImport implements ShouldQueue, WithParallelSheets {};
    $import->sheets[0] = new ChunkedLoggedSheet($import, 0, 0);
    $import->sheets[1] = new ChunkedLoggedSheet($import, 1, 3);

    new QueuedImportJob(import: $import, filePath: $fixture->path())->handle();
    [$first, $second] = Bus::dispatched(QueuedSheetImportJob::class)->all();

    expect(fn () => $first->handle())->toThrow(InvalidConcernCombination::class, 'must be at least 1, got 0');

    $second->handle();

    expect($import->log)->toBe([
        ['collection', 1, 2, [0 => 2, 1 => 3, 2 => 4]],
        ['AfterChunk', 1, 0, 3],
        ['collection', 1, 5, [0 => 5, 1 => 6]],
        ['AfterChunk', 1, 1, 2],
    ]);
});

it('runs a sheet through runSheet() without a parent exactly as before', function () {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $chunked = new ChunkedCollectionImport;
    $plain = new LoggedCollectionImport;

    foreach ([$chunked, $plain] as $import) {
        $reader = ReaderDrivers::open('openspout', $fixture->path());

        foreach ($reader->sheets() as $index => $sheet) {
            new ImportRunner(defaultBatchSize: 2)->runSheet($import, $sheet, null, $index);
        }

        $reader->close();
    }

    // No bus was passed, so only the collection() calls are logged.
    expect($chunked->log)->toBe([
        ['collection', 2, [0 => 2, 1 => 3]],
        ['collection', 4, [0 => 4]],
    ])->and($plain->log)->toBe([
        ['collection', 2, [0 => 2, 1 => 3, 2 => 4]],
    ]);
});

// --- memory (plan items 19, 20) ---

it('lets go of each chunk before reading the next', function (string $driver) {
    $import = new class implements ToCollection, WithChunkReading
    {
        /** @var list<WeakReference<Collection>> */
        public array $chunks = [];

        /** Per collection() call: which earlier chunks were still alive. */
        public array $aliveBefore = [];

        public function collection(Collection $collection): void
        {
            $this->aliveBefore[] = array_keys(array_filter($this->chunks, fn (WeakReference $ref): bool => $ref->get() !== null));
            $this->chunks[] = WeakReference::create($collection);
        }

        public function chunkSize(): int
        {
            return 2;
        }
    };

    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D', 'E', 'F', 'G']);
    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->chunks)->toHaveCount(4)
        ->and($import->aliveBefore)->toBe([[], [], [], []]);
})->with(ReaderDrivers::ALL);

it('lets go of saved models at each chunk boundary', function (string $driver) {
    $import = new class(chunkSize: 3) extends ChunkedModelImport
    {
        /** @var list<WeakReference<Model>> */
        public array $models = [];

        /** Per model() call: how many models older than the previous row's were still alive. */
        public array $aliveBefore = [];

        public function model(array $row): Model
        {
            // The runner may still hold the model it was just given, so that one is left out.
            $older = array_slice($this->models, 0, -1);
            $this->aliveBefore[] = count(array_filter($older, fn (WeakReference $ref): bool => $ref->get() !== null));
            $model = new StubModel(['name' => $row['name'], 'email' => 'x']);
            $this->models[] = WeakReference::create($model);

            return $model;
        }
    };

    // chunkSize 3 with the default batch size: a chunk's models are held until its boundary
    // and no longer. A runner that kept its buffer would show 0, 0, 1, 2, 3, 4, 5.
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D', 'E', 'F', 'G']);
    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->aliveBefore)->toBe([0, 0, 1, 0, 0, 1, 0])
        ->and(StubModel::count())->toBe(7);
})->with(ReaderDrivers::ALL);

it('never hands over more than chunkSize rows of a 20,000-row file', function (string $driver) {
    $lines = ['id,name'];
    for ($row = 2; $row <= 20001; $row++) {
        $lines[] = "{$row},Name {$row}";
    }
    $fixture = (new CsvFixtureBuilder)->raw(implode("\n", $lines)."\n");

    $import = new class implements ToCollection, WithChunkReading
    {
        use RemembersChunkOffset;

        public array $sizes = [];

        public array $offsets = [];

        public int $rows = 0;

        public bool $keysAddUp = true;

        public function collection(Collection $collection): void
        {
            $this->sizes[] = $collection->count();
            $this->offsets[] = $this->getChunkOffset();
            $this->rows += $collection->count();

            foreach ($collection as $key => $row) {
                // Row 1 is the heading here (no WithHeadingRow), with id "id".
                $this->keysAddUp = $this->keysAddUp && ($row[0] === 'id' || (int) $row[0] === $this->getChunkOffset() + $key);
            }
        }

        public function chunkSize(): int
        {
            return 500;
        }
    };

    ReaderDrivers::import($driver, $import, $fixture->path());

    // 20,001 rows with the heading: 40 full chunks and one row left over.
    expect(max($import->sizes))->toBe(500)
        ->and($import->sizes)->toBe([...array_fill(0, 40, 500), 1])
        ->and($import->offsets)->toBe(range(1, 20001, 500))
        ->and($import->rows)->toBe(20001)
        ->and($import->keysAddUp)->toBeTrue();
})->with(ReaderDrivers::ALL);
