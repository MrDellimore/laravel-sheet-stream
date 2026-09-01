<?php

use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Bus;
use MrDellimore\SheetStream\Jobs\QueuedImportJob;
use MrDellimore\SheetStream\Tests\Fixtures\QueuedArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

it('dispatches a QueuedImportJob when using queueImport()', function () {
    Bus::fake();

    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);

    $import = new QueuedArrayImport;
    $result = app('sheet-stream')->queueImport($import, $fixture->path());

    expect($result)->toBeInstanceOf(PendingDispatch::class);

    // PendingDispatch dispatches on __destruct; trigger it.
    unset($result);

    Bus::assertDispatched(QueuedImportJob::class);
});

it('auto-detects ShouldQueue on import() and returns PendingDispatch', function () {
    Bus::fake();

    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);

    $import = new QueuedArrayImport;
    $result = app('sheet-stream')->import($import, $fixture->path());

    expect($result)->toBeInstanceOf(PendingDispatch::class);

    unset($result);

    Bus::assertDispatched(QueuedImportJob::class);
});

it('runs import synchronously when ShouldQueue is not implemented', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);

    $import = new SimpleArrayImport;
    $result = app('sheet-stream')->import($import, $fixture->path());

    expect($result)->toBeNull()
        ->and($import->result)->toHaveCount(1);
});

it('executes the import correctly when QueuedImportJob is handled', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
    ]);

    $import = new QueuedArrayImport;
    $job = new QueuedImportJob(
        import: $import,
        filePath: $fixture->path(),
        readerOptions: [
            'dates' => ['coerce' => true, 'timezone' => null],
        ],
    );

    $job->handle();

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);
});
