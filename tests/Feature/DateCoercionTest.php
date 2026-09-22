<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithMapping;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Engine\PhpSpreadsheet\PhpSpreadsheetReader;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Jobs\StagingChunkProcessorJob;
use MrDellimore\SheetStream\Jobs\StagingProducerJob;
use MrDellimore\SheetStream\Staging\FileStagingStore;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Support\CellNormalizer;
use MrDellimore\SheetStream\Tests\Fixtures\FormatDataArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\StagingArrayImport;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel serials used throughout: 2025-06-15 = 45823, 2025-12-25 = 46016.
 */
const SERIAL_2025_06_15 = 45823;
const SERIAL_2025_12_25 = 46016;

/**
 * Write an .xlsx whose Date column carries a real Excel date number format,
 * the way Excel itself saves a date cell.
 */
function dateFixture(string $dateFormat = 'yyyy-mm-dd'): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'date_fixture_').'.xlsx';

    $writer = new OpenSpoutWriter('xlsx', null, dateFormat: $dateFormat);
    $writer->openToFile($tmp);
    $writer->addSheet(null);
    $writer->addRow(['Name', 'Date']);
    $writer->addRow(['Alice', new DateTimeImmutable('2025-06-15')]);
    $writer->addRow(['Bob', new DateTimeImmutable('2025-12-25 14:30:00')]);
    $writer->close();

    return $tmp;
}

function readWith(object $reader, string $path): array
{
    $import = new SimpleArrayImport;
    $reader->open($path);

    try {
        (new ImportRunner)->run($import, $reader);
    } finally {
        $reader->close();
    }

    return $import->result;
}

it('returns date-styled cells as Excel serial numbers by default, like Laravel Excel', function () {
    $tmp = dateFixture();

    try {
        $rows = readWith(new OpenSpoutReader, $tmp);

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['name'])->toBe('Alice')
            ->and($rows[0]['date'])->toBeInt()
            ->and($rows[0]['date'])->toBe(SERIAL_2025_06_15)
            ->and($rows[1]['name'])->toBe('Bob')
            ->and($rows[1]['date'])->toBeFloat()
            ->and(round($rows[1]['date'], 6))->toBe(round(SERIAL_2025_12_25 + 14.5 / 24, 6));
    } finally {
        @unlink($tmp);
    }
});

it('returns DateTimeImmutable objects when dates.import_as is datetime', function () {
    $tmp = dateFixture();

    try {
        $rows = readWith(new OpenSpoutReader(['dates' => ['import_as' => 'datetime']]), $tmp);

        expect($rows[0]['date'])->toBeInstanceOf(DateTimeImmutable::class)
            ->and($rows[0]['date']->format('Y-m-d'))->toBe('2025-06-15')
            ->and($rows[1]['date'])->toBeInstanceOf(DateTimeImmutable::class)
            ->and($rows[1]['date']->format('Y-m-d H:i:s'))->toBe('2025-12-25 14:30:00');
    } finally {
        @unlink($tmp);
    }
});

it('honours dates.import_as from config when importing through the manager', function () {
    $tmp = dateFixture();

    try {
        $import = new SimpleArrayImport;
        SheetStream::import($import, $tmp);
        expect($import->result[0]['date'])->toBe(SERIAL_2025_06_15);

        config(['sheet-stream.dates.import_as' => 'datetime']);

        $import = new SimpleArrayImport;
        SheetStream::import($import, $tmp);
        expect($import->result[0]['date'])->toBeInstanceOf(DateTimeInterface::class);
    } finally {
        config(['sheet-stream.dates.import_as' => 'serial']);
        @unlink($tmp);
    }
});

it('rejects an unknown dates.import_as value', function () {
    $tmp = dateFixture();

    try {
        readWith(new OpenSpoutReader(['dates' => ['import_as' => 'carbon']]), $tmp);
    } finally {
        @unlink($tmp);
    }
})->throws(InvalidArgumentException::class, 'carbon');

