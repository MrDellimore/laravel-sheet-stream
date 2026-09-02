<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeImport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\EventTrackingImport;
use MrDellimore\SheetStream\Tests\Fixtures\RegistersEventListenersImport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

it('fires BeforeImport, BeforeSheet, AfterSheet, AfterImport in correct order', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
    ]);

    $import = new EventTrackingImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    $classes = array_column($import->firedEvents, 'class');

    expect($classes)->toBe([
        BeforeImport::class,
        BeforeSheet::class,
        AfterChunk::class,
        AfterSheet::class,
        AfterImport::class,
    ]);

    // Verify data still imported correctly
    expect($import->result)->toHaveCount(2);
});

it('fires AfterChunk with correct chunk numbers for small batch size', function () {
    $rows = [['Name', 'Email']];
    for ($i = 1; $i <= 5; $i++) {
        $rows[] = ["User{$i}", "user{$i}@example.com"];
    }

    $fixture = (new XlsxFixtureBuilder)->write($rows);

    $import = new EventTrackingImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner(defaultBatchSize: 2))->run($import, $reader);
    $reader->close();

    $chunks = array_filter($import->firedEvents, fn ($e) => $e['class'] === AfterChunk::class);
    $chunks = array_values($chunks);

    // 5 rows with batch size 2 → chunks: 0 (2 rows), 1 (2 rows), 2 (1 row)
    expect($chunks)->toHaveCount(3)->and($chunks[0]['event']->chunkNumber)->toBe(0)->and($chunks[0]['event']->rowsInChunk)->toBe(2)->and($chunks[1]['event']->chunkNumber)->toBe(1)->and($chunks[1]['event']->rowsInChunk)->toBe(2)->and($chunks[2]['event']->chunkNumber)->toBe(2)->and($chunks[2]['event']->rowsInChunk)->toBe(1);
});

it('does not crash when import does not implement WithEvents', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);

    $import = new SimpleArrayImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(1);
});

it('passes correct import object in event properties', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);

    $import = new EventTrackingImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    $beforeImport = $import->firedEvents[0]['event'];
    expect($beforeImport)->toBeInstanceOf(BeforeImport::class)->and($beforeImport->import)->toBe($import);

    $beforeSheet = $import->firedEvents[1]['event'];
    expect($beforeSheet)->toBeInstanceOf(BeforeSheet::class)->and($beforeSheet->sheetIndex)->toBe(0);
});

it('works with RegistersEventListeners trait', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);

    $import = new RegistersEventListenersImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->firedEvents)->toBe([
        BeforeImport::class,
        AfterImport::class,
    ])->and($import->result)->toHaveCount(1);
});
