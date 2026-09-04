<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\MappedExport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;

it('applies WithMapping transformation during export', function () {
    $rows = [
        ['name' => 'Alice', 'email' => 'Alice@Example.COM'],
        ['name' => 'Bob', 'email' => 'Bob@Example.COM'],
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';

    try {
        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run(new MappedExport($rows), $writer);
        $writer->close();

        // Round-trip: verify the mapping was applied
        $import = new SimpleArrayImport;
        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->result)->toHaveCount(2)
            ->and($import->result[0])->toMatchArray([
                'full_name' => 'ALICE',
                'email_address' => 'alice@example.com',
            ])
            ->and($import->result[1])->toMatchArray([
                'full_name' => 'BOB',
                'email_address' => 'bob@example.com',
            ]);
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
});
