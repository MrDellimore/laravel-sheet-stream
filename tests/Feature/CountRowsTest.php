<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\WithCalculatedFormulas;
use MrDellimore\SheetStream\Concerns\WithFormatData;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use MrDellimore\SheetStream\Concerns\WithRequiredHeadings;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Tests\Fixtures\RowCountingImport;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * countRows() must equal the number of rows a real import of the same file hands to the
 * import class, so every case below runs the import too and compares, on every driver.
 */

dataset('count drivers', ['openspout', 'phpspreadsheet', 'rust']);

function useCountDriver(string $driver): void
{
    if ($driver === 'rust' && ! RustReader::isAvailable()) {
        test()->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    config(['sheet-stream.default_reader' => $driver]);
}

function countTempFile(string $extension, string $contents = ''): string
{
    $path = sys_get_temp_dir().'/'.uniqid('count_fixture_', true).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

/** @param array<string, array<int, array<int, mixed>>> $sheets sheet name => rows; a row of [] is left blank */
function countWorkbook(array $sheets): string
{
    $spreadsheet = new Spreadsheet;
    $first = true;

    foreach ($sheets as $name => $rows) {
        $sheet = $first ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
        $sheet->setTitle($name);
        $first = false;

        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $value) {
                if ($value !== null) {
                    $sheet->setCellValue([$c + 1, $r + 1], $value);
                }
            }
        }
    }

    $path = countTempFile('xlsx');
    new Xlsx($spreadsheet)->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

/**
 * Nine source rows: a heading, four ordinary rows (one only whitespace, one only a zero,
 * neither of which SkipsEmptyRows treats as empty), two blank rows in the middle, and a
 * row of empty strings.
 */
function blankRowsFixture(): array
{
    return [
        ['Name', 'Qty'],
        ['Alice', 1],
        [],
        ['Bob', 2],
        [' ', null],
        [],
        [null, 0],
        ['', ''],
        ['Carol', 3],
    ];
}

function blankRowsCsv(): string
{
    return countTempFile('csv', "Name,Qty\nAlice,1\n\nBob,2\n\" \",\n\n,0\n,\nCarol,3\n");
}

/** A fresh import with the given concerns; $options turns on WithReaderOptions. */
function countingImport(bool $heading, bool $skipsEmpty, ?object $options = null): RowCountingImport
{
    if ($options !== null) {
        return match ([$heading, $skipsEmpty]) {
            [true, true] => new class($options) extends RowCountingImport implements SkipsEmptyRows, WithHeadingRow, WithReaderOptions {},
            [true, false] => new class($options) extends RowCountingImport implements WithHeadingRow, WithReaderOptions {},
            [false, true] => new class($options) extends RowCountingImport implements SkipsEmptyRows, WithReaderOptions {},
            default => new class($options) extends RowCountingImport implements WithReaderOptions {},
        };
    }

    return match ([$heading, $skipsEmpty]) {
        [true, true] => new class extends RowCountingImport implements SkipsEmptyRows, WithHeadingRow {},
        [true, false] => new class extends RowCountingImport implements WithHeadingRow {},
        [false, true] => new class extends RowCountingImport implements SkipsEmptyRows {},
        default => new RowCountingImport,
    };
}

/** @param array<int|string, RowCountingImport> $sheets */
function countingSheets(array $sheets): object
{
    return new class($sheets) implements WithMultipleSheets
    {
        public int $sheetsCalls = 0;

        public function __construct(public array $sheets) {}

        public function sheets(): array
        {
            $this->sheetsCalls++;

            return $this->sheets;
        }
    };
}

/** Rows a real import of the file delivers, for a single-sheet import. */
function importedRowCount(RowCountingImport $import, string $path, ?string $disk = null): int
{
    SheetStream::import($import, $path, $disk);

    return $import->delivered;
}

function countTempCopies(): array
{
    return glob((config('sheet-stream.temp_path') ?? sys_get_temp_dir()).'/sheet_stream_count_*') ?: [];
}

dataset('concern combinations', [
    'heading row' => [true, false],
    'heading row + SkipsEmptyRows' => [true, true],
    'no heading row' => [false, false],
    'SkipsEmptyRows only' => [false, true],
]);

it('counts what an xlsx import delivers', function (string $driver, bool $heading, bool $skipsEmpty) {
    useCountDriver($driver);
    $path = countWorkbook(['Data' => blankRowsFixture()]);

    // The streaming drivers drop blank rows themselves and read the row of empty-string
    // cells as blank too; PhpSpreadsheet yields all nine rows and leaves it to SkipsEmptyRows.
    $rowsRead = $driver === 'phpspreadsheet' ? ($skipsEmpty ? 6 : 9) : 6;
    $expected = $rowsRead - ($heading ? 1 : 0);

    try {
        $counts = SheetStream::countRows(countingImport($heading, $skipsEmpty), $path);

        expect($counts)->toBe(['Data' => $expected])
            ->and(importedRowCount(countingImport($heading, $skipsEmpty), $path))->toBe($expected);
    } finally {
        @unlink($path);
    }
})->with('count drivers')->with('concern combinations');

it('counts what an xlsx import delivers when the reader preserves empty rows', function (string $driver, bool $heading, bool $skipsEmpty) {
    useCountDriver($driver);
    $path = countWorkbook(['Data' => blankRowsFixture()]);
    $options = fn () => new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true);

    // All nine rows reach the runner on every driver now; SkipsEmptyRows removes three.
    $expected = ($skipsEmpty ? 6 : 9) - ($heading ? 1 : 0);

    try {
        $counts = SheetStream::countRows(countingImport($heading, $skipsEmpty, $options()), $path);

        expect($counts)->toBe(['Data' => $expected])
            ->and(importedRowCount(countingImport($heading, $skipsEmpty, $options()), $path))->toBe($expected);
    } finally {
        @unlink($path);
    }
})->with('count drivers')->with('concern combinations');

