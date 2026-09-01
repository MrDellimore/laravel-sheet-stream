<?php

use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleCollectionExport;
use MrDellimore\SheetStream\Tests\Fixtures\StyledExport;

it('exports an XLSX with styled headings and data rows', function () {
    $export = new StyledExport([
        ['Name' => 'Alice', 'Amount' => 1234.56],
        ['Name' => 'Bob', 'Amount' => 789.00],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/styled.xlsx');

    expect($result)->toBeTrue()
        ->and(Storage::exists('exports/styled.xlsx'))->toBeTrue();

    // Round-trip: verify the file is valid and readable.
    $import = new SimpleArrayImport;
    app('sheet-stream')->import($import, Storage::path('exports/styled.xlsx'));

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0]['name'])->toBe('Alice');

    Storage::delete('exports/styled.xlsx');
});

it('exports a CSV with style concerns without errors', function () {
    $export = new StyledExport([
        ['Name' => 'Alice', 'Amount' => 1234.56],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/styled.csv');

    expect($result)->toBeTrue()
        ->and(Storage::exists('exports/styled.csv'))->toBeTrue();

    Storage::delete('exports/styled.csv');
});

it('exports without style concerns (backward compatibility)', function () {
    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/unstyled.xlsx');

    expect($result)->toBeTrue()
        ->and(Storage::exists('exports/unstyled.xlsx'))->toBeTrue();

    Storage::delete('exports/unstyled.xlsx');
});
