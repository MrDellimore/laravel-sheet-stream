<?php

declare(strict_types=1);

use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Support\CellNormalizer;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * In serial mode a date-styled cell must come back as the number stored in the file,
 * which is what Laravel Excel hands an import. The serials below straddle Excel's
 * fictitious 1900-02-29 (serial 60): everything under 61 used to come back a day low
 * from the OpenSpout driver, and from the rust driver when a timezone was configured.
 */

const ROUND_TRIP_SERIALS = [
    'time 06:00' => 0.25,
    'time 18:00' => 0.75,
    '1900-01-01' => 1,
    '1900-01-01 12:00' => 1.5,
    '1900-01-31' => 31,
    '1900-02-28' => 59,
    'nonexistent 1900-02-29' => 60,
    'nonexistent 1900-02-29 12:00' => 60.5,
    '1900-03-01' => 61,
    '1900-03-01 12:00' => 61.5,
    '1900-03-02' => 62,
    '2025-06-15' => 45823,
    '2025-12-25 14:30' => 46016.604166666664,
];

const ROUND_TRIP_FORMATS = [
    'date' => 'yyyy-mm-dd',
    'datetime' => 'yyyy-mm-dd hh:mm:ss',
    'time of day' => 'h:mm',
    'elapsed time' => '[h]:mm:ss',
];

/** One row per serial, one column per number format, every cell holding that row's serial. */
function roundTripFixture(): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $row = 1;

    foreach (ROUND_TRIP_SERIALS as $serial) {
        $column = 1;

        foreach (ROUND_TRIP_FORMATS as $format) {
            $sheet->setCellValue([$column, $row], $serial);
            $sheet->getStyle([$column, $row])->getNumberFormat()->setFormatCode($format);
            $column++;
        }

        $row++;
    }

    $path = sys_get_temp_dir().'/'.uniqid('serial_round_trip_', true).'.xlsx';
    new Xlsx($spreadsheet)->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

/** @return list<list<mixed>> */
function readSerialRows(string $driver, string $path, array $dates, ?object $nativeOptions = null): array
{
    $reader = EngineFactory::reader($driver, ['dates' => $dates], $nativeOptions);
    $reader->open($path);

    try {
        $rows = [];

        foreach ($reader->sheets() as $sheet) {
            foreach ($sheet->rows() as $row) {
                $rows[] = array_values($row);
            }
        }

        return $rows;
    } finally {
        $reader->close();
    }
}

function skipWithoutRust(string $driver): void
{
    if ($driver === 'rust' && ! RustReader::isAvailable()) {
        test()->markTestSkipped('The rusty_sheet extension is not loaded.');
    }
}

dataset('serial drivers', ['openspout', 'phpspreadsheet', 'rust']);

// A null timezone reads natively on the rust driver; a timezone routes it through
// CellNormalizer like the OpenSpout driver. The tests run with PHP's default timezone (UTC).
dataset('serial timezones', ['no timezone' => [null], 'the default timezone' => ['UTC']]);

it('returns the stored serial for date-, datetime- and time-styled cells', function (string $driver, ?string $timezone) {
    skipWithoutRust($driver);
    expect(date_default_timezone_get())->toBe('UTC');

    $path = roundTripFixture();

    try {
        $rows = readSerialRows($driver, $path, ['import_as' => 'serial', 'timezone' => $timezone]);
        $labels = array_keys(ROUND_TRIP_SERIALS);
        $formats = array_keys(ROUND_TRIP_FORMATS);

        expect($rows)->toHaveSameSize(ROUND_TRIP_SERIALS);

        foreach (array_values(ROUND_TRIP_SERIALS) as $r => $stored) {
            foreach ($formats as $c => $format) {
                $value = $rows[$r][$c];
                $cell = "{$labels[$r]} as {$format}";

                // A whole serial is an int; a fraction is exact apart from the 14:30 one,
                // which is rebuilt from whole seconds.
                is_int($stored)
                    ? expect($value)->toBe($stored, $cell)
                    : expect($value)->toBeFloat($cell)->toEqualWithDelta($stored, 1e-9, $cell);
            }
        }
    } finally {
        @unlink($path);
    }
})->with('serial drivers')->with('serial timezones');