it('counts what a CSV import delivers', function (string $driver, bool $heading, bool $skipsEmpty) {
    useCountDriver($driver);
    $path = blankRowsCsv();

    // OpenSpout and the extension skip the two blank lines but keep the "," line as
    // ['', '']; PhpSpreadsheet keeps all nine lines.
    $rowsRead = $skipsEmpty ? 6 : ($driver === 'phpspreadsheet' ? 9 : 7);
    $expected = $rowsRead - ($heading ? 1 : 0);

    try {
        $counts = SheetStream::countRows(countingImport($heading, $skipsEmpty), $path);

        // One sheet, named by the driver ('' or PhpSpreadsheet's "Worksheet").
        expect(array_values($counts))->toBe([$expected])
            ->and(importedRowCount(countingImport($heading, $skipsEmpty), $path))->toBe($expected);
    } finally {
        @unlink($path);
    }
})->with('count drivers')->with('concern combinations');

it('counts what a CSV import delivers when the reader preserves empty rows', function (string $driver, bool $heading, bool $skipsEmpty) {
    useCountDriver($driver);
    $path = blankRowsCsv();
    $options = fn () => new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true);

    $expected = ($skipsEmpty ? 6 : 9) - ($heading ? 1 : 0);

    try {
        $counts = SheetStream::countRows(countingImport($heading, $skipsEmpty, $options()), $path);

        expect(array_values($counts))->toBe([$expected])
            ->and(importedRowCount(countingImport($heading, $skipsEmpty, $options()), $path))->toBe($expected);
    } finally {
        @unlink($path);
    }
})->with('count drivers')->with('concern combinations');

it('honours a CSV delimiter from WithReaderOptions', function (string $driver) {
    useCountDriver($driver);
    $path = countTempFile('csv', "Name;Qty\nAlice;1\nBob;2\n");
    $options = fn () => new CsvOptions(FIELD_DELIMITER: ';');

    try {
        expect(array_values(SheetStream::countRows(countingImport(true, true, $options()), $path)))->toBe([2])
            ->and(importedRowCount(countingImport(true, true, $options()), $path))->toBe(2);
    } finally {
        @unlink($path);
    }
})->with('count drivers');