it('applies dates.timezone before converting to a serial', function () {
    $tmp = dateFixture();

    try {
        // 2025-12-25 14:30 UTC is 09:30 in New York; the serial reflects the local wall clock.
        $rows = readWith(new OpenSpoutReader(['dates' => ['timezone' => 'America/New_York']]), $tmp);

        expect(round($rows[1]['date'], 6))->toBe(round(SERIAL_2025_12_25 + 9.5 / 24, 6));
    } finally {
        @unlink($tmp);
    }
});

it('returns formatted date strings when the import uses WithFormatData', function () {
    $tmp = dateFixture('mm/dd/yyyy');

    try {
        $import = new FormatDataArrayImport;
        SheetStream::import($import, $tmp);

        expect($import->result[0]['date'])->toBe('06/15/2025')
            ->and($import->result[1]['date'])->toBe('2025-12-25 14:30:00') // datetime cells use dates.datetime_format
            ->and($import->result[0]['name'])->toBe('Alice');
    } finally {
        @unlink($tmp);
    }
});

it('layers WithFormatData onto native options supplied via WithReaderOptions', function () {
    $tmp = dateFixture('mm/dd/yyyy');
    $native = new XlsxOptions;
    $native->SHOULD_PRESERVE_EMPTY_ROWS = true;

    try {
        $rows = readWith(new OpenSpoutReader(['formatData' => true], $native), $tmp);

        expect($native->SHOULD_FORMAT_DATES)->toBeTrue()
            ->and($native->SHOULD_PRESERVE_EMPTY_ROWS)->toBeTrue()
            ->and($rows[0]['date'])->toBe('06/15/2025');
    } finally {
        @unlink($tmp);
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
        $rows = readWith(new OpenSpoutReader, $path);

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['name'])->toBe('Alice')
            ->and($rows[0]['date'])->toBeInt()
            ->and($rows[0]['date'])->toBe(SERIAL_2025_06_15);
    } finally {
        @unlink($path);
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
        $rows = readWith(new OpenSpoutReader, $path);

        expect($rows[0]['amount'])->toBe(1500)
            ->and($rows[0]['rate'])->toBe(0.075);
    } finally {
        @unlink($path);
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

        $rows = readWith(new OpenSpoutReader(['dates' => ['import_as' => 'datetime']]), $tmp);

        // 45823 is a plain number, NOT a date (even though it equals the Excel serial for 2025-06-15)
        expect($rows[0]['score'])->toBe(45823)
            ->and($rows[0]['score'])->toBeInt()
            ->and($rows[0]['date'])->toBeInstanceOf(DateTimeInterface::class)
            ->and($rows[0]['date']->format('Y-m-d'))->toBe('2025-06-15')
            ->and($rows[0]['qty'])->toBe(100);
    } finally {
        @unlink($tmp);
    }
});

