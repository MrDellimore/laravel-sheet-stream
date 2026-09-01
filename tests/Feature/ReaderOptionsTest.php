<?php

use MrDellimore\SheetStream\Tests\Fixtures\SemicolonCsvImport;

it('imports a CSV with a custom semicolon delimiter via WithReaderOptions', function () {
    // Write a semicolon-delimited CSV manually (can't use CsvFixtureBuilder which uses commas).
    $path = tempnam(sys_get_temp_dir(), 'sheet_fixture_').'.csv';
    file_put_contents($path, "Name;Email\nAlice;alice@example.com\nBob;bob@example.com\n");

    try {
        $import = new SemicolonCsvImport;
        app('sheet-stream')->import($import, $path);

        expect($import->result)->toHaveCount(2)
            ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
            ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);
    } finally {
        @unlink($path);
    }
});
