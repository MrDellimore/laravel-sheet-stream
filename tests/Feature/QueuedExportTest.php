<?php

use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Jobs\QueuedExportJob;
use MrDellimore\SheetStream\Tests\Fixtures\QueuedCollectionExport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleCollectionExport;

it('dispatches a QueuedExportJob when using queueExport()', function () {
    Bus::fake();

    $export = new QueuedCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->queueExport($export, 'exports/queued.xlsx');

    expect($result)->toBeInstanceOf(PendingDispatch::class);

    // PendingDispatch dispatches on __destruct; trigger it.
    unset($result);

    Bus::assertDispatched(QueuedExportJob::class);
});

it('auto-detects ShouldQueue on store() and returns PendingDispatch', function () {
    Bus::fake();

    $export = new QueuedCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/queued.xlsx');

    expect($result)->toBeInstanceOf(PendingDispatch::class);

    unset($result);

    Bus::assertDispatched(QueuedExportJob::class);
});

it('stores synchronously when ShouldQueue is not implemented', function () {
    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/sync.xlsx');

    expect($result)->toBeTrue()
        ->and(Storage::exists('exports/sync.xlsx'))->toBeTrue();

    Storage::delete('exports/sync.xlsx');
});

it('writes the file correctly when QueuedExportJob is handled', function () {
    $export = new QueuedCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
        ['Name' => 'Bob', 'Email' => 'bob@example.com'],
    ]);

    $job = new QueuedExportJob(
        export: $export,
        storagePath: 'exports/queued_handled.xlsx',
        extension: 'xlsx',
    );

    $job->handle();

    expect(Storage::exists('exports/queued_handled.xlsx'))->toBeTrue();

    // Round-trip: verify the file content.
    $import = new SimpleArrayImport;
    app('sheet-stream')->import($import, Storage::path('exports/queued_handled.xlsx'));

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com']);

    Storage::delete('exports/queued_handled.xlsx');
});
