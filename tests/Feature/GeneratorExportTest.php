<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\GeneratorExport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;

it('exports rows from a generator and round-trips correctly', function () {
    $rows = [
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';

    try {
        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run(new GeneratorExport($rows), $writer);
        $writer->close();

        $import = new SimpleArrayImport;
        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->result)->toHaveCount(2)
            ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
            ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
});
