<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Engine\Rust\RustSheetReader;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Imports\FileInspector;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

dataset('header drivers', ['openspout', 'phpspreadsheet', 'rust']);

function useHeaderDriver(string $driver): void
{
    if ($driver === 'rust' && ! RustReader::isAvailable()) {
        test()->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    config(['sheet-stream.default_reader' => $driver]);
}

function headerTempFile(string $extension, string $contents = ''): string
{
    $path = sys_get_temp_dir().'/'.uniqid('headers_fixture_', true).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

/**
 * A workbook the way Excel saves one: a normal sheet, a sheet whose first row is blank,
 * a sheet with nothing in it, a header with gaps, and a second normal sheet.
 */
function headerWorkbook(): string
{
    $spreadsheet = new Spreadsheet;

    $people = $spreadsheet->getActiveSheet()->setTitle('People');
    $people->fromArray([['First Name', 'E-mail Address'], ['Alice', 'alice@example.com'], ['Bob', 'bob@example.com']]);

    $blankFirst = $spreadsheet->createSheet()->setTitle('Blank first');
    $blankFirst->setCellValue('A2', 'Claim Number');
    $blankFirst->setCellValue('B2', 'Amount');
    $blankFirst->setCellValue('A3', 'C-1');

    $spreadsheet->createSheet()->setTitle('Empty');

    $gap = $spreadsheet->createSheet()->setTitle('Gap');
    $gap->setCellValue('B1', 'Two');
    $gap->setCellValue('D1', 'Four');
    $gap->setCellValue('A2', 1);

    $orders = $spreadsheet->createSheet()->setTitle('Orders');
    $orders->fromArray([['Order ID', 'Total'], [1, 9.5]]);

    $path = headerTempFile('xlsx');
    new Xlsx($spreadsheet)->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

function headerTempCopies(): array
{
    return glob((config('sheet-stream.temp_path') ?? sys_get_temp_dir()).'/sheet_stream_headers_*') ?: [];
}

it('returns the first row of every sheet keyed by sheet name in workbook order', function (string $driver) {
    useHeaderDriver($driver);
    $path = headerWorkbook();

    // An interior blank is whatever the driver yields for an empty cell.
    $blank = $driver === 'phpspreadsheet' ? null : '';

    try {
        $headers = SheetStream::headers($path);

        expect($headers)->toBe([
            'People' => ['First Name', 'E-mail Address'],
            // Row 1 is the header even when blank: row 2 is never promoted.
            'Blank first' => [],
            'Empty' => [],
            'Gap' => [$blank, 'Two', $blank, 'Four'],
            'Orders' => ['Order ID', 'Total'],
        ])
            // Positional access by sheet index stays aligned because no sheet is dropped.
            ->and(array_values($headers)[4])->toBe(['Order ID', 'Total'])
            ->and(array_filter($headers))->toHaveKeys(['People', 'Gap', 'Orders'])->toHaveCount(3);
    } finally {
        @unlink($path);
    }
})->with('header drivers');

it('returns the headings as WithHeadingRow keys them when asked for the formatted form', function (string $driver) {
    useHeaderDriver($driver);
    $path = headerWorkbook();

    try {
        expect(SheetStream::headers($path, formatted: true))->toBe([
            'People' => ['first_name', 'e_mail_address'],
            'Blank first' => [],
            'Empty' => [],
            'Gap' => ['', 'two', '', 'four'],
            'Orders' => ['order_id', 'total'],
        ]);

        config(['sheet-stream.heading_formatter' => 'none']);

        expect(SheetStream::headers($path, formatted: true)['People'])->toBe(['first name', 'e-mail address']);

        // The formatted headings are the keys an import of the same file receives.
        config(['sheet-stream.heading_formatter' => 'slug']);
        $import = new SimpleArrayImport;
        SheetStream::import($import, $path);

        expect(array_keys($import->result[0]))->toBe(SheetStream::headers($path, formatted: true)['People']);
    } finally {
        @unlink($path);
    }
})->with('header drivers');

it('reads the header of a CSV file', function (string $driver) {
    useHeaderDriver($driver);
    $path = headerTempFile('csv', "Claim Number,Amount,\nC-1,10\nC-2,20\n");

    try {
        $headers = SheetStream::headers($path);

        // A CSV is one sheet; its name is the driver's ('' or PhpSpreadsheet's "Worksheet").
        expect($headers)->toHaveCount(1)
            ->and(array_values($headers)[0])->toBe(['Claim Number', 'Amount'])
            ->and(array_values(SheetStream::headers($path, formatted: true))[0])->toBe(['claim_number', 'amount']);
    } finally {
        @unlink($path);
    }
})->with('header drivers');

it('reports a blank first line of a CSV as an empty header rather than promoting line 2', function (string $driver) {
    useHeaderDriver($driver);
    $path = headerTempFile('csv', "\nClaim Number,Amount\nC-1,10\n");

    try {
        expect(array_values(SheetStream::headers($path)))->toBe([[]]);
    } finally {
        @unlink($path);
    }
})->with('header drivers');

it('returns an empty header for an empty CSV file', function (string $driver) {
    useHeaderDriver($driver);
    $path = headerTempFile('csv');

    try {
        expect(array_values(SheetStream::headers($path)))->toBe([[]]);
    } finally {
        @unlink($path);
    }
})->with('header drivers');

it('reads .ods headers, through the OpenSpout fallback on the rust driver', function (string $driver) {
    useHeaderDriver($driver);
    $path = headerTempFile('ods');

    $writer = new OdsWriter;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Name', 'Email']));
    $writer->addRow(Row::fromValues(['Alice', 'alice@example.com']));
    $writer->close();

    try {
        expect(array_values(SheetStream::headers($path)))->toBe([['Name', 'Email']]);
    } finally {
        @unlink($path);
    }
})->with(['openspout', 'rust']);

it('reads headers from a filesystem disk and removes the temp copy', function (string $driver) {
    useHeaderDriver($driver);
    Storage::fake('s3');

    $path = headerWorkbook();
    Storage::disk('s3')->put('imports/book.xlsx', file_get_contents($path));
    @unlink($path);

    $before = headerTempCopies();

    expect(SheetStream::headers('imports/book.xlsx', 's3')['Orders'])->toBe(['Order ID', 'Total'])
        ->and(headerTempCopies())->toBe($before);
})->with('header drivers');

it('removes the temp copy when the reader cannot open the disk file', function (string $driver) {
    useHeaderDriver($driver);
    Storage::fake('s3');
    Storage::disk('s3')->put('imports/broken.xlsx', 'not a zip archive');

    $before = headerTempCopies();

    try {
        SheetStream::headers('imports/broken.xlsx', 's3');
        $threw = false;
    } catch (Throwable) {
        $threw = true;
    }

    // PhpSpreadsheet sniffs the content and reads the garbage as a one-cell CSV.
    expect($threw)->toBe($driver !== 'phpspreadsheet')
        ->and(headerTempCopies())->toBe($before);
})->with('header drivers');

it('reports a missing disk file clearly and leaves no temp copy behind', function () {
    Storage::fake('s3');

    $before = headerTempCopies();

    expect(fn () => SheetStream::headers('imports/missing.xlsx', 's3'))
        ->toThrow(FileNotFoundException::class, 'imports/missing.xlsx')
        ->and(headerTempCopies())->toBe($before);
});

it('leaves a local source file in place', function () {
    $fixture = (new XlsxFixtureBuilder)->write([['Name'], ['Alice']]);

    SheetStream::headers($fixture->path());

    expect(file_exists($fixture->path()))->toBeTrue();
});

it('pulls exactly one row from a sheet reader', function () {
    $sheet = new class implements SheetReader
    {
        public int $pulled = 0;

        public function name(): string
        {
            return 'Spy';
        }

        public function rows(): iterable
        {
            foreach ([[5 => 'Name', 9 => 'Email'], ['Alice', 'a@example.com'], ['Bob', 'b@example.com']] as $row) {
                $this->pulled++;

                yield $row;
            }
        }
    };

    expect(FileInspector::firstRow($sheet))->toBe(['Name', 'Email'])
        ->and($sheet->pulled)->toBe(1);
});

it('returns null for a sheet reader without rows', function () {
    $sheet = new class implements SheetReader
    {
        public function name(): string
        {
            return 'Empty';
        }

        public function rows(): iterable
        {
            return [];
        }
    };

    expect(FileInspector::firstRow($sheet))->toBeNull();
});

it('stops after the first row on the streaming OpenSpout driver', function () {
    // The driver's rows() is a generator over OpenSpout's row iterator: abandoning it after
    // one row must leave the rest unread, which shows as the rows still being there to read.
    $fixture = (new XlsxFixtureBuilder)->write([['Name'], ['Alice'], ['Bob']]);

    $reader = EngineFactory::reader('openspout');
    $reader->open($fixture->path());

    try {
        foreach ($reader->sheets() as $sheet) {
            $rows = $sheet->rows();
            $pulled = 0;

            foreach ($rows as $row) {
                $pulled++;
                expect($row)->toBe(['Name']);

                break;
            }

            expect($pulled)->toBe(1)
                ->and($rows)->toBeInstanceOf(Generator::class)
                ->and($rows->valid())->toBeTrue();
        }
    } finally {
        $reader->close();
    }
});

it('asks the rust extension for one row only, leaving the rest of the sheet unread', function () {
    if (! RustReader::isAvailable()) {
        $this->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    $rows = [['Name', 'Email']];
    for ($i = 1; $i <= 2500; $i++) {
        $rows[] = ["name {$i}", "user{$i}@example.com"];
    }
    $fixture = (new XlsxFixtureBuilder)->write($rows);

    $reader = EngineFactory::reader('rust');
    $reader->open($fixture->path());

    try {
        foreach ($reader->sheets() as $sheet) {
            expect($sheet)->toBeInstanceOf(RustSheetReader::class)
                ->and(FileInspector::firstRow($sheet))->toBe(['Name', 'Email']);

            // The extension streams forward only, so everything after row 1 still being
            // there proves the header read consumed nothing else.
            $rest = [];
            foreach ($sheet->rows() as $row) {
                $rest[] = $row;
            }

            expect($rest)->toHaveCount(2500)
                ->and($rest[0])->toBe(['name 1', 'user1@example.com'])
                ->and($rest[2499])->toBe(['name 2500', 'user2500@example.com']);
        }
    } finally {
        $reader->close();
    }
});

it('returns null from the rust sheet reader when the sheet is empty', function () {
    if (! RustReader::isAvailable()) {
        $this->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    $path = headerWorkbook();
    $reader = EngineFactory::reader('rust');
    $reader->open($path);

    try {
        $first = [];
        foreach ($reader->sheets() as $sheet) {
            $first[$sheet->name()] = $sheet instanceof RustSheetReader ? $sheet->firstRow() : 'not rust';
        }

        // Without preserved empty rows the extension skips the blank row 1 of "Blank first".
        expect($first['Empty'])->toBeNull()
            ->and($first['People'])->toBe(['First Name', 'E-mail Address'])
            ->and($first['Blank first'])->toBe(['Claim Number', 'Amount']);
    } finally {
        $reader->close();
        @unlink($path);
    }
});

it('converts a date-styled header cell to a serial on the rust driver with a timezone', function () {
    if (! RustReader::isAvailable()) {
        $this->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 45823);
    $sheet->getStyle('A1')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
    $sheet->setCellValue('B1', 'Total');
    $path = headerTempFile('xlsx');
    new Xlsx($spreadsheet)->save($path);
    $spreadsheet->disconnectWorksheets();

    config(['sheet-stream.default_reader' => 'rust', 'sheet-stream.dates.timezone' => 'UTC']);

    try {
        expect(array_values(SheetStream::headers($path))[0])->toBe([45823, 'Total']);
    } finally {
        @unlink($path);
    }
});
