<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use MrDellimore\SheetStream\Tests\Fixtures\CsvFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\FormulaXlsxFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetXlsxFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer;

/*
 * The 'rust' driver must yield what the 'openspout' driver yields. Run with the
 * extension loaded:  php -d extension=/path/to/librusty_sheet.so vendor/bin/pest
 */

beforeEach(function () {
    if (! RustReader::isAvailable()) {
        $this->markTestSkipped('The rusty_sheet extension is not loaded.');
    }
});

/** Read every sheet with a driver: sheet name => rows, values rendered with their types. */
function readAllSheets(Reader $reader, string $path): array
{
    $reader->open($path);
    try {
        $out = [];
        foreach ($reader->sheets() as $sheet) {
            $rows = [];
            foreach ($sheet->rows() as $row) {
                $rows[] = array_map(describeCell(...), array_values($row));
            }
            $out[$sheet->name()] = $rows;
        }

        return $out;
    } finally {
        $reader->close();
    }
}

function describeCell(mixed $v): string
{
    return match (true) {
        $v instanceof DateTimeInterface => 'datetime('.$v->format('Y-m-d H:i:s.u e').')',
        $v instanceof DateInterval => 'interval('.$v->format('%R %d %h:%i:%s.%f').')',
        default => get_debug_type($v).'('.var_export($v, true).')',
    };
}

/** An .xlsx with real date, datetime and time-of-day number formats. */
function rustDateFixture(string $format): string
{
    $path = sys_get_temp_dir().'/'.uniqid('rust_dates_', true).'.xlsx';
    $writer = new OpenSpoutWriter('xlsx', null, dateFormat: $format, dateTimeFormat: $format);
    $writer->openToFile($path);
    $writer->addSheet('Dates');
    $writer->addRow(['Label', 'When']);
    $writer->addRow(['date', new DateTimeImmutable('2025-06-15')]);
    $writer->addRow(['datetime', new DateTimeImmutable('2025-12-25 14:30:00')]);
    $writer->addRow(['late', new DateTimeImmutable('1999-12-31 23:59:59')]);
    $writer->close();

    return $path;
}

function openSpoutBefore(string $version): bool
{
    return version_compare(InstalledVersions::getVersion('openspout/openspout') ?? '0', $version, '<');
}

function rustCsvFixture(string $extension, string $contents): string
{
    $path = sys_get_temp_dir().'/'.uniqid('rust_csv_', true).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

/** @return array<string, array{array<string, mixed>, ?object}> */
function rustParityModes(): array
{
    return [
        'serial' => [[], null],
        'datetime' => [['dates' => ['import_as' => 'datetime']], null],
        'datetime+tz' => [['dates' => ['import_as' => 'datetime', 'timezone' => 'America/New_York']], null],
        'serial+tz' => [['dates' => ['import_as' => 'serial', 'timezone' => 'America/New_York']], null],
        'calculated formulas' => [['calculateFormulas' => true], null],
        'format data' => [['formatData' => true], null],
        'format data + calculated + tz' => [['formatData' => true, 'calculateFormulas' => true, 'dates' => ['timezone' => 'America/New_York']], null],
        'native SHOULD_FORMAT_DATES' => [[], new XlsxOptions(SHOULD_FORMAT_DATES: true)],
        'csv semicolon' => [[], new CsvOptions(FIELD_DELIMITER: ';')],
        'csv preserve empty rows' => [[], new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)],
        'csv calculated' => [['calculateFormulas' => true], null],
        'preserve empty rows' => [[], new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)],
    ];
}