it('counts only the sheets a WithMultipleSheets import maps by index', function (string $driver) {
    useCountDriver($driver);
    $path = countWorkbook([
        'People' => [['Name'], ['Alice'], ['Bob'], ['Carol']],
        'Unmapped' => [['Name'], ['Dave'], ['Erin']],
        'Orders' => [['Order ID', 'Total']],
        'Notes' => [['first note'], ['second note']],
    ]);

    $make = fn () => countingSheets([
        0 => countingImport(true, false),
        2 => countingImport(true, false),
        3 => countingImport(false, true),
    ]);

    try {
        $counted = $make();
        $counts = SheetStream::countRows($counted, $path);

        $imported = $make();
        SheetStream::import($imported, $path);

        expect($counts)->toBe(['People' => 3, 'Orders' => 0, 'Notes' => 2])
            ->and(array_map(fn (RowCountingImport $sheet) => $sheet->delivered, $imported->sheets))->toBe([0 => 3, 2 => 0, 3 => 2])
            // Counting reads the file only: the sheet imports never see a row.
            ->and(array_sum(array_map(fn (RowCountingImport $sheet) => $sheet->delivered, $counted->sheets)))->toBe(0)
            ->and($counted->sheetsCalls)->toBe(1);
    } finally {
        @unlink($path);
    }
})->with('count drivers');

it('counts only the sheets a WithMultipleSheets import maps by name', function (string $driver) {
    useCountDriver($driver);
    $path = countWorkbook([
        'People' => [['Name'], ['Alice'], ['Bob'], ['Carol']],
        'Unmapped' => [['Name'], ['Dave'], ['Erin']],
        'Orders' => [['Order ID', 'Total'], [1, 10], [2, 20]],
        'Empty' => [],
    ]);

    // Declared out of workbook order, with a name the workbook doesn't have.
    $make = fn () => countingSheets([
        'Orders' => countingImport(true, true),
        'Missing' => countingImport(true, true),
        'People' => countingImport(false, false),
    ]);

    try {
        $counts = SheetStream::countRows($make(), $path);

        $imported = $make();
        SheetStream::import($imported, $path);

        // Keyed by sheet name in workbook order; "Missing" has no sheet so no entry.
        expect($counts)->toBe(['People' => 4, 'Orders' => 2])
            ->and(array_keys($counts))->toBe(['People', 'Orders'])
            ->and($imported->sheets['People']->delivered)->toBe(4)
            ->and($imported->sheets['Orders']->delivered)->toBe(2)
            ->and($imported->sheets['Missing']->delivered)->toBe(0);
    } finally {
        @unlink($path);
    }
})->with('count drivers');

it('resolves a sheet by index before name, like an import', function (string $driver) {
    useCountDriver($driver);
    $path = countWorkbook([
        'People' => [['Name'], ['Alice']],
        'Orders' => [['Order ID'], [1], [2]],
    ]);

    // Index 1 and the name "Orders" are the same sheet; the index entry wins.
    $make = fn () => countingSheets([
        1 => countingImport(false, false),
        'Orders' => countingImport(true, false),
        'People' => countingImport(true, false),
    ]);

    try {
        $imported = $make();
        SheetStream::import($imported, $path);

        expect(SheetStream::countRows($make(), $path))->toBe(['People' => 1, 'Orders' => 3])
            ->and($imported->sheets[1]->delivered)->toBe(3)
            ->and($imported->sheets['Orders']->delivered)->toBe(0)
            ->and($imported->sheets['People']->delivered)->toBe(1);
    } finally {
        @unlink($path);
    }
})->with('count drivers');

it('counts only the first sheet for an import without WithMultipleSheets', function (string $driver) {
    useCountDriver($driver);
    $path = countWorkbook([
        'People' => [['Name'], ['Alice'], ['Bob']],
        'Orders' => [['Order ID'], [1], [2], [3]],
    ]);

    try {
        expect(SheetStream::countRows(countingImport(true, false), $path))->toBe(['People' => 2])
            ->and(importedRowCount(countingImport(true, false), $path))->toBe(2);
    } finally {
        @unlink($path);
    }
})->with('count drivers');

