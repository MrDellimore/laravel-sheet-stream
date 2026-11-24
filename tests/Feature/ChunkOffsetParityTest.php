<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Engine\Contracts\KeysRowsBySourceRow;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Tests\Fixtures\CsvFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedCollectionImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedModelImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\ChunkedOnEachRowImport;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedFixture;
use MrDellimore\SheetStream\Tests\Fixtures\Logged\LoggedOnEachRowImport;
use MrDellimore\SheetStream\Tests\Fixtures\ReaderDrivers;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;
use OpenSpout\Reader\CSV\Options as CsvOptions;

/*
 * Row numbers come from the reader where it knows them (rust, phpspreadsheet), so a blank
 * row no longer shifts every number below it, and getChunkOffset() + key is the row's real
 * spreadsheet row. OpenSpout does not expose row numbers, so that driver numbers rows in
 * the order they arrive.
 *
 * The id of a fixture row is its real spreadsheet row number.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('stubs', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });
});

dataset('source row drivers', ['phpspreadsheet', 'rust']);

/** Every delivered row with an id, as [offset + key, id]: the two must match. */
function chunkOffsetPlusKeyPairs(ChunkedCollectionImport $import): array
{
    $pairs = [];

    foreach ($import->entries('collection') as [$offset, $ids]) {
        foreach ($ids as $key => $id) {
            if ($id !== null) {
                $pairs[] = [$offset + $key, $id];
            }
        }
    }

    return $pairs;
}

// --- the readers ---

it('keys rows by source row on the rust and phpspreadsheet readers only', function (string $driver, bool $keyed, array $expectedKeys) {
    //                                row: 2    3     4    5     6     7
    $fixture = LoggedFixture::xlsx(['A', null, 'B', null, null, 'C']);
    $reader = ReaderDrivers::open($driver, $fixture->path());

    foreach ($reader->sheets() as $sheet) {
        expect($sheet instanceof KeysRowsBySourceRow)->toBe($keyed);

        $keys = [];
        foreach ($sheet->rows() as $key => $row) {
            $keys[] = $key;
        }

        expect($keys)->toBe($expectedKeys);
    }

    $reader->close();
})->with([
    // The extension leaves blank rows out and keeps the numbering.
    'rust' => ['rust', true, [1, 2, 4, 7]],
    // PhpSpreadsheet yields blank rows too.
    'phpspreadsheet' => ['phpspreadsheet', true, [1, 2, 3, 4, 5, 6, 7]],
    // OpenSpout leaves blank rows out and has no numbering to keep.
    'openspout' => ['openspout', false, [0, 1, 2, 3]],
]);

// --- getChunkOffset() + key (plan items 21-23) ---

it('makes offset + key the real row when the reader leaves blank rows out', function () {
    //                                row: 2    3     4    5     6     7    8    9
    $fixture = LoggedFixture::xlsx(['A', null, 'B', null, null, 'C', 'D', 'E']);
    $import = ReaderDrivers::import('rust', new ChunkedCollectionImport, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['collection', 2, [0 => 2, 2 => 4]],
        ['AfterChunk', 0, 2],
        ['collection', 7, [0 => 7, 1 => 8]],
        ['AfterChunk', 1, 2],
        ['collection', 9, [0 => 9]],
        ['AfterChunk', 2, 1],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 7, 9]);
});

it('makes offset + key the real row when the reader yields blank rows', function () {
    // PhpSpreadsheet hands blank rows to the import (id null) unless it skips empty rows,
    // so they take a slot in the chunk.
    //                                row: 2    3     4    5     6     7    8    9
    $fixture = LoggedFixture::xlsx(['A', null, 'B', null, null, 'C', 'D', 'E']);
    $import = ReaderDrivers::import('phpspreadsheet', new ChunkedCollectionImport, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['collection', 2, [0 => 2, 1 => null]],
        ['AfterChunk', 0, 2],
        ['collection', 4, [0 => 4, 1 => null]],
        ['AfterChunk', 1, 2],
        ['collection', 6, [0 => null, 1 => 7]],
        ['AfterChunk', 2, 2],
        ['collection', 8, [0 => 8, 1 => 9]],
        ['AfterChunk', 3, 2],
        ['AfterSheet', 0],
    ]);
});