/** Fixture name => factory returning [path, keep-alive object]. */
function rustParityFixtures(): array
{
    $fixtures = [
        'mixed values' => fn () => [($b = (new XlsxFixtureBuilder)->write([
            ['name', 'age', 'score', 'active', 'note'],
            ['Alice', 30, 91.5, true, null],
            ['Bob', 0, -2.25, false, ''],
            [],
            ['Ünïcødé & <xml>', 1500, 0.075, null, '=1+1'],
            ['short'],
        ]))->path(), $b],
        'multiple sheets' => fn () => [($b = (new MultiSheetXlsxFixtureBuilder)->write([
            'Users' => [['id', 'name'], [1, 'a'], [2, 'b']],
            'Orders' => [['id', 'total'], [10, 9.99]],
            'Empty' => [],
        ]))->path(), $b],
        'formulas' => fn () => [($b = (new FormulaXlsxFixtureBuilder)->build())->path(), $b],
        'dates yyyy-mm-dd' => fn () => [rustDateFixture('yyyy-mm-dd'), null],
        'dates with time' => fn () => [rustDateFixture('yyyy-mm-dd hh:mm:ss'), null],
        'dates dddd, mmmm d' => fn () => [rustDateFixture('dddd, mmmm d, yyyy h:mm AM/PM'), null],
        'dates with literals' => fn () => [rustDateFixture('"Day "d" of "mmm yyyy\\Thh'), null],
    ];

    $fixtures += [
        'csv builder' => fn () => [($b = (new CsvFixtureBuilder)->write([
            ['name', 'note'], ['Alice', 'has, comma'], ['Bob', 'say "hi"'], ['', ''],
        ]))->path(), $b],
        'csv quirks' => fn () => [rustCsvFixture('csv', "\nid,name,note\r\n1,\"multi\nline\",x\n2,  \"sp\"aced,=== Totals ===\n\n3,a;b,\n\"\"\n4,last"), null],
        'csv semicolon + bom' => fn () => [rustCsvFixture('csv', "\xEF\xBB\xBFa;b\n1;\"x;y\"\n"), null],
        'tsv' => fn () => [rustCsvFixture('tsv', "a\tb\n1\t\"t\tab\"\n"), null],
    ];

    // Real-world files authored by Excel, LibreOffice and ONLYOFFICE (local, gitignored).
    foreach (glob(dirname(__DIR__, 2).'/_reference-laravel-excel/tests/Data/Disks/Local/*.{xlsx,csv,tsv}', GLOB_BRACE) ?: [] as $file) {
        $fixtures['laravel-excel/'.basename($file)] = fn () => [$file, null];
    }

    return $fixtures;
}

function rustParityCases(): array
{
    $cases = [];
    foreach (array_keys(rustParityFixtures()) as $fixture) {
        foreach (array_keys(rustParityModes()) as $mode) {
            $cases["$fixture [$mode]"] = [$fixture, $mode];
        }
    }

    return $cases;
}

it('is built by the factory for the rust driver', function () {
    expect(EngineFactory::reader('rust'))->toBeInstanceOf(RustReader::class);
});

it('reads exactly what the OpenSpout driver reads', function (string $fixture, string $mode) {
    [$path, $keepAlive] = rustParityFixtures()[$fixture]();
    [$options, $native] = rustParityModes()[$mode];

    $expected = readAllSheets(new OpenSpoutReader($options, $native), $path);
    $actual = readAllSheets(new RustReader($options, $native), $path);

    // Fixed in OpenSpout 5.15, which the extension follows. sheet-stream's composer.lock is
    // gitignored, so CI resolves the newest 5.x; an older local install shows these differences.
    if ($fixture === 'mixed values' && ($options['calculateFormulas'] ?? false)) {
        // '=1+1' is written without a cached result: OpenSpout < 5.15 computes (float) '' = 0.0.
        expect($actual['Sheet1'][3][4])->toBe('null(NULL)');
        $expected['Sheet1'][3][4] = 'null(NULL)';
    }
    if ($options['calculateFormulas'] ?? false) {
        // Deliberate: OpenSpout turns text starting with "=" into a value-less formula
        // cell, so it reads back as null. The extension keeps the text.
        foreach ($expected as $sheet => $rows) {
            foreach ($rows as $r => $cells) {
                foreach ($cells as $c => $value) {
                    $got = $actual[$sheet][$r][$c] ?? null;
                    if ($value === 'null(NULL)' && is_string($got) && str_starts_with($got, "string('=")) {
                        $expected[$sheet][$r][$c] = $got;
                    }
                }
            }
        }
    }
    $formatsDates = ($options['formatData'] ?? false) || ($native instanceof XlsxOptions && $native->SHOULD_FORMAT_DATES);
    if ($fixture === 'dates with literals' && $formatsDates && openSpoutBefore('5.15.0')) {
        // OpenSpout < 5.15 turns the escaped \T into PHP's "t" (days in month): "Day 15 of Jun 20253000".
        expect(array_column(array_slice($actual['Dates'], 1), 1))
            ->each->toMatch("/^string\\('Day \\d+ of \\w{3} \\d{4}T\\d{2}'\\)$/");
        $expected = $actual;
    }

    expect($actual)->toBe($expected);
    unset($keepAlive);
})->with(rustParityCases());