it('round-trips dates written by ExportRunner back to serials', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'mixed_export_').'.xlsx';

    try {
        $export = new class implements FromCollection, WithHeadings, WithMapping
        {
            public function collection(): Collection
            {
                return new Collection([
                    ['name' => 'Alice', 'joined' => new DateTimeImmutable('2025-06-15'), 'score' => 95],
                    ['name' => 'Bob', 'joined' => new DateTimeImmutable('2025-12-25 14:30:00'), 'score' => 87],
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

        $rows = readWith(new OpenSpoutReader, $tmp);

        expect($rows)->toHaveCount(2)
            ->and($rows[0]['joined'])->toBe(SERIAL_2025_06_15)
            ->and($rows[0]['score'])->toBe(95)
            ->and(round($rows[1]['joined'], 6))->toBe(round(SERIAL_2025_12_25 + 14.5 / 24, 6))
            ->and($rows[1]['score'])->toBe(87);
    } finally {
        @unlink($tmp);
    }
});

it('reads dates the same way through the PhpSpreadsheet driver', function () {
    $tmp = dateFixture('mm/dd/yyyy');

    try {
        $serial = readWith(new PhpSpreadsheetReader, $tmp);
        expect($serial[0]['date'])->toEqual(SERIAL_2025_06_15)
            ->and(round($serial[1]['date'], 6))->toBe(round(SERIAL_2025_12_25 + 14.5 / 24, 6));

        $objects = readWith(new PhpSpreadsheetReader(['dates' => ['import_as' => 'datetime']]), $tmp);
        expect($objects[0]['date'])->toBeInstanceOf(DateTimeImmutable::class)
            ->and($objects[0]['date']->format('Y-m-d'))->toBe('2025-06-15')
            ->and($objects[1]['date']->format('Y-m-d H:i'))->toBe('2025-12-25 14:30')
            ->and($objects[0]['name'])->toBe('Alice');

        $formatted = readWith(new PhpSpreadsheetReader(['formatData' => true]), $tmp);
        expect($formatted[0]['date'])->toBe('06/15/2025')
            ->and($formatted[0]['name'])->toBe('Alice');
    } finally {
        @unlink($tmp);
    }
});

it('stages date cells as serials in the staging pipeline', function () {
    $stagingPath = sys_get_temp_dir().'/sheet_stream_date_test_'.uniqid();
    mkdir($stagingPath, 0755, true);
    app()->singleton(StagingStore::class, fn () => new FileStagingStore($stagingPath));

    $tmp = dateFixture();

    try {
        Bus::fake([StagingChunkProcessorJob::class]);

        $producer = new StagingProducerJob(
            import: new StagingArrayImport,
            filePath: $tmp,
            readerOptions: ['dates' => ['import_as' => 'serial', 'timezone' => null]],
            chunkSize: 10,
            insertBatchSize: 100,
        );
        $producer->handle();

        $files = glob($stagingPath.'/*/*.ndjson') ?: [];
        expect($files)->toHaveCount(1);

        $lines = array_values(array_filter(explode("\n", file_get_contents($files[0])), fn ($l) => $l !== ''));
        $first = json_decode($lines[0], true);

        expect($first['row_data']['date'])->toBe(SERIAL_2025_06_15);
    } finally {
        @unlink($tmp);
        foreach (glob($stagingPath.'/**/*.ndjson') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($stagingPath.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($stagingPath);
    }
});

describe('CellNormalizer', function () {
    it('converts dates to 1900-system Excel serials', function () {
        expect(CellNormalizer::dateToSerial(new DateTimeImmutable('1970-01-01')))->toBe(25569)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('2025-06-15')))->toBe(SERIAL_2025_06_15)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('2025-06-15 12:00:00')))->toBe(SERIAL_2025_06_15 + 0.5)
            ->and(CellNormalizer::dateToSerial(new DateTime('2025-06-15 06:00:00')))->toBe(SERIAL_2025_06_15 + 0.25);
    });

    it('reproduces the 1900 leap-year quirk for dates before March 1900', function () {
        expect(CellNormalizer::dateToSerial(new DateTimeImmutable('1900-01-01')))->toBe(1)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1900-02-28')))->toBe(59)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1900-03-01')))->toBe(61);
    });

    it('uses the wall clock of the date, not UTC', function () {
        $local = new DateTimeImmutable('2025-06-15 23:00:00', new DateTimeZone('America/New_York'));

        expect(CellNormalizer::dateToSerial($local))->toBe(SERIAL_2025_06_15 + 23 / 24);
    });

    it('converts time-only intervals to a fraction of a day', function () {
        expect(CellNormalizer::intervalToSerial(new DateInterval('PT6H')))->toBe(0.25)
            ->and(CellNormalizer::intervalToSerial(new DateInterval('P1D')))->toBe(1)
            ->and(CellNormalizer::normalize([new DateInterval('PT12H'), 'x'], 'serial'))->toBe([0.5, 'x']);
    });

    it('leaves rows untouched in datetime mode', function () {
        $date = new DateTimeImmutable('2025-06-15');

        expect(CellNormalizer::normalize([$date, 1, 'a'], 'datetime'))->toBe([$date, 1, 'a']);
    });
});
