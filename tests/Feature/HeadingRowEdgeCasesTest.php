<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SkipsEmptyRowsImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

it('pads missing columns with null when row is shorter than headings', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email', 'Phone'],
        ['Alice', 'alice@example.com'],  // missing Phone
    ]);

    $import = new SimpleArrayImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(1)
        ->and($import->result[0])->toBe([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'phone' => null,
        ]);
});

it('truncates extra columns when row is longer than headings', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com', 'extra-value', 'another-extra'],
    ]);

    $import = new SimpleArrayImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(1)
        ->and($import->result[0])->toBe([
            'name' => 'Alice',
            'email' => 'alice@example.com',
        ]);
});

it('normalizes heading keys to lowercase trimmed strings', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['  First Name ', ' EMAIL '],
        ['Alice', 'alice@example.com'],
    ]);

    $import = new SimpleArrayImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect(array_keys($import->result[0]))->toBe(['first name', 'email']);
});

it('imports without heading row when WithHeadingRow is not used', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
    ]);

    // SkipsEmptyRowsImport implements ToArray but NOT WithHeadingRow
    $import = new SkipsEmptyRowsImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toBe(['Alice', 'alice@example.com'])
        ->and($import->result[1])->toBe(['Bob', 'bob@example.com']);
});