it('uses the extension for xlsx and OpenSpout for what it cannot read yet', function () {
    $xlsx = (new XlsxFixtureBuilder)->write([['a', 1]]);
    $csv = (new CsvFixtureBuilder)->write([['a', '1']]);

    $reader = new RustReader;
    $reader->open($xlsx->path());
    expect($reader->usesFallback())->toBeFalse();
    $reader->close();

    $reader->open($csv->path());
    expect($reader->usesFallback())->toBeFalse();
    $reader->close();

    $latin1 = new RustReader([], new CsvOptions(ENCODING: 'ISO-8859-1'));
    $latin1->open($csv->path());
    expect($latin1->usesFallback())->toBeTrue();
    $latin1->close();

    $ods = sys_get_temp_dir().'/'.uniqid('rust_', true).'.ods';
    $writer = new OpenSpout\Writer\ODS\Writer;
    $writer->openToFile($ods);
    $writer->addRow(Row::fromValues(['a', 1]));
    $writer->close();
    $reader->open($ods);
    expect($reader->usesFallback())->toBeTrue()
        ->and(readAllSheets(new RustReader, $ods))->toBe(readAllSheets(new OpenSpoutReader, $ods));
    $reader->close();
    @unlink($ods);

    $formatData = new RustReader(['formatData' => true]);
    $formatData->open($xlsx->path());
    expect($formatData->usesFallback())->toBeFalse();
    $formatData->close();

    $mac = new RustReader([], new XlsxOptions(SHOULD_USE_1904_DATES: true));
    $mac->open($xlsx->path());
    expect($mac->usesFallback())->toBeTrue();
    $mac->close();
});

it('still rejects .xls like the OpenSpout driver', function () {
    $path = sys_get_temp_dir().'/'.uniqid('legacy_', true).'.xls';
    file_put_contents($path, 'not really xls');

    try {
        expect(fn () => (new RustReader)->open($path))->toThrow(UnsupportedByEngine::class);
    } finally {
        @unlink($path);
    }
});

it('returns the true serial for time-only cells, where the OpenSpout driver is a day off', function () {
    $path = sys_get_temp_dir().'/'.uniqid('rust_time_', true).'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(new Row([
        Cell::fromValue('18:00'),
        Cell::fromValue(0.75, new Style(format: 'hh:mm')),
    ]));
    $writer->close();

    try {
        $rust = readAllSheets(new RustReader, $path);
        $openspout = readAllSheets(new OpenSpoutReader, $path);

        // CellNormalizer::dateToSerial() subtracts a leap day OpenSpout never added,
        // so every value before 1900-03-01, including every time of day, comes out a day early.
        expect($rust['Sheet1'][0][1])->toBe('float(0.75)')
            ->and($openspout['Sheet1'][0][1])->toBe('float(-0.25)');
    } finally {
        @unlink($path);
    }
});
