<?php

declare(strict_types=1);

use MrDellimore\SheetStream\Engine\Rust\RustReader;

/*
 * The rust drivers only run against rusty_sheet versions they were tested with; these
 * checks don't need the extension loaded.
 */

it('accepts extension versions inside the supported range', function (string $version) {
    expect(RustReader::supportsVersion($version))->toBeTrue();
})->with(['0.1.0', '0.1.1', '0.1.27']);

it('rejects extension versions outside the supported range', function (string $version) {
    expect(RustReader::supportsVersion($version))->toBeFalse();
})->with(['0.0.9', '0.2.0', '0.10.0', '1.0.0']);

it('keeps the version bounds in step with the constraint', function () {
    expect(RustReader::SUPPORTED_VERSIONS)->toBe('^0.1')
        ->and(RustReader::MIN_VERSION)->toBe('0.1.0')
        ->and(RustReader::MAX_VERSION)->toBe('0.2.0');
});
