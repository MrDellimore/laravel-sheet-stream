<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use MrDellimore\SheetStream\Concerns\OnEachRow;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithParallelSheets;
use MrDellimore\SheetStream\Concerns\WithRequiredHeadings;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Exceptions\MissingHeadingsException;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Jobs\QueuedImportJob;
use MrDellimore\SheetStream\Jobs\QueuedSheetImportJob;
use MrDellimore\SheetStream\Support\RowHelper;
use MrDellimore\SheetStream\Tests\Fixtures\BlankCellsFixtures;
use MrDellimore\SheetStream\Tests\Fixtures\BlankCellsXlsxFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\QueuedArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;

/*
 * `blank_cells` (default null) decides what a blank cell is when it reaches the import:
 * null like Laravel Excel, or '' to leave what the reader yields. Only the exact empty
 * string is a blank; 0, '0', false and whitespace are values on every path.
 */

afterEach(fn () => BlankCellsFixtures::cleanup());

/** Point the package at a driver, skipping the test when the rust extension isn't loaded. */
function useBlankCellsDriver(string $driver): void
{
    if (! BlankCellsFixtures::driverAvailable($driver)) {
        test()->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    config()->set('sheet-stream.default_reader', $driver);
}

/** @return array{0: string, 1: object|null} path and the object keeping the file alive */
function blankCellsFile(string $format): array
{
    if ($format === 'csv') {
        return [BlankCellsFixtures::csv(), null];
    }

    $fixture = BlankCellsFixtures::xlsx();

    return [$fixture->path(), $fixture];
}

/** An import for one of the four output modes that records every row it is handed. */
function blankCellsImport(string $mode, bool $headingRow): object
{
    return match ([$mode, $headingRow]) {
        ['array', true] => new class implements ToArray, WithHeadingRow
        {
            public array $rows = [];

            public function array(array $array): void
            {
                $this->rows = $array;
            }
        },
        ['array', false] => new class implements ToArray
        {
            public array $rows = [];

            public function array(array $array): void
            {
                $this->rows = $array;
            }
        },
        ['collection', true] => new class implements ToCollection, WithHeadingRow
        {
            public array $rows = [];

            public function collection(Collection $collection): void
            {
                $this->rows = $collection->map(fn ($row) => $row instanceof Collection ? $row->all() : $row)->values()->all();
            }
        },
        ['collection', false] => new class implements ToCollection
        {
            public array $rows = [];

            public function collection(Collection $collection): void
            {
                $this->rows = $collection->map(fn ($row) => $row instanceof Collection ? $row->all() : $row)->values()->all();
            }
        },
        ['each-row', true] => new class implements OnEachRow, WithHeadingRow
        {
            public array $rows = [];

            public function onRow(array $row): void
            {
                $this->rows[] = $row;
            }
        },
        ['each-row', false] => new class implements OnEachRow
        {
            public array $rows = [];

            public function onRow(array $row): void
            {
                $this->rows[] = $row;
            }
        },
        ['model', true] => new class implements ToModel, WithHeadingRow
        {
            public array $rows = [];

            public function model(array $row): ?Model
            {
                $this->rows[] = $row;

                return null;
            }
        },
        ['model', false] => new class implements ToModel
        {
            public array $rows = [];

            public function model(array $row): ?Model
            {
                $this->rows[] = $row;

                return null;
            }
        },
    };
}

dataset('blank cell drivers and formats', BlankCellsFixtures::driversAndFormats());
dataset('blank cell drivers', BlankCellsFixtures::DRIVERS);
dataset('blank cell output modes', ['array', 'collection', 'each-row', 'model']);
dataset('blank cell settings', ['null (default)' => [null], "'' (opt-out)" => ['']]);

// ── What the readers yield (the reason the option exists) ────────────────────

it('pins what each reader yields for blank cells before any normalization', function (string $driver, string $format) {
    useBlankCellsDriver($driver);
    [$path, $keep] = blankCellsFile($format);

    $reader = EngineFactory::reader($driver);
    $reader->open($path);

    $rows = [];
    foreach ($reader->sheets() as $sheet) {
        foreach ($sheet->rows() as $row) {
            $rows[] = array_values($row);
        }
    }
    $reader->close();

    $headings = $format === 'xlsx' ? BlankCellsFixtures::XLSX_HEADINGS : BlankCellsFixtures::CSV_HEADINGS;

    expect($rows)->toBe([$headings, ...BlankCellsFixtures::rawRows($driver, $format)]);
})->with('blank cell drivers and formats');

// ── Every output mode, with and without a heading row ────────────────────────

it('hands blank cells to the import as configured', function (string $driver, string $format, string $mode, mixed $setting) {
    useBlankCellsDriver($driver);
    config()->set('sheet-stream.blank_cells', $setting);
    [$path, $keep] = blankCellsFile($format);

    $import = blankCellsImport($mode, headingRow: true);
    SheetStream::import($import, $path);

    expect($import->rows)->toBe(BlankCellsFixtures::keyedRows($driver, $format, $setting === null));
})->with('blank cell drivers and formats')->with('blank cell output modes')->with('blank cell settings');

it('hands blank cells to an import without a heading row as configured', function (string $driver, string $format, string $mode, mixed $setting) {
    useBlankCellsDriver($driver);
    config()->set('sheet-stream.blank_cells', $setting);
    [$path, $keep] = blankCellsFile($format);

    $import = blankCellsImport($mode, headingRow: false);
    SheetStream::import($import, $path);

    $headings = $format === 'xlsx' ? BlankCellsFixtures::XLSX_HEADINGS : BlankCellsFixtures::CSV_HEADINGS;

    expect($import->rows)->toBe([$headings, ...BlankCellsFixtures::listRows($driver, $format, $setting === null)]);
})->with('blank cell drivers and formats')->with('blank cell output modes')->with('blank cell settings');

it('defaults to null when the published config predates the option', function () {
    $config = config('sheet-stream');
    unset($config['blank_cells']);
    config()->set('sheet-stream', $config);

    $fixture = BlankCellsFixtures::xlsx();
    $import = new SimpleArrayImport;
    SheetStream::import($import, $fixture->path());

    expect($import->result[0]['gap'])->toBeNull()
        ->and($import->result[0]['inline_empty'])->toBeNull();
});

it('ships null as the packaged default', function () {
    $packaged = require __DIR__.'/../../config/sheet-stream.php';

    expect($packaged)->toHaveKey('blank_cells')
        ->and($packaged['blank_cells'])->toBeNull()
        ->and(array_key_exists('blank_cells', config('sheet-stream')))->toBeTrue();
});

it('makes a directly constructed runner null blanks unless told otherwise', function () {
    $fixture = BlankCellsFixtures::xlsx();

    $run = function (ImportRunner $runner) use ($fixture): array {
        $import = new SimpleArrayImport;
        $reader = EngineFactory::reader('openspout');
        $reader->open($fixture->path());
        $runner->run($import, $reader);
        $reader->close();

        return $import->result;
    };

    expect($run(new ImportRunner))->toBe(BlankCellsFixtures::keyedRows('openspout', 'xlsx', true))
        ->and($run(new ImportRunner(1000, 'slug', false)))->toBe(BlankCellsFixtures::keyedRows('openspout', 'xlsx', false));
});

// ── Invalid configuration ────────────────────────────────────────────────────

it('rejects a blank_cells value other than null or the empty string', function (mixed $value, string $shown) {
    config()->set('sheet-stream.blank_cells', $value);

    $fixture = BlankCellsFixtures::xlsx();
    $import = new SimpleArrayImport;

    expect(fn () => SheetStream::import($import, $fixture->path()))
        ->toThrow(InvalidArgumentException::class, "Unsupported sheet-stream.blank_cells value: expected null or '' (an empty string), got {$shown}.");

    expect($import->result)->toBe([]);
})->with([
    "the string 'null'" => ['null', "'null'"],
    "the string 'empty'" => ['empty', "'empty'"],
    'a space' => [' ', "' '"],
    'false' => [false, 'false'],
    'zero' => [0, '0'],
    'an array' => [[], 'array'],
]);

it('rejects an invalid blank_cells value before anything is queued', function () {
    Bus::fake();
    config()->set('sheet-stream.blank_cells', 'nope');

    $fixture = BlankCellsFixtures::xlsx();

    expect(fn () => SheetStream::import(new QueuedArrayImport, $fixture->path()))
        ->toThrow(InvalidArgumentException::class, 'Unsupported sheet-stream.blank_cells value');

    Bus::assertNothingDispatched();
});

// ── RowHelper ────────────────────────────────────────────────────────────────

it('replaces only exact empty strings and keeps keys and order', function () {
    $object = new stdClass;
    $date = new DateTimeImmutable('2024-01-02');

    $row = ['a' => '', 'b' => 0, 'c' => '0', 'd' => false, 'e' => ' ', 'f' => "\t", 'g' => 0.0, 'h' => null, 'i' => 'x', 'j' => $object, 'k' => $date, 'l' => '', 'm' => "\0"];

    expect(RowHelper::blanksToNull($row))->toBe(
        ['a' => null, 'b' => 0, 'c' => '0', 'd' => false, 'e' => ' ', 'f' => "\t", 'g' => 0.0, 'h' => null, 'i' => 'x', 'j' => $object, 'k' => $date, 'l' => null, 'm' => "\0"]
    )
        ->and(RowHelper::blanksToNull(['', 'x', '', 0]))->toBe([null, 'x', null, 0])
        ->and(RowHelper::blanksToNull([]))->toBe([])
        ->and(RowHelper::blanksToNull(['', '']))->toBe([null, null]);
});

it('resolves the blank_cells setting strictly', function () {
    expect(RowHelper::blankCellsAsNull(null))->toBeTrue()
        ->and(RowHelper::blankCellsAsNull(''))->toBeFalse()
        ->and(fn () => RowHelper::blankCellsAsNull(true))->toThrow(InvalidArgumentException::class, 'got true.')
        ->and(fn () => RowHelper::blankCellsAsNull(new stdClass))->toThrow(InvalidArgumentException::class, 'got stdClass.');
});

// ── Heading row ──────────────────────────────────────────────────────────────

it('still reads a heading row with blank headings', function (string $driver, mixed $setting) {
    useBlankCellsDriver($driver);
    config()->set('sheet-stream.blank_cells', $setting);

    // Headings: "Name", a gap, "Email", an explicit empty string, "Phone".
    $fixture = (new BlankCellsXlsxFixtureBuilder)->write([
        [['inline', 'Name'], null, ['inline', 'Email'], ['inline', ''], ['inline', 'Phone']],
        [['inline', 'Alice'], ['inline', 'skipped'], ['inline', 'a@example.com'], ['inline', 'under-blank'], null],
    ]);

    $import = new class implements ToArray, WithHeadingRow, WithRequiredHeadings
    {
        public array $rows = [];

        public function array(array $array): void
        {
            $this->rows = $array;
        }

        public function requiredHeadings(): array
        {
            return ['name', 'email', 'phone'];
        }
    };

    SheetStream::import($import, $fixture->path());

    // Both blank headings normalize to the key '', so the later column wins, as before.
    expect($import->rows)->toBe([
        ['name' => 'Alice', '' => 'under-blank', 'email' => 'a@example.com', 'phone' => null],
    ]);
})->with('blank cell drivers')->with('blank cell settings');

it('still reports a missing required heading when the heading row has blanks', function (mixed $setting) {
    config()->set('sheet-stream.blank_cells', $setting);

    $fixture = (new BlankCellsXlsxFixtureBuilder)->write([
        [['inline', 'Name'], null, ['inline', '']],
        [['inline', 'Alice']],
    ]);

    $import = new class implements ToArray, WithHeadingRow, WithRequiredHeadings
    {
        public bool $called = false;

        public function array(array $array): void
        {
            $this->called = true;
        }

        public function requiredHeadings(): array
        {
            return ['name', 'email'];
        }
    };

    expect(fn () => SheetStream::import($import, $fixture->path()))
        ->toThrow(MissingHeadingsException::class, 'The following required headings are missing in sheet "Sheet1": email');

    expect($import->called)->toBeFalse();
})->with('blank cell settings');

// ── SkipsEmptyRows ───────────────────────────────────────────────────────────

it('skips the same rows under SkipsEmptyRows whatever blank_cells is', function (string $driver, mixed $setting) {
    useBlankCellsDriver($driver);
    config()->set('sheet-stream.blank_cells', $setting);

    $path = BlankCellsFixtures::csv("name,qty,note\n,,\nAlice,,\n\"\",\"\",\"\"\n,0,\n,,\"  \"\nBob,2,ok\n");

    $import = new class implements SkipsEmptyRows, ToArray, WithHeadingRow
    {
        public array $rows = [];

        public function array(array $array): void
        {
            $this->rows = $array;
        }
    };

    SheetStream::import($import, $path);

    $blank = $setting === null || $driver === 'phpspreadsheet' ? null : '';
    $zero = $driver === 'phpspreadsheet' ? 0 : '0';
    $two = $driver === 'phpspreadsheet' ? 2 : '2';

    // The all-blank rows are gone; a row holding only 0 or only whitespace is data.
    expect($import->rows)->toBe([
        ['name' => 'Alice', 'qty' => $blank, 'note' => $blank],
        ['name' => $blank, 'qty' => $zero, 'note' => $blank],
        ['name' => $blank, 'qty' => $blank, 'note' => '  '],
        ['name' => 'Bob', 'qty' => $two, 'note' => 'ok'],
    ]);
})->with('blank cell drivers')->with('blank cell settings');

it('delivers an all-blank row as nulls when empty rows are not skipped', function () {
    config()->set('sheet-stream.default_reader', 'openspout');

    $path = BlankCellsFixtures::csv("name,qty\n,\nBob,2\n");
    $import = new SimpleArrayImport;
    SheetStream::import($import, $path);

    expect($import->result)->toBe([
        ['name' => null, 'qty' => null],
        ['name' => 'Bob', 'qty' => '2'],
    ]);
});

// ── Validation ───────────────────────────────────────────────────────────────

/** A validating import whose rules and failure handling the test picks. */
function blankCellsValidatingImport(array $rules, bool $skipsOnFailure): object
{
    if ($skipsOnFailure) {
        return new class($rules) implements SkipsOnFailure, ToArray, WithHeadingRow, WithValidation
        {
            public array $rows = [];

            public array $failures = [];

            public function __construct(private readonly array $rules) {}

            public function array(array $array): void
            {
                $this->rows = $array;
            }

            public function rules(): array
            {
                return $this->rules;
            }

            public function onFailure(Collection $failures): void
            {
                $this->failures = $failures->all();
            }
        };
    }

    return new class($rules) implements ToArray, WithHeadingRow, WithValidation
    {
        public array $rows = [];

        public function __construct(private readonly array $rules) {}

        public function array(array $array): void
        {
            $this->rows = $array;
        }

        public function rules(): array
        {
            return $this->rules;
        }
    };
}

it('validates a blank cell as null, like Laravel Excel', function (string $driver) {
    useBlankCellsDriver($driver);

    // Row 2 has a blank qty in the middle of the row.
    $path = BlankCellsFixtures::csv("name,qty,note\nAlice,,first\nBob,2,second\n");

    // Without `nullable`, Laravel validates null against `integer` and fails it;
    // an empty string would have skipped the rule altogether.
    $import = blankCellsValidatingImport(['qty' => 'integer'], skipsOnFailure: true);
    SheetStream::import($import, $path);

    expect($import->failures)->toHaveCount(1)
        ->and($import->failures[0]->row())->toBe(2)
        ->and($import->failures[0]->errors())->toHaveKey('qty')
        ->and($import->failures[0]->values())->toBe(['name' => 'Alice', 'qty' => null, 'note' => 'first'])
        ->and(array_column($import->rows, 'name'))->toBe(['Bob']);
})->with('blank cell drivers');

it('throws for a blank cell failing a non-nullable rule when failures are not skipped', function () {
    config()->set('sheet-stream.default_reader', 'openspout');

    $path = BlankCellsFixtures::csv("name,qty,note\nAlice,,first\n");
    $import = blankCellsValidatingImport(['qty' => 'integer'], skipsOnFailure: false);

    expect(fn () => SheetStream::import($import, $path))->toThrow(ValidationException::class)->and($import->rows)->toBe([]);
});

it('lets the empty-string opt-out skip non-implicit rules as before', function () {
    config()->set('sheet-stream.default_reader', 'openspout');
    config()->set('sheet-stream.blank_cells', '');

    $path = BlankCellsFixtures::csv("name,qty,note\nAlice,,first\nBob,2,second\n");
    $import = blankCellsValidatingImport(['qty' => 'integer'], skipsOnFailure: true);
    SheetStream::import($import, $path);

    expect($import->failures)->toBe([])
        ->and($import->rows)->toBe([
            ['name' => 'Alice', 'qty' => '', 'note' => 'first'],
            ['name' => 'Bob', 'qty' => '2', 'note' => 'second'],
        ]);
});

it('treats a blank cell the same under required and nullable in both modes', function (mixed $setting) {
    config()->set('sheet-stream.default_reader', 'openspout');
    config()->set('sheet-stream.blank_cells', $setting);

    $path = BlankCellsFixtures::csv("name,qty,note\nAlice,,first\n,3,second\nBob,2,third\n");
    $import = blankCellsValidatingImport(['name' => 'required', 'qty' => 'nullable|integer'], skipsOnFailure: true);
    SheetStream::import($import, $path);

    expect($import->failures)->toHaveCount(1)
        ->and($import->failures[0]->row())->toBe(3)
        ->and(array_keys($import->failures[0]->errors()))->toBe(['name'])
        ->and(array_column($import->rows, 'name'))->toBe(['Alice', 'Bob']);
})->with('blank cell settings');

it('never fails required for 0, the string 0, false or whitespace-preserved values', function () {
    config()->set('sheet-stream.default_reader', 'openspout');

    $fixture = (new BlankCellsXlsxFixtureBuilder)->write([
        [['inline', 'zero'], ['inline', 'zero_str'], ['inline', 'flag']],
        [['number', '0'], ['shared', '0'], ['bool', false]],
    ]);

    $import = blankCellsValidatingImport(
        ['zero' => 'required|integer', 'zero_str' => 'required|string', 'flag' => 'required|boolean'],
        skipsOnFailure: true,
    );
    SheetStream::import($import, $fixture->path());

    expect($import->failures)->toBe([])
        ->and($import->rows)->toBe([['zero' => 0, 'zero_str' => '0', 'flag' => false]]);
});

// ── Queued jobs ──────────────────────────────────────────────────────────────

it('captures the setting on the queued job at dispatch time', function (mixed $setting, bool $expected) {
    Bus::fake();
    config()->set('sheet-stream.blank_cells', $setting);

    $fixture = BlankCellsFixtures::xlsx();
    $pending = SheetStream::import(new QueuedArrayImport, $fixture->path());
    unset($pending);

    Bus::assertDispatched(QueuedImportJob::class, fn (QueuedImportJob $job) => $job->blankCellsAsNull === $expected);
})->with([
    'null' => [null, true],
    "''" => ['', false],
]);

it('runs a queued import with the captured setting, not the config at run time', function (string $driver, string $format, bool $captured) {
    useBlankCellsDriver($driver);
    [$path, $keep] = blankCellsFile($format);

    // The worker's config says the opposite of what was captured at dispatch.
    config()->set('sheet-stream.blank_cells', $captured ? '' : null);

    $import = new QueuedArrayImport;
    new QueuedImportJob(import: $import, filePath: $path, blankCellsAsNull: $captured)->handle();

    expect($import->result)->toBe(BlankCellsFixtures::keyedRows($driver, $format, $captured));
})->with('blank cell drivers and formats')->with([true, false]);

it('follows the config at run time for a payload queued before the option existed', function (mixed $setting) {
    $fixture = BlankCellsFixtures::xlsx();
    config()->set('sheet-stream.default_reader', 'openspout');
    config()->set('sheet-stream.blank_cells', $setting);

    // An old payload has no blankCellsAsNull entry at all.
    $state = new QueuedImportJob(import: new QueuedArrayImport, filePath: $fixture->path())->__serialize();
    $state = array_filter($state, fn (string $key) => ! str_contains($key, 'blankCellsAsNull'), ARRAY_FILTER_USE_KEY);

    $job = new ReflectionClass(QueuedImportJob::class)->newInstanceWithoutConstructor();
    $job->__unserialize($state);

    expect($job->blankCellsAsNull)->toBeNull();

    $job->handle();

    expect($job->import->result)->toBe(BlankCellsFixtures::keyedRows('openspout', 'xlsx', $setting === null));
})->with('blank cell settings');

it('survives a serialize round trip with the setting intact', function (?bool $value) {
    $fixture = BlankCellsFixtures::xlsx();

    $job = unserialize(serialize(new QueuedImportJob(import: new QueuedArrayImport, filePath: $fixture->path(), blankCellsAsNull: $value)));

    expect($job->blankCellsAsNull)->toBe($value);
})->with([true, false, null]);

it('passes the setting on to parallel sheet jobs and applies it there', function (bool $captured) {
    Bus::fake([QueuedSheetImportJob::class]);
    config()->set('sheet-stream.default_reader', 'openspout');
    config()->set('sheet-stream.blank_cells', $captured ? '' : null);

    $fixture = BlankCellsFixtures::xlsx();

    $import = new class implements ShouldQueue, WithMultipleSheets, WithParallelSheets
    {
        public SimpleArrayImport $sheet;

        public function __construct()
        {
            $this->sheet = new SimpleArrayImport;
        }

        public function sheets(): array
        {
            return [0 => $this->sheet];
        }
    };

    new QueuedImportJob(import: $import, filePath: $fixture->path(), blankCellsAsNull: $captured)->handle();

    $jobs = Bus::dispatched(QueuedSheetImportJob::class);
    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]->blankCellsAsNull)->toBe($captured);

    $jobs[0]->handle();

    expect($import->sheet->result)->toBe(BlankCellsFixtures::keyedRows('openspout', 'xlsx', $captured));
})->with([true, false]);

it('lets a sheet job queued before the option existed follow the config at run time', function () {
    $fixture = BlankCellsFixtures::xlsx();
    config()->set('sheet-stream.default_reader', 'openspout');
    config()->set('sheet-stream.blank_cells', '');

    $sheet = new SimpleArrayImport;
    $state = new QueuedSheetImportJob(
        parentImport: new QueuedArrayImport,
        sheetImport: $sheet,
        sheetIndex: 0,
        filePath: $fixture->path(),
    )->__serialize();
    $state = array_filter($state, fn (string $key) => ! str_contains($key, 'blankCellsAsNull'), ARRAY_FILTER_USE_KEY);

    $job = new ReflectionClass(QueuedSheetImportJob::class)->newInstanceWithoutConstructor();
    $job->__unserialize($state);
    $job->handle();

    expect($job->sheetImport->result)->toBe(BlankCellsFixtures::keyedRows('openspout', 'xlsx', false));
});
