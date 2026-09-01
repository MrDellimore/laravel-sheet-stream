<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\BatchModelImport;
use MrDellimore\SheetStream\Tests\Fixtures\StubModel;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    Schema::create('stubs', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email');
    });
});

it('imports rows via ToModel with batch inserts', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
        ['Charlie', 'charlie@example.com'],
    ]);

    $import = new BatchModelImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect(StubModel::count())->toBe(3)
        ->and(StubModel::pluck('name')->all())->toBe(['Alice', 'Bob', 'Charlie']);
});

it('respects batch size boundaries', function () {
    // BatchModelImport has batchSize() = 2, so with 5 rows we get 3 flushes (2+2+1)
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['A', 'a@test.com'],
        ['B', 'b@test.com'],
        ['C', 'c@test.com'],
        ['D', 'd@test.com'],
        ['E', 'e@test.com'],
    ]);

    $import = new BatchModelImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect(StubModel::count())->toBe(5);
});