it('hands an import the stored serial of a time-only cell through the manager', function (string $driver, ?string $timezone) {
    skipWithoutRust($driver);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray(['Name', 'Starts At', 'Born']);
    $sheet->setCellValue('A2', 'Alice');
    $sheet->setCellValue('B2', 0.75);
    $sheet->getStyle('B2')->getNumberFormat()->setFormatCode('h:mm AM/PM');
    $sheet->setCellValue('C2', 31);
    $sheet->getStyle('C2')->getNumberFormat()->setFormatCode('m/d/yyyy');
    $path = sys_get_temp_dir().'/'.uniqid('serial_round_trip_', true).'.xlsx';
    new Xlsx($spreadsheet)->save($path);
    $spreadsheet->disconnectWorksheets();

    config([
        'sheet-stream.default_reader' => $driver,
        'sheet-stream.dates.import_as' => 'serial',
        'sheet-stream.dates.timezone' => $timezone,
    ]);

    try {
        $import = new SimpleArrayImport;
        SheetStream::import($import, $path);

        expect($import->result)->toBe([['name' => 'Alice', 'starts_at' => 0.75, 'born' => 31]]);
    } finally {
        @unlink($path);
    }
})->with('serial drivers')->with('serial timezones');

it('still shifts the serial by the wall clock of a timezone other than the default', function (string $driver) {
    skipWithoutRust($driver);
    $path = roundTripFixture();

    try {
        $rows = readSerialRows($driver, $path, ['import_as' => 'serial', 'timezone' => 'America/New_York']);
        $byLabel = array_combine(array_keys(ROUND_TRIP_SERIALS), array_column($rows, 0));

        // Midnight UTC is 19:00 the previous day in New York (UTC-5 in winter, -4 in June):
        // the shift is the timezone's and nothing else, on either side of serial 61.
        expect($byLabel['time 18:00'])->toEqualWithDelta(0.75 - 5 / 24, 1e-9)
            ->and($byLabel['1900-02-28'])->toEqualWithDelta(59 - 5 / 24, 1e-9)
            ->and($byLabel['1900-03-01'])->toEqualWithDelta(61 - 5 / 24, 1e-9)
            ->and($byLabel['1900-03-02'])->toEqualWithDelta(62 - 5 / 24, 1e-9)
            ->and($byLabel['2025-06-15'])->toEqualWithDelta(45823 - 4 / 24, 1e-9);
    } finally {
        @unlink($path);
    }
})->with(['openspout', 'rust']);

it('never applies a timezone on the PhpSpreadsheet driver, which reads the stored number', function () {
    $path = roundTripFixture();

    try {
        $rows = readSerialRows('phpspreadsheet', $path, ['import_as' => 'serial', 'timezone' => 'America/New_York']);

        // Same values as with no timezone at all.
        expect(array_column($rows, 0))
            ->toBe(array_column(readSerialRows('phpspreadsheet', $path, ['import_as' => 'serial', 'timezone' => null]), 0))
            ->and($rows[1][0])->toBe(0.75)
            ->and($rows[11][0])->toBe(45823);
    } finally {
        @unlink($path);
    }
});

it('keeps the datetime mode output unchanged', function (string $driver) {
    skipWithoutRust($driver);
    $path = roundTripFixture();

    try {
        $rows = readSerialRows($driver, $path, ['import_as' => 'datetime', 'timezone' => null]);
        $byLabel = array_combine(
            array_keys(ROUND_TRIP_SERIALS),
            array_map(fn (array $row) => $row[1]->format('Y-m-d H:i:s'), $rows),
        );

        // Both readers count days from 1899-12-30 with no 1900-02-29, so below serial 61
        // the calendar date is one day behind what Excel displays.
        expect($byLabel)->toMatchArray([
            'time 18:00' => '1899-12-30 18:00:00',
            '1900-01-01' => '1899-12-31 00:00:00',
            '1900-02-28' => '1900-02-27 00:00:00',
            'nonexistent 1900-02-29' => '1900-02-28 00:00:00',
            '1900-03-01' => '1900-03-01 00:00:00',
            '2025-12-25 14:30' => '2025-12-25 14:30:00',
        ]);
    } finally {
        @unlink($path);
    }
})->with(['openspout', 'rust']);

it('returns the stored serial for negative serials', function (string $driver, ?string $timezone) {
    skipWithoutRust($driver);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    foreach ([-0.5, -1.25, -3] as $i => $serial) {
        $sheet->setCellValue([1, $i + 1], $serial);
        $sheet->getStyle([1, $i + 1])->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
    }
    $path = sys_get_temp_dir().'/'.uniqid('serial_round_trip_', true).'.xlsx';
    new Xlsx($spreadsheet)->save($path);
    $spreadsheet->disconnectWorksheets();

    try {
        $rows = readSerialRows($driver, $path, ['import_as' => 'serial', 'timezone' => $timezone]);

        expect(array_column($rows, 0))->toBe([-0.5, -1.25, -3]);
    } finally {
        @unlink($path);
    }
})->with('serial drivers')->with('serial timezones');

