<?php

use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Tests\Fixtures\NoBomCsvExport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleCollectionExport;

it('exports a CSV without BOM when WithWriterOptions disables it', function () {
    $export = new NoBomCsvExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/no_bom.csv');

    expect($result)->toBeTrue();

    $content = Storage::get('exports/no_bom.csv');

    // UTF-8 BOM is \xEF\xBB\xBF — it should NOT be present.
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeFalse()
        ->and($content)->toContain('Alice');

    Storage::delete('exports/no_bom.csv');
});

it('exports a CSV with BOM by default', function () {
    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/with_bom.csv');

    expect($result)->toBeTrue();

    $content = Storage::get('exports/with_bom.csv');

    // Default OpenSpout CSV writer adds BOM.
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue();

    Storage::delete('exports/with_bom.csv');
});
