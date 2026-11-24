<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedCollectionImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedFixture;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedModelImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedMultiSheetImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedOnEachRowImport;
use MrDellimore\SheetStream\Tests\Fixtures\ReaderDrivers;
use MrDellimore\SheetStream\Tests\Fixtures\StubModel;

/*
 * Characterization tests: what an import WITHOUT WithChunkReading does. Chunk reading must
 * not change any of it. Every import here uses batchSize 2; the id of a row is its
 * spreadsheet row number (the heading is row 1).
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('stubs', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });
});

/** A non-chunked ToCollection or ToArray import, optionally validating (and skipping) rows. */
function nonChunkedBufferingImport(string $kind, string $validation = 'none'): LoggedCollectionImport|LoggedArrayImport
{
    return match ([$kind, $validation]) {
        ['collection', 'none'] => new LoggedCollectionImport(batchSize: 2),
        ['collection', 'skips'] => new class(batchSize: 2) extends LoggedCollectionImport implements SkipsOnFailure, WithValidation {},
        ['collection', 'throws'] => new class(batchSize: 2) extends LoggedCollectionImport implements WithValidation {},
        ['array', 'none'] => new LoggedArrayImport(batchSize: 2),
        ['array', 'skips'] => new class(batchSize: 2) extends LoggedArrayImport implements SkipsOnFailure, WithValidation {},
        ['array', 'throws'] => new class(batchSize: 2) extends LoggedArrayImport implements WithValidation {},
    };
}

dataset('buffering kinds', ['collection', 'array']);

it('delivers every row in one call after the sheet, with AfterChunk per batch before it', function (string $kind, string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D', 'E']);
    $import = ReaderDrivers::import($driver, nonChunkedBufferingImport($kind), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['AfterChunk', 0, 2],
        ['AfterChunk', 1, 2],
        ['AfterChunk', 2, 1],
        [$kind, 6, [0 => 2, 1 => 3, 2 => 4, 3 => 5, 4 => 6]],
        ['AfterSheet', 0],
    ]);
})->with('buffering kinds')->with(ReaderDrivers::ALL);

it('fires no trailing AfterChunk when the rows are an exact multiple of the batch size', function (string $kind, string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C', 'D']);
    $import = ReaderDrivers::import($driver, nonChunkedBufferingImport($kind), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['AfterChunk', 0, 2],
        ['AfterChunk', 1, 2],
        [$kind, 4, [0 => 2, 1 => 3, 2 => 4, 3 => 5]],
        ['AfterSheet', 0],
    ]);
})->with('buffering kinds')->with(ReaderDrivers::ALL);

it('still makes the one call, with no rows and no AfterChunk, for a sheet with only a heading', function (string $kind, string $driver) {
    $fixture = LoggedFixture::xlsx([]);
    $import = ReaderDrivers::import($driver, nonChunkedBufferingImport($kind), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        [$kind, 0, []],
        ['AfterSheet', 0],
    ]);
})->with('buffering kinds')->with(ReaderDrivers::ALL);

it('calls onFailure once at the end of the sheet and does not count failed rows toward a batch', function (string $kind, string $driver) {
    //                                row: 2    3      4    5    6      7    8
    $fixture = LoggedFixture::xlsx(['A', 'BAD', 'C', 'D', 'BAD', 'E', 'F']);
    $import = ReaderDrivers::import($driver, nonChunkedBufferingImport($kind, 'skips'), $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['AfterChunk', 0, 2],   // rows 2, 4
        ['AfterChunk', 1, 2],   // rows 5, 7
        ['AfterChunk', 2, 1],   // row 8
        ['onFailure', [3, 6]],
        // Keys are 0..n-1 with no gaps, and the offset is that of the last batch's first valid row.
        [$kind, 8, [0 => 2, 1 => 4, 2 => 5, 3 => 7, 4 => 8]],
        ['AfterSheet', 0],
    ]);
})->with('buffering kinds')->with(ReaderDrivers::ALL);

it('throws on the first invalid row without SkipsOnFailure and delivers nothing', function (string $kind, string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'BAD', 'C']);
    $import = nonChunkedBufferingImport($kind, 'throws');

    expect(fn () => ReaderDrivers::import($driver, $import, $fixture->path()))
        ->toThrow(ValidationException::class);

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['AfterChunk', 0, 2],
    ]);
})->with('buffering kinds')->with(ReaderDrivers::ALL);

it('hands ToArray plain arrays', function (string $driver) {
    $fixture = LoggedFixture::xlsx(['A', 'B', 'C']);
    $import = ReaderDrivers::import($driver, new LoggedArrayImport(batchSize: 2), $fixture->path());

    expect($import->rowsWerePlainArrays)->toBeTrue()
        ->and($import->entries('array'))->toHaveCount(1);
})->with(ReaderDrivers::ALL);

it('does not count rows skipped by SkipsEmptyRows toward a batch', function (string $kind, string $driver) {
    // CSV rows of empty cells reach the runner on every driver.
    //                              row: 2    3   4    5   6    7
    $fixture = LoggedFixture::csv(['A', '', 'B', '', 'C', 'D']);
    $import = $kind === 'collection'
        ? new class(batchSize: 2) extends LoggedCollectionImport implements SkipsEmptyRows {}
    : new class(batchSize: 2) extends LoggedArrayImport implements SkipsEmptyRows {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['AfterChunk', 0, 2],   // rows 2, 4
        ['AfterChunk', 1, 2],   // rows 6, 7
        [$kind, 6, [0 => 2, 1 => 4, 2 => 6, 3 => 7]],
        ['AfterSheet', 0],
    ]);
})->with('buffering kinds')->with(ReaderDrivers::ALL);