it('counts zero rows for a sheet that has only its heading row', function (string $driver, bool $skipsEmpty) {
    useCountDriver($driver);
    $path = countWorkbook(['Data' => [['Name', 'Qty']]]);

    try {
        expect(SheetStream::countRows(countingImport(true, $skipsEmpty), $path))->toBe(['Data' => 0])
            ->and(importedRowCount(countingImport(true, $skipsEmpty), $path))->toBe(0);
    } finally {
        @unlink($path);
    }
})->with('count drivers')->with([false, true]);

it('counts an empty sheet the way an import reads it', function (string $driver, bool $heading, bool $skipsEmpty) {
    useCountDriver($driver);
    $path = countWorkbook(['Data' => []]);

    // PhpSpreadsheet reports an empty sheet as one row holding a single null cell, which
    // an import without WithHeadingRow or SkipsEmptyRows does receive.
    $expected = $driver === 'phpspreadsheet' && ! $heading && ! $skipsEmpty ? 1 : 0;

    try {
        expect(SheetStream::countRows(countingImport($heading, $skipsEmpty), $path))->toBe(['Data' => $expected])
            ->and(importedRowCount(countingImport($heading, $skipsEmpty), $path))->toBe($expected);
    } finally {
        @unlink($path);
    }
})->with('count drivers')->with('concern combinations');

it('counts an empty CSV file the way an import reads it', function (string $driver, bool $heading, bool $skipsEmpty) {
    useCountDriver($driver);
    $path = countTempFile('csv');

    $expected = $driver === 'phpspreadsheet' && ! $heading && ! $skipsEmpty ? 1 : 0;

    try {
        expect(array_values(SheetStream::countRows(countingImport($heading, $skipsEmpty), $path)))->toBe([$expected])
            ->and(importedRowCount(countingImport($heading, $skipsEmpty), $path))->toBe($expected);
    } finally {
        @unlink($path);
    }
})->with('count drivers')->with('concern combinations');

it('counts exact multiples of a chunk size without an off-by-one', function (string $driver) {
    useCountDriver($driver);

    $rows = [['Name']];
    for ($i = 1; $i <= 2000; $i++) {
        $rows[] = ["row {$i}"];
    }
    $path = countWorkbook(['Data' => $rows]);

    try {
        // 2000 data rows: more than one batch of the rust reader's 1000-row fetches.
        expect(SheetStream::countRows(countingImport(true, true), $path))->toBe(['Data' => 2000])
            ->and(importedRowCount(countingImport(true, true), $path))->toBe(2000);
    } finally {
        @unlink($path);
    }
})->with('count drivers');

it('reads formulas the way the import would, so SkipsEmptyRows drops the same rows', function (string $driver) {
    useCountDriver($driver);

    // Row 3 holds only a formula whose cached result is an empty string: blank to an
    // import that reads calculated values, a "=…" string to one that doesn't.
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet()->setTitle('Data');
    $sheet->setCellValue('A1', 'Name');
    $sheet->setCellValue('A2', 'Alice');
    $sheet->setCellValue('A3', '=REPT("x",0)');
    $sheet->setCellValue('A4', 'Bob');
    $path = countTempFile('xlsx');
    $writer = new Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(true);
    $writer->save($path);
    $spreadsheet->disconnectWorksheets();

    $plain = fn () => countingImport(true, true);
    $calculated = fn () => new class extends RowCountingImport implements SkipsEmptyRows, WithCalculatedFormulas, WithHeadingRow {};

    try {
        expect(SheetStream::countRows($plain(), $path))->toBe(['Data' => 3])
            ->and(importedRowCount($plain(), $path))->toBe(3)
            ->and(SheetStream::countRows($calculated(), $path))->toBe(['Data' => 2])
            ->and(importedRowCount($calculated(), $path))->toBe(2);
    } finally {
        @unlink($path);
    }
})->with('count drivers');

it('counts the same rows as an import combining reader options, calculated formulas and format data', function (string $driver) {
    useCountDriver($driver);
    $path = countWorkbook(['Data' => blankRowsFixture()]);

    $make = fn () => new class(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true)) extends RowCountingImport implements SkipsEmptyRows, WithCalculatedFormulas, WithFormatData, WithHeadingRow, WithReaderOptions {};

    try {
        $expected = importedRowCount($make(), $path);

        expect(SheetStream::countRows($make(), $path))->toBe(['Data' => $expected])
            ->and($expected)->toBe(5);
    } finally {
        @unlink($path);
    }
})->with('count drivers');

