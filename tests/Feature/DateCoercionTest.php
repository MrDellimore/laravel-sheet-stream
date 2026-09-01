<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

it('returns date cells as Excel serial numbers (date coercion is Phase 7)', function () {
    // OpenSpout's reader returns date-formatted cells as numeric serial values.
    // Date coercion (converting these back to DateTimeInterface) is planned for Phase 7.
    $path = tempnam(sys_get_temp_dir(), 'date_fixture_').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);

    $writer->addRow(new Row([
        Cell::fromValue('Name'),
        Cell::fromValue('Date'),
    ]));

    $date = new DateTimeImmutable('2025-06-15');
    $writer->addRow(new Row([
        Cell::fromValue('Alice'),
        Cell::fromValue($date),
    ]));

    $writer->close();

    try {
        $import = new SimpleArrayImport;
        $reader = new OpenSpoutReader;
        $reader->open($path);

        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->result)->toHaveCount(1)
            ->and($import->result[0]['name'])->toBe('Alice')
            // Excel serial date for 2025-06-15 is 45823
            ->and($import->result[0]['date'])->toBeInt()
            ->and($import->result[0]['date'])->toBe(45823);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('preserves numeric values without coercing to strings', function () {
    $path = tempnam(sys_get_temp_dir(), 'num_fixture_').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);

    $writer->addRow(new Row([
        Cell::fromValue('Label'),
        Cell::fromValue('Amount'),
        Cell::fromValue('Rate'),
    ]));

    $writer->addRow(new Row([
        Cell::fromValue('Invoice'),
        Cell::fromValue(1500),
        Cell::fromValue(0.075),
    ]));

    $writer->close();

    try {
        $import = new SimpleArrayImport;
        $reader = new OpenSpoutReader;
        $reader->open($path);

        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->result[0]['amount'])->toBe(1500)
            ->and($import->result[0]['rate'])->toBe(0.075);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
