<?php

declare(strict_types=1);

use MrDellimore\SheetStream\SheetStreamManager;

it('boots the service provider and resolves the facade', function () {
    $manager = app('sheet-stream');

    expect($manager)->toBeInstanceOf(SheetStreamManager::class);
});

it('loads the default config values', function () {
    expect(config('sheet-stream.default_reader'))->toBe('openspout')
        ->and(config('sheet-stream.batch_size'))->toBe(1000);
});
