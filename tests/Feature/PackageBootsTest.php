<?php

declare(strict_types=1);

use MrDellimore\SheetStream\SheetStreamManager;

it('boots the service provider and resolves the facade', function () {
    $manager = app('sheet-stream');

    expect($manager)->toBeInstanceOf(SheetStreamManager::class);
});

it('loads the default config values', function () {
    // SHEET_STREAM_TEST_READER (see TestCase) swaps the reader for whole-suite runs.
    expect(config('sheet-stream.default_reader'))->toBe(getenv('SHEET_STREAM_TEST_READER') ?: 'openspout')
        ->and(config('sheet-stream.batch_size'))->toBe(1000);
});