it('never calls the import: no rows, no required-headings check, no queue dispatch', function (string $driver) {
    useCountDriver($driver);
    Bus::fake();
    $path = countWorkbook(['Data' => [['Name'], ['Alice'], ['Bob']]]);

    $import = new class extends RowCountingImport implements ShouldQueue, WithHeadingRow, WithRequiredHeadings
    {
        public function requiredHeadings(): array
        {
            return ['not_in_the_file'];
        }
    };

    try {
        expect(SheetStream::countRows($import, $path))->toBe(['Data' => 2])
            ->and($import->delivered)->toBe(0);

        Bus::assertNothingDispatched();
    } finally {
        @unlink($path);
    }
})->with('count drivers');

it('counts a file on a filesystem disk and removes the temp copy', function (string $driver) {
    useCountDriver($driver);
    Storage::fake('s3');

    $path = countWorkbook(['Data' => blankRowsFixture()]);
    Storage::disk('s3')->put('imports/data.xlsx', file_get_contents($path));
    @unlink($path);

    $before = countTempCopies();
    $expected = importedRowCount(countingImport(true, true), 'imports/data.xlsx', 's3');

    expect(SheetStream::countRows(countingImport(true, true), 'imports/data.xlsx', 's3'))->toBe(['Data' => $expected])
        ->and($expected)->toBe(5)
        ->and(countTempCopies())->toBe($before);
})->with('count drivers');

it('removes the temp copy when the reader cannot open the disk file', function (string $driver) {
    useCountDriver($driver);
    Storage::fake('s3');
    Storage::disk('s3')->put('imports/broken.xlsx', 'not a zip archive');

    $before = countTempCopies();

    try {
        SheetStream::countRows(countingImport(true, true), 'imports/broken.xlsx', 's3');
        $threw = false;
    } catch (Throwable) {
        $threw = true;
    }

    // PhpSpreadsheet sniffs the content and reads the garbage as a one-cell CSV.
    expect($threw)->toBe($driver !== 'phpspreadsheet')
        ->and(countTempCopies())->toBe($before);
})->with('count drivers');

it('removes the temp copy when counting fails after the file was opened', function (string $driver) {
    useCountDriver($driver);
    Storage::fake('s3');

    $path = countWorkbook(['Data' => [['Name'], ['Alice']]]);
    Storage::disk('s3')->put('imports/data.xlsx', file_get_contents($path));
    @unlink($path);

    $import = new class implements WithMultipleSheets
    {
        public function sheets(): array
        {
            throw new RuntimeException('sheets() blew up');
        }
    };

    $before = countTempCopies();

    expect(fn () => SheetStream::countRows($import, 'imports/data.xlsx', 's3'))
        ->toThrow(RuntimeException::class, 'sheets() blew up')
        ->and(countTempCopies())->toBe($before);
})->with('count drivers');

it('reports a missing disk file clearly and leaves no temp copy behind', function () {
    Storage::fake('s3');

    $before = countTempCopies();

    expect(fn () => SheetStream::countRows(countingImport(true, true), 'imports/missing.xlsx', 's3'))
        ->toThrow(FileNotFoundException::class, 'imports/missing.xlsx')
        ->and(countTempCopies())->toBe($before);
});

it('gives the totals a progress bar needs', function (string $driver) {
    useCountDriver($driver);
    $path = countWorkbook([
        'People' => [['Name'], ['A'], ['B'], ['C'], ['D'], ['E']],
        'Orders' => [['Order ID'], [1], [2], [3], [4]],
    ]);

    try {
        $counts = SheetStream::countRows(countingSheets([
            'People' => countingImport(true, true),
            'Orders' => countingImport(true, true),
        ]), $path);

        $chunkSize = 2;

        expect(array_sum($counts))->toBe(9)
            ->and(array_sum(array_map(fn (int $rows) => (int) ceil($rows / $chunkSize), $counts)))->toBe(5);
    } finally {
        @unlink($path);
    }
})->with('count drivers');