it('makes offset + key the real row for every row of every chunk, with blank rows skipped', function (string $driver, int $chunkSize) {
    //                                row: 2    3     4    5     6     7    8    9    10    11
    $fixture = LoggedFixture::xlsx(['A', null, 'B', null, null, 'C', 'D', 'E', null, 'F']);
    $import = new class($chunkSize) extends ChunkedCollectionImport implements SkipsEmptyRows {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    $pairs = chunkOffsetPlusKeyPairs($import);

    expect(array_column($pairs, 1))->toBe([2, 4, 7, 8, 9, 11])
        ->and(array_column($pairs, 0))->toBe([2, 4, 7, 8, 9, 11])
        ->and(array_sum(array_column($import->entries('AfterChunk'), 1)))->toBe(6);
})->with('source row drivers')->with([1, 2, 3, 4, 100]);

it('produces the same chunks on rust and phpspreadsheet once blank rows are skipped', function (string $driver) {
    //                                row: 2    3     4    5     6     7    8    9
    $fixture = LoggedFixture::xlsx(['A', null, 'B', null, null, 'C', 'D', 'E']);
    $import = new class extends ChunkedCollectionImport implements SkipsEmptyRows {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['collection', 2, [0 => 2, 2 => 4]],
        ['AfterChunk', 0, 2],
        ['collection', 7, [0 => 7, 1 => 8]],
        ['AfterChunk', 1, 2],
        ['collection', 9, [0 => 9]],
        ['AfterChunk', 2, 1],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 7, 9]);
})->with('source row drivers');

it('keeps offset + key right with failed rows, skipped rows and blank rows together', function (string $driver) {
    //                                row: 2    3     4      5    6     7    8
    $fixture = LoggedFixture::xlsx(['A', null, 'BAD', 'B', null, 'C', 'BAD']);
    $import = new class extends ChunkedCollectionImport implements SkipsEmptyRows, SkipsOnFailure, WithValidation {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['onFailure', [4]],             // the failure carries the real row too
        ['collection', 2, [0 => 2]],
        ['AfterChunk', 0, 2],
        ['collection', 5, [0 => 5, 2 => 7]],
        ['AfterChunk', 1, 2],
        ['onFailure', [8]],
        ['AfterChunk', 2, 1],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 5, 8]);
})->with('source row drivers');

it('numbers rows in arrival order on OpenSpout, so blank rows shift the numbers below them', function () {
    // The documented fallback: OpenSpout does not expose row numbers.
    //                                row: 2    3     4      5    6     7    8
    $fixture = LoggedFixture::xlsx(['A', null, 'BAD', 'B', null, 'C', 'BAD']);
    $import = new class extends ChunkedCollectionImport implements SkipsEmptyRows, SkipsOnFailure, WithValidation {};

    ReaderDrivers::import('openspout', $import, $fixture->path());

    // Rows 2, 4, 5, 7, 8 arrive as rows 2, 3, 4, 5, 6.
    expect($import->log)->toBe([
        ['BeforeSheet', 0],
        ['onFailure', [3]],
        ['collection', 2, [0 => 2]],
        ['AfterChunk', 0, 2],
        ['collection', 4, [0 => 5, 1 => 7]],
        ['AfterChunk', 1, 2],
        ['onFailure', [6]],
        ['AfterChunk', 2, 1],
        ['AfterSheet', 0],
    ])->and($import->chunkStartRows)->toBe([2, 4, 6]);
});

it('counts the rows above the heading row', function () {
    // Rows 1-2 are blank, the heading is on row 3.
    $fixture = (new XlsxFixtureBuilder)->write([[], [], ['id', 'name'], [4, 'A'], [], [6, 'B'], [7, 'C']]);
    $import = ReaderDrivers::import('rust', new ChunkedCollectionImport, $fixture->path());

    expect($import->entries('collection'))->toBe([
        [4, [0 => 4, 2 => 6]],
        [7, [0 => 7]],
    ]);
});

// --- CSV ---

