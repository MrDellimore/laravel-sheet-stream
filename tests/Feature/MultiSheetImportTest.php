<?php

declare(strict_types=1);

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetImport;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetXlsxFixtureBuilder;

it('routes multiple sheets to the correct sub-imports', function () {
    $fixture = (new MultiSheetXlsxFixtureBuilder)->write([
        'Users' => [
            ['Name', 'Email'],
            ['Alice', 'alice@example.com'],
        ],
        'Orders' => [
            ['Product', 'Qty'],
            ['Widget', '5'],
        ],
    ]);

    $import = new MultiSheetImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->usersImport->result)->toHaveCount(1)
        ->and($import->usersImport->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->ordersImport->result)->toHaveCount(1)
        ->and($import->ordersImport->result[0])->toMatchArray(['product' => 'Widget', 'qty' => '5']);
});
