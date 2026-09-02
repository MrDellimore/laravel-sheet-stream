<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\RowNumberTrackingImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('stubs', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });
});

it('exposes absolute row numbers via RemembersRowNumber in ToModel imports', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],       // row 1 (heading)
        ['Alice', 'alice@test.com'],   // row 2
        ['Bob', 'bob@test.com'],       // row 3
        ['Charlie', 'charlie@test.com'], // row 4
        ['Diana', 'diana@test.com'],   // row 5
        ['Eve', 'eve@test.com'],       // row 6
    ]);

    $import = new RowNumberTrackingImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    // Row numbers should be absolute (1-based, heading row counted)
    expect($import->observedRowNumbers)->toBe([2, 3, 4, 5, 6]);
});

it('maintains correct row numbers across batch boundaries', function () {
    // batchSize() = 2, so batches flush at rows 2-3, 4-5, then 6 as remainder
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['A', 'a@test.com'],
        ['B', 'b@test.com'],
        ['C', 'c@test.com'],
        ['D', 'd@test.com'],
        ['E', 'e@test.com'],
    ]);

    $import = new RowNumberTrackingImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    // All 5 data rows should have continuous absolute row numbers
    expect($import->observedRowNumbers)->toBe([2, 3, 4, 5, 6]);
});