it('numbers CSV rows by line, so a blank line does not shift them', function (string $driver) {
    //                             line: 2    3     4    5     6
    $fixture = LoggedFixture::csv(['A', null, 'B', null, 'C']);
    $import = new class extends ChunkedCollectionImport implements SkipsEmptyRows {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    expect($import->entries('collection'))->toBe([
        [2, [0 => 2, 2 => 4]],
        [6, [0 => 6]],
    ]);
})->with('source row drivers');

it('numbers a CSV record by the line it starts on with the rust reader', function () {
    // The record on line 3 spans two lines, so the next one starts on line 5.
    $fixture = (new CsvFixtureBuilder)->raw("id,name\n2,A\n3,\"two\nlines\"\n5,B\n\n7,C\n");
    $import = ReaderDrivers::import('rust', new ChunkedCollectionImport, $fixture->path());

    expect($import->entries('collection'))->toBe([
        [2, [0 => 2, 1 => 3]],
        [5, [0 => 5, 2 => 7]],
    ]);
});

it('numbers CSV records, not lines, with the phpspreadsheet reader', function () {
    // Documented difference: PhpSpreadsheet numbers records, so a multi-line record shifts what follows.
    $fixture = (new CsvFixtureBuilder)->raw("id,name\n2,A\n3,\"two\nlines\"\n5,B\n");
    $import = ReaderDrivers::import('phpspreadsheet', new ChunkedCollectionImport, $fixture->path());

    expect($import->entries('collection'))->toBe([
        [2, [0 => 2, 1 => 3]],
        [4, [0 => 5]],
    ]);
});

// --- RemembersRowNumber and Failure::row() (plan item 24) ---

it('gives RemembersRowNumber and Failure the source row, with and without chunk reading', function (string $driver) {
    //                                row: 2    3     4      5    6     7
    $fixture = LoggedFixture::xlsx(['A', null, 'BAD', 'B', null, 'C']);

    $plain = new class(batchSize: 2) extends LoggedOnEachRowImport implements SkipsEmptyRows, SkipsOnFailure, WithValidation {};
    ReaderDrivers::import($driver, $plain, $fixture->path());

    // ['row', id, row number, chunk offset]
    expect($plain->log)->toBe([
        ['BeforeSheet', 0],
        ['row', 2, 2, 2],
        ['row', 5, 5, 2],
        ['AfterChunk', 0, 2],
        ['row', 7, 7, 7],
        ['AfterChunk', 1, 1],
        ['onFailure', [4]],
        ['AfterSheet', 0],
    ]);

    $chunked = new class(chunkSize: 2) extends ChunkedOnEachRowImport implements SkipsEmptyRows, SkipsOnFailure, WithValidation {};
    ReaderDrivers::import($driver, $chunked, $fixture->path());

    expect($chunked->log)->toBe([
        ['BeforeSheet', 0],
        ['row', 2, 2, 2],
        ['onFailure', [4]],
        ['AfterChunk', 0, 2],
        ['row', 5, 5, 5],
        ['row', 7, 7, 5],
        ['AfterChunk', 1, 2],
        ['AfterSheet', 0],
    ]);
})->with('source row drivers');

it('gives a ToModel import the source row and offset', function (string $driver) {
    //                                row: 2    3     4    5     6    7
    $fixture = LoggedFixture::xlsx(['A', null, 'B', null, 'C', 'D']);
    $import = new class(chunkSize: 2) extends ChunkedModelImport implements SkipsEmptyRows {};

    ReaderDrivers::import($driver, $import, $fixture->path());

    // ['model', id, row number, chunk offset]
    expect(array_map(fn (array $entry): array => array_slice($entry, 0, 3), $import->entries('model')))->toBe([
        [2, 2, 2],
        [4, 4, 2],
        [6, 6, 6],
        [7, 7, 6],
    ])->and($import->chunkStartRows)->toBe([2, 6]);
})->with('source row drivers');

// --- rust falling back to OpenSpout (plan item 25) ---

it('numbers rows in arrival order when the rust driver hands a non-UTF-8 CSV to OpenSpout', function () {
    $options = new CsvOptions(ENCODING: 'ISO-8859-1');
    //                             line: 2    3     4    5     6
    $fixture = LoggedFixture::csv(['A', null, 'B', null, 'C']);

    $probe = ReaderDrivers::open('rust', $fixture->path(), $options);
    expect($probe)->toBeInstanceOf(RustReader::class)
        ->and($probe->usesFallback())->toBeTrue();
    $probe->close();

    $viaRust = ReaderDrivers::import('rust', new ChunkedCollectionImport, $fixture->path(), nativeOptions: $options);
    $viaOpenSpout = ReaderDrivers::import('openspout', new ChunkedCollectionImport, $fixture->path(), nativeOptions: $options);

    expect($viaRust->log)->toBe($viaOpenSpout->log)
        ->and($viaRust->entries('collection'))->toBe([
            [2, [0 => 2, 1 => 4]],
            [4, [0 => 6]],
        ]);
});

it('numbers rows in arrival order when the rust driver hands an .ods file to OpenSpout', function () {
    $path = sys_get_temp_dir().'/'.uniqid('chunk_offset_', true).'.ods';
    $writer = new OpenSpoutWriter('ods');
    $writer->openToFile($path);
    $writer->addSheet('Sheet');
    foreach ([['id', 'name'], [2, 'A'], [], [4, 'B'], [5, 'C']] as $row) {
        $writer->addRow($row);
    }
    $writer->close();

    try {
        $probe = ReaderDrivers::open('rust', $path);
        expect($probe->usesFallback())->toBeTrue();
        $probe->close();

        $viaRust = ReaderDrivers::import('rust', new ChunkedCollectionImport, $path);
        $viaOpenSpout = ReaderDrivers::import('openspout', new ChunkedCollectionImport, $path);

        expect($viaRust->log)->toBe($viaOpenSpout->log)
            ->and($viaRust->entries('AfterChunk'))->toBe([[0, 2], [1, 1]])
            ->and($viaRust->chunkStartRows)->toBe([2, 4]);
    } finally {
        @unlink($path);
    }
});
