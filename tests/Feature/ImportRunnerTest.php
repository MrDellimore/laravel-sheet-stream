<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SkipsEmptyRowsImport;
use MrDellimore\SheetStream\Tests\Fixtures\ValidationImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

it('imports rows into ToArray with heading row mapping', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
    ]);

    $import = new SimpleArrayImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('skips empty rows when SkipsEmptyRows is implemented', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Alice'],
        [null],
        [''],
        ['Bob'],
    ]);

    $import = new SkipsEmptyRowsImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0][0])->toBe('Alice')
        ->and($import->result[1][0])->toBe('Bob');
});

it('collects all validation failures with row numbers before calling onFailure', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['name', 'email'],              // row 1 (heading)
        ['Alice', 'alice@example.com'], // row 2 — valid
        ['', 'not-an-email'],           // row 3 — two failures
        ['Bob', 'also-not-an-email'],   // row 4 — one failure
    ]);

    $import = new ValidationImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    // Valid rows make it through, invalid rows are skipped
    expect($import->result)->toHaveCount(1)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com']);

    // All failures collected — not just the first one
    expect($import->failures)->toHaveCount(2);

    // Each failure carries the 1-based spreadsheet row number
    $first = $import->failures->first();
    expect($first->row())->toBe(3)
        ->and($first->errors())->toHaveKey('name')
        ->and($first->errors())->toHaveKey('email')
        ->and($first->values())->toMatchArray(['name' => '', 'email' => 'not-an-email']);

    $second = $import->failures->last();
    expect($second->row())->toBe(4)
        ->and($second->errors())->toHaveKey('email')
        ->and($second->values())->toMatchArray(['name' => 'Bob', 'email' => 'also-not-an-email']);
});

it('throws on unsupported .xls extension', function () {
    $reader = new OpenSpoutReader;
    $reader->open('/fake/path/file.xls');
})->throws(UnsupportedByEngine::class);
