<?php

use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Engine\PhpSpreadsheet\PhpSpreadsheetReader;
use MrDellimore\SheetStream\Engine\PhpSpreadsheet\PhpSpreadsheetWriter;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;

it('creates a PhpSpreadsheet reader via the factory', function () {
    $reader = EngineFactory::reader('phpspreadsheet');

    expect($reader)->toBeInstanceOf(PhpSpreadsheetReader::class);
});

it('creates a PhpSpreadsheet writer via the factory', function () {
    $writer = EngineFactory::writer('phpspreadsheet', 'xlsx');

    expect($writer)->toBeInstanceOf(PhpSpreadsheetWriter::class);
});

it('reads an xlsx file with the PhpSpreadsheet driver', function () {
    // Write a fixture using PhpSpreadsheet writer
    $tmp = tempnam(sys_get_temp_dir(), 'pss_test_').'.xlsx';
    $writer = new PhpSpreadsheetWriter('xlsx');
    $writer->openToFile($tmp);
    $writer->addSheet(null);
    $writer->addRow(['Name', 'Email']);
    $writer->addRow(['Alice', 'alice@example.com']);
    $writer->addRow(['Bob', 'bob@example.com']);
    $writer->close();

    // Read it back with PhpSpreadsheet reader
    $reader = new PhpSpreadsheetReader;
    $reader->open($tmp);

    $import = new SimpleArrayImport;
    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);

    @unlink($tmp);
});

it('reads a legacy .xls file with the PhpSpreadsheet driver', function () {
    // Write a .xls fixture
    $tmp = tempnam(sys_get_temp_dir(), 'pss_test_').'.xls';
    $writer = new PhpSpreadsheetWriter('xls');
    $writer->openToFile($tmp);
    $writer->addSheet(null);
    $writer->addRow(['Name', 'Email']);
    $writer->addRow(['Charlie', 'charlie@example.com']);
    $writer->close();

    // Read it back
    $reader = new PhpSpreadsheetReader;
    $reader->open($tmp);

    $import = new SimpleArrayImport;
    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(1)
        ->and($import->result[0])->toMatchArray(['name' => 'Charlie', 'email' => 'charlie@example.com']);

    @unlink($tmp);
});

it('round-trips an export through PhpSpreadsheet write then read', function () {
    $rows = [
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
        ['Name' => 'Bob', 'Email' => 'bob@example.com'],
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'pss_rt_').'.xlsx';
    $writer = new PhpSpreadsheetWriter('xlsx');
    $writer->openToFile($tmp);
    $writer->addSheet(null);
    $writer->addRow(['Name', 'Email']);

    foreach ($rows as $row) {
        $writer->addRow(array_values($row));
    }

    $writer->close();

    // Read back
    $reader = new PhpSpreadsheetReader;
    $reader->open($tmp);

    $import = new SimpleArrayImport;
    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);

    @unlink($tmp);
});

it('writes multiple sheets with PhpSpreadsheet driver', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'pss_ms_').'.xlsx';
    $writer = new PhpSpreadsheetWriter('xlsx');
    $writer->openToFile($tmp);

    $writer->addSheet('Users');
    $writer->addRow(['Name']);
    $writer->addRow(['Alice']);

    $writer->addSheet('Orders');
    $writer->addRow(['Product']);
    $writer->addRow(['Widget']);

    $writer->close();

    // Read back and verify both sheets
    $reader = new PhpSpreadsheetReader;
    $reader->open($tmp);

    $sheets = [];

    foreach ($reader->sheets() as $sheet) {
        $rows = [];

        foreach ($sheet->rows() as $row) {
            $rows[] = $row;
        }

        $sheets[$sheet->name()] = $rows;
    }

    $reader->close();

    expect($sheets)->toHaveKey('Users')
        ->and($sheets)->toHaveKey('Orders')
        ->and($sheets['Users'])->toHaveCount(2)
        ->and($sheets['Users'][0])->toBe(['Name'])
        ->and($sheets['Users'][1])->toBe(['Alice'])
        ->and($sheets['Orders'][0])->toBe(['Product'])
        ->and($sheets['Orders'][1])->toBe(['Widget']);

    @unlink($tmp);
});

it('imports via the manager with phpspreadsheet config driver', function () {
    // Write a fixture
    $tmp = tempnam(sys_get_temp_dir(), 'pss_mgr_').'.xlsx';
    $writer = new PhpSpreadsheetWriter('xlsx');
    $writer->openToFile($tmp);
    $writer->addSheet(null);
    $writer->addRow(['Name', 'Email']);
    $writer->addRow(['Dana', 'dana@example.com']);
    $writer->close();

    // Override config to use phpspreadsheet
    config(['sheet-stream.default_reader' => 'phpspreadsheet']);

    $import = new SimpleArrayImport;
    SheetStream::import($import, $tmp);

    expect($import->result)->toHaveCount(1)
        ->and($import->result[0])->toMatchArray(['name' => 'Dana', 'email' => 'dana@example.com']);

    // Reset config
    config(['sheet-stream.default_reader' => 'openspout']);
    @unlink($tmp);
});
