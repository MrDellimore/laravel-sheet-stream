<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

it('round-trips DateTimeInterface values through export and import', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'date_export_').'.xlsx';

    try {
        // Export with dates via our writer (which applies date format styles)
        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        $writer->addSheet(null);
        $writer->addRow(['Name', 'Date']);
        $writer->addRow(['Alice', new DateTimeImmutable('2025-06-15')]);
        $writer->addRow(['Bob', new DateTimeImmutable('2025-12-25 14:30:00')]);
        $writer->close();

        // Re-import and verify dates come back as DateTimeInterface
        $import = new SimpleArrayImport;
        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->result)->toHaveCount(2)
            ->and($import->result[0]['name'])->toBe('Alice')
            ->and($import->result[0]['date'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($import->result[0]['date']->format('Y-m-d'))->toBe('2025-06-15')
            ->and($import->result[1]['name'])->toBe('Bob')
            ->and($import->result[1]['date'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($import->result[1]['date']->format('Y-m-d H:i:s'))->toBe('2025-12-25 14:30:00');
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
});

it('returns date cells as serial numbers when written without format (legacy OpenSpout behavior)', function () {
    // When using raw OpenSpout writer without our wrapper, dates lose their format
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

        // Without our writer's date format, the value is an Excel serial number
        expect($import->result)->toHaveCount(1)
            ->and($import->result[0]['name'])->toBe('Alice')
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

it('applies custom date format from config', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'date_fmt_').'.xlsx';

    try {
        // Use a custom format
        $writer = new OpenSpoutWriter('xlsx', null, dateFormat: 'dd/mm/yyyy');
        $writer->openToFile($tmp);
        $writer->addSheet(null);
        $writer->addRow(['Name', 'Date']);
        $writer->addRow(['Alice', new DateTimeImmutable('2025-06-15')]);
        $writer->close();

        // Re-import — the date should still round-trip as DateTimeInterface
        // because the reader interprets the format code as a date format
        $import = new SimpleArrayImport;
        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->result[0]['date'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($import->result[0]['date']->format('Y-m-d'))->toBe('2025-06-15');
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
});

it('does not coerce regular numbers into dates', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'num_test_').'.xlsx';

    try {
        // Write a mix of numbers and dates
        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        $writer->addSheet(null);
        $writer->addRow(['Score', 'Date', 'Qty']);
        $writer->addRow([45823, new DateTimeImmutable('2025-06-15'), 100]);
        $writer->close();

        $import = new SimpleArrayImport;
        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        (new ImportRunner)->run($import, $reader);
        $reader->close();

        // 45823 is a plain number, NOT a date (even though it equals the Excel serial for 2025-06-15)
        expect($import->result[0]['score'])->toBe(45823)
            ->and($import->result[0]['score'])->toBeInt()
            ->and($import->result[0]['date'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($import->result[0]['date']->format('Y-m-d'))->toBe('2025-06-15')
            ->and($import->result[0]['qty'])->toBe(100);
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
});

it('handles mixed date and non-date rows in exports via ExportRunner', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'mixed_export_').'.xlsx';

    try {
        $export = new class implements
            \MrDellimore\SheetStream\Concerns\FromCollection,
            \MrDellimore\SheetStream\Concerns\WithHeadings,
            \MrDellimore\SheetStream\Concerns\WithMapping
        {
            public function collection(): \Illuminate\Support\Collection
            {
                return new \Illuminate\Support\Collection([
                    ['name' => 'Alice', 'joined' => new DateTimeImmutable('2025-01-15'), 'score' => 95],
                    ['name' => 'Bob', 'joined' => new DateTimeImmutable('2025-03-20 09:30:00'), 'score' => 87],
                ]);
            }

            public function headings(): array
            {
                return ['Name', 'Joined', 'Score'];
            }

            public function map(mixed $row): array
            {
                return [$row['name'], $row['joined'], $row['score']];
            }
        };

        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run($export, $writer);
        $writer->close();

        // Round-trip
        $import = new SimpleArrayImport;
        $reader = new OpenSpoutReader;
        $reader->open($tmp);
        (new ImportRunner)->run($import, $reader);
        $reader->close();

        expect($import->result)->toHaveCount(2)
            ->and($import->result[0]['name'])->toBe('Alice')
            ->and($import->result[0]['joined'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($import->result[0]['joined']->format('Y-m-d'))->toBe('2025-01-15')
            ->and($import->result[0]['score'])->toBe(95)
            ->and($import->result[1]['name'])->toBe('Bob')
            ->and($import->result[1]['joined'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($import->result[1]['joined']->format('Y-m-d H:i:s'))->toBe('2025-03-20 09:30:00')
            ->and($import->result[1]['score'])->toBe(87);
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
});