it('flushes ToModel per batch with AfterChunk after each flush, counting only rows that produced a model', function (string $driver) {
    //                                row: 2    3    4       5    6    7
    $fixture = LoggedFixture::xlsx(['A', 'B', 'SKIP', 'C', 'D', 'E']);
    $import = ReaderDrivers::import($driver, new LoggedModelImport(batchSize: 2), $fixture->path());

    // ['model', id, row number, chunk offset, models saved so far]; AfterChunk ends with the saved count.
    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['model', 2, 2, 2, 0],
        ['model', 3, 3, 2, 0],
        ['AfterChunk', 0, 2, 2],
        ['model', 4, 4, 4, 2],   // no model: not counted
        ['model', 5, 5, 4, 2],
        ['model', 6, 6, 4, 2],
        ['AfterChunk', 1, 2, 4],
        ['model', 7, 7, 7, 4],
        ['AfterChunk', 2, 1, 5],
        ['AfterSheet', 0],
    ])->and(StubModel::pluck('name')->all())->toBe(['A', 'B', 'C', 'D', 'E']);
})->with(ReaderDrivers::ALL);

it('fires no AfterChunk for a ToModel sheet whose rows produced no model', function (string $driver) {
    $fixture = LoggedFixture::xlsx(['SKIP', 'SKIP']);
    $import = ReaderDrivers::import($driver, new LoggedModelImport(batchSize: 2), $fixture->path());

    expect($import->entries('AfterChunk'))->toBe([])
        ->and($import->entries('model'))->toHaveCount(2)
        ->and(StubModel::count())->toBe(0);
})->with(ReaderDrivers::ALL);

it('calls onFailure once at the end of a ToModel sheet', function (string $driver) {
    //                                row: 2    3      4    5
    $fixture = LoggedFixture::xlsx(['A', 'BAD', 'B', 'C']);
    $import = new class(batchSize: 2) extends LoggedModelImport implements SkipsOnFailure, WithValidation {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['model', 2, 2, 2, 0],
        ['model', 4, 4, 2, 0],
        ['AfterChunk', 0, 2, 2],
        ['model', 5, 5, 5, 2],
        ['AfterChunk', 1, 1, 3],
        ['onFailure', [3]],
        ['AfterSheet', 0],
    ]);
})->with(ReaderDrivers::ALL);

it('fires AfterChunk per batch of OnEachRow rows and sets the offset on the first valid row of each', function (string $driver) {
    //                                row: 2    3    4      5    6    7
    $fixture = LoggedFixture::xlsx(['A', 'B', 'BAD', 'C', 'D', 'E']);
    $import = new class(batchSize: 2) extends LoggedOnEachRowImport implements SkipsOnFailure, WithValidation {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    // ['row', id, row number, chunk offset]
    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['row', 2, 2, 2],
        ['row', 3, 3, 2],
        ['AfterChunk', 0, 2],
        ['row', 5, 5, 5],   // row 4 failed, so the batch starts (and the offset is set) at row 5
        ['row', 6, 6, 5],
        ['AfterChunk', 1, 2],
        ['row', 7, 7, 7],
        ['AfterChunk', 2, 1],
        ['onFailure', [4]],
        ['AfterSheet', 0],
    ]);
})->with(ReaderDrivers::ALL);

it('fires no AfterChunk for an OnEachRow sheet with only a heading', function (string $driver) {
    $fixture = LoggedFixture::xlsx([]);
    $import = ReaderDrivers::import($driver, new LoggedOnEachRowImport(batchSize: 2), $fixture->path());

    expect($import->log)->toBe([['BeforeSheet', 0], ['AfterSheet', 0]]);
})->with(ReaderDrivers::ALL);

it('uses the configured default batch size and restarts chunk numbers on each sheet', function (string $driver) {
    $fixture = LoggedFixture::multiSheetXlsx([
        'First' => ['A', 'B', 'C'],
        'Second' => ['D', 'E', 'F', 'G', 'H'],
    ]);

    // The parent's chunkSize() is not a concern here: it does not implement WithChunkReading.
    $import = ReaderDrivers::import($driver, new LoggedMultiSheetImport, $fixture->path(), new ImportRunner(defaultBatchSize: 2));

    // ['AfterChunk', sheet, chunk number, rows]; ['collection', sheet, chunk offset, [key => id]]
    expect($import->log)->toBe([
        ['AfterChunk', 0, 0, 2],
        ['AfterChunk', 0, 1, 1],
        ['collection', 0, 4, [0 => 2, 1 => 3, 2 => 4]],
        ['AfterChunk', 1, 0, 2],
        ['AfterChunk', 1, 1, 2],
        ['AfterChunk', 1, 2, 1],
        ['collection', 1, 6, [0 => 2, 1 => 3, 2 => 4, 3 => 5, 4 => 6]],
    ]);
})->with(ReaderDrivers::ALL);
