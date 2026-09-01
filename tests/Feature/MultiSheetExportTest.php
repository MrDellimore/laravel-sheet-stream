<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetExport;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetImport;

it('exports multiple sheets with titles and round-trips correctly', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'export_test_').'.xlsx';

    try {
        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run(new MultiSheetExport, $writer);
        $writer->close();

        // Round-trip: re-import with multi-sheet import
        $import = new MultiSheetImport;
        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->usersImport->result)->toHaveCount(1)
            ->and($import->usersImport->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
            ->and($import->ordersImport->result)->toHaveCount(1)
            ->and($import->ordersImport->result[0])->toMatchArray(['product' => 'Widget', 'qty' => 5]);
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
});
