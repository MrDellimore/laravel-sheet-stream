<?php

use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleCollectionExport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('imports via the manager facade', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);

    $import = new SimpleArrayImport;
    app('sheet-stream')->import($import, $fixture->path());

    expect($import->result)->toHaveCount(1)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com']);
});

it('returns a StreamedResponse from download()', function () {
    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $response = app('sheet-stream')->download($export, 'test.xlsx');

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Disposition'))->toContain('test.xlsx')
        ->and($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('stores an export to the local filesystem', function () {
    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/test.xlsx');

    expect($result)->toBeTrue()
        ->and(Storage::exists('exports/test.xlsx'))->toBeTrue();

    // Clean up
    Storage::delete('exports/test.xlsx');
});

it('stores a CSV export correctly', function () {
    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/test.csv');

    expect($result)->toBeTrue()
        ->and(Storage::exists('exports/test.csv'))->toBeTrue();

    // Round-trip: read back the CSV
    $import = new SimpleArrayImport;
    $storagePath = Storage::path('exports/test.csv');
    app('sheet-stream')->import($import, $storagePath);

    expect($import->result)->toHaveCount(1)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com']);

    Storage::delete('exports/test.csv');
});