it('loses sub-second precision on the streaming drivers, which build dates from whole seconds', function (string $driver) {
    skipWithoutRust($driver);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 0.123456789);
    $sheet->getStyle('A1')->getNumberFormat()->setFormatCode('h:mm:ss');
    $path = sys_get_temp_dir().'/'.uniqid('serial_round_trip_', true).'.xlsx';
    new Xlsx($spreadsheet)->save($path);
    $spreadsheet->disconnectWorksheets();

    try {
        $value = readSerialRows($driver, $path, ['import_as' => 'serial', 'timezone' => null])[0][0];

        // 0.123456789 days is 10666.67 s, read as 02:57:47.
        expect($value)->toBe($driver === 'phpspreadsheet' ? 0.123456789 : 10667 / 86400);
    } finally {
        @unlink($path);
    }
})->with('serial drivers');

it('returns the stored serial when the rust driver falls back to OpenSpout', function (?string $timezone) {
    skipWithoutRust('rust');
    $path = roundTripFixture();

    // Asking for the 1904 date system is the one XLSX case the rust driver hands to
    // OpenSpout, which then takes the date system from the workbook itself (1900 here).
    $reader = new RustReader(
        ['dates' => ['import_as' => 'serial', 'timezone' => $timezone]],
        new XlsxOptions(SHOULD_USE_1904_DATES: true),
    );
    $reader->open($path);

    try {
        expect($reader->usesFallback())->toBeTrue();

        $rows = [];
        foreach ($reader->sheets() as $sheet) {
            foreach ($sheet->rows() as $row) {
                $rows[] = array_values($row);
            }
        }

        $byLabel = array_combine(array_keys(ROUND_TRIP_SERIALS), array_column($rows, 2));

        expect($byLabel['time 18:00'])->toBe(0.75)
            ->and($byLabel['1900-01-01'])->toBe(1)
            ->and($byLabel['nonexistent 1900-02-29'])->toBe(60)
            ->and($byLabel['1900-03-01'])->toBe(61)
            ->and($byLabel['2025-06-15'])->toBe(45823);
    } finally {
        $reader->close();
        @unlink($path);
    }
})->with('serial timezones');

it('reports the dates of a 1904-system workbook as 1900-system serials', function (string $driver, ?string $timezone) {
    skipWithoutRust($driver);

    $spreadsheet = new Spreadsheet;
    $spreadsheet->setExcelCalendar(Date::CALENDAR_MAC_1904);
    $sheet = $spreadsheet->getActiveSheet();
    foreach ([0.75, 1, 44361] as $i => $serial) {
        $sheet->setCellValue([1, $i + 1], $serial);
        $sheet->getStyle([1, $i + 1])->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
    }
    $path = sys_get_temp_dir().'/'.uniqid('serial_round_trip_', true).'.xlsx';
    new Xlsx($spreadsheet)->save($path);
    $spreadsheet->disconnectWorksheets();

    try {
        $rows = readSerialRows($driver, $path, ['import_as' => 'serial', 'timezone' => $timezone]);

        // Not the stored number, deliberately: the streaming drivers read the cell as the
        // date it means (0.75 is 1904-01-01 18:00) and give that date's 1900-system serial,
        // 1462 days on. 44361 is 2025-06-15 in the 1904 system, 45823 in the 1900 system.
        expect(array_column($rows, 0))->toBe([1462.75, 1463, 45823]);
    } finally {
        @unlink($path);
    }
})->with(['openspout', 'rust'])->with('serial timezones');

describe('CellNormalizer::dateToSerial', function () {
    it('inverts the way the readers build a date from a serial', function () {
        expect(CellNormalizer::dateToSerial(new DateTimeImmutable('1899-12-30 18:00:00')))->toBe(0.75)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1899-12-30 00:00:00')))->toBe(0)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1899-12-31')))->toBe(1)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1900-02-27')))->toBe(59)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1900-02-28')))->toBe(60)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1900-02-28 12:00:00')))->toBe(60.5)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1900-03-01')))->toBe(61)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1900-03-02')))->toBe(62);
    });

    it('handles dates before the 1899-12-30 base', function () {
        expect(CellNormalizer::dateToSerial(new DateTimeImmutable('1899-12-29 12:00:00')))->toBe(-0.5)
            ->and(CellNormalizer::dateToSerial(new DateTimeImmutable('1899-12-27')))->toBe(-3);
    });

    it('normalizes a time-only date and an elapsed-time interval to the same fraction', function () {
        expect(CellNormalizer::normalize([
            new DateTimeImmutable('1899-12-30 18:00:00'),
            new DateInterval('PT18H'),
            new DateTime('1899-12-30 06:00:00'),
        ]))->toBe([0.75, 0.75, 0.25]);
    });
});
