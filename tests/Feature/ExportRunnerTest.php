<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleCollectionExport;

it('exports a collection with headings and round-trips back correctly', function () {
    $rows = [
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
        ['Name' => 'Bob', 'Email' => 'bob@example.com'],
    ];

    $export = new SimpleCollectionExport($rows);

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('export_test_', true).'.xlsx';

    try {
        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run($export, $writer);
        $writer->close();

        expect(file_exists($tmp))->toBeTrue()
            ->and(filesize($tmp))->toBeGreaterThan(0);

        // Round-trip: re-import and verify the data survived.
        $import = new SimpleArrayImport;
        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->result)->toHaveCount(2)
            ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
            ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);
    } finally {
        @unlink($tmp);
    }
});
