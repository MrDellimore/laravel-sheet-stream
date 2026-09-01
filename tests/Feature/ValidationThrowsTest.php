<?php

use Illuminate\Validation\ValidationException;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\StrictValidationImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

it('throws ValidationException on first failure when SkipsOnFailure is not used', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name', 'email'],
        ['Alice', 'alice@example.com'],
        ['', 'not-an-email'],
    ]);

    $import = new StrictValidationImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    try {
        (new ImportRunner)->run($import, $reader);
    } finally {
        $reader->close();
    }
})->throws(ValidationException::class);
