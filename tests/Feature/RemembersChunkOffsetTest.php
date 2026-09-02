<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\ChunkOffsetTrackingImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('stubs', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });
});

it('exposes chunk offset that resets at each batch boundary', function () {
    // batchSize = 2, so chunks: [rows 2-3], [rows 4-5], [row 6]
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],              // row 1 (heading)
        ['Alice', 'alice@test.com'],     // row 2 → chunk 0, offset 2
        ['Bob', 'bob@test.com'],         // row 3 → chunk 0, offset 2
        ['Charlie', 'charlie@test.com'], // row 4 → chunk 1, offset 4
        ['Diana', 'diana@test.com'],     // row 5 → chunk 1, offset 4
        ['Eve', 'eve@test.com'],         // row 6 → chunk 2, offset 6
    ]);

    $import = new ChunkOffsetTrackingImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    // Each row should see the offset of its chunk's first row
    expect($import->observedChunkOffsets)->toBe([2, 2, 4, 4, 6]);
});

it('sets chunk offset to first row when all rows fit in one batch', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@test.com'],
        ['Bob', 'bob@test.com'],
    ]);

    $import = new ChunkOffsetTrackingImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    // Both rows in same chunk, offset is row 2
    expect($import->observedChunkOffsets)->toBe([2, 2]);
});
