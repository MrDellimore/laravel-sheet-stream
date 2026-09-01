<?php

use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleCollectionExport;

it('uses the configured temp_path for store()', function () {
    $customTempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sheet_stream_test_'.uniqid();
    mkdir($customTempDir, 0755, true);

    config(['sheet-stream.temp_path' => $customTempDir]);

    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/temp_path_test.xlsx');

    expect($result)->toBeTrue()
        ->and(Storage::exists('exports/temp_path_test.xlsx'))->toBeTrue();

    // Temp file should have been cleaned up — custom dir should be empty.
    expect(glob($customTempDir.DIRECTORY_SEPARATOR.'*'))->toBeEmpty();

    // Clean up
    Storage::delete('exports/temp_path_test.xlsx');
    rmdir($customTempDir);
});

it('uses the configured temp_path for download()', function () {
    $customTempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sheet_stream_test_'.uniqid();
    mkdir($customTempDir, 0755, true);

    config(['sheet-stream.temp_path' => $customTempDir]);

    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $response = app('sheet-stream')->download($export, 'test.xlsx');

    // Stream the response to trigger temp file creation and cleanup.
    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->not->toBeEmpty();

    // Temp file should have been cleaned up.
    expect(glob($customTempDir.DIRECTORY_SEPARATOR.'*'))->toBeEmpty();

    rmdir($customTempDir);
});

it('falls back to sys_get_temp_dir when temp_path is null', function () {
    config(['sheet-stream.temp_path' => null]);

    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $result = app('sheet-stream')->store($export, 'exports/fallback_test.xlsx');

    expect($result)->toBeTrue();

    Storage::delete('exports/fallback_test.xlsx');
});
