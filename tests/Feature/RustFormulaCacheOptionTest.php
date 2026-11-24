<?php

use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Tests\Fixtures\FormulaArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\FormulaXlsxFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use RustySheet\ReaderException;

/*
 * sheet-stream.formulas_without_cached_value: what a WithCalculatedFormulas import gets for a
 * formula the writing application never calculated. Only the rust reader can tell such a
 * cell from an empty one, and only from rusty_sheet 0.1.3.
 *
 * The fixture's C3 (=B3+5) has no cached value; C2 (=B2*2 → 20) and the totals do.
 */

function rustSupportsFormulaCacheOption(): bool
{
    $version = RustReader::installedVersion();

    return RustReader::isAvailable() && $version !== null && RustReader::supportsFormulaCacheOption($version);
}

function importUncachedFormulaFixture(string $driver, ?string $mode, object $import): object
{
    config()->set('sheet-stream.default_reader', $driver);

    if ($mode !== null) {
        config()->set('sheet-stream.formulas_without_cached_value', $mode);
    }

    $fixture = (new FormulaXlsxFixtureBuilder)->buildWithUncachedFormula();
    SheetStream::import($import, $fixture->path());

    return $import;
}

it('builds a fixture whose C3 formula has no cached value', function () {
    $fixture = (new FormulaXlsxFixtureBuilder)->buildWithUncachedFormula();

    $zip = new ZipArchive;
    $zip->open($fixture->path());
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($xml)->toMatch('~<c r="C3"[^>]*>\s*<f>B3\+5</f>\s*</c>~')
        ->and($xml)->toMatch('~<c r="C2"[^>]*>\s*<f>B2\*2</f>\s*<v>20</v>~');
});

it('gives null for an uncached formula by default on every reader that streams cached values', function (string $driver, ?string $mode) {
    if ($driver === 'rust' && ! RustReader::isAvailable()) {
        $this->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    $import = importUncachedFormulaFixture($driver, $mode, new FormulaArrayImport);

    expect(array_column($import->result, 'formula_result'))->toBe([20, null, 50]);
})->with([
    'openspout, key absent' => ['openspout', null],
    'openspout, null' => ['openspout', 'null'],
    'rust, key absent' => ['rust', null],
    'rust, null' => ['rust', 'null'],
]);

it('gives the formula text for an uncached formula in formula mode, and cached values elsewhere', function () {
    $import = importUncachedFormulaFixture('rust', 'formula', new FormulaArrayImport);

    expect(array_column($import->result, 'formula_result'))->toBe([20, '=B3+5', 50])
        ->and(array_column($import->result, 'value'))->toBe([10, 25, 35]);
})->skip(fn () => ! rustSupportsFormulaCacheOption(), 'Needs rusty_sheet 0.1.3 or newer.');

it('fails the import naming the cell in throw mode, after delivering nothing from the sheet', function () {
    $import = new FormulaArrayImport;

    expect(fn () => importUncachedFormulaFixture('rust', 'throw', $import))
        ->toThrow(ReaderException::class, 'C3 has a formula with no cached value (=B3+5)');

    expect($import->result)->toBe([]);
})->skip(fn () => ! rustSupportsFormulaCacheOption(), 'Needs rusty_sheet 0.1.3 or newer.');

it('does not throw in throw mode when every formula has a cached value', function () {
    config()->set('sheet-stream.default_reader', 'rust');
    config()->set('sheet-stream.formulas_without_cached_value', 'throw');

    $fixture = (new FormulaXlsxFixtureBuilder)->build();
    $import = new FormulaArrayImport;
    SheetStream::import($import, $fixture->path());

    expect(array_column($import->result, 'formula_result'))->toBe([20, 30, 50]);
})->skip(fn () => ! rustSupportsFormulaCacheOption(), 'Needs rusty_sheet 0.1.3 or newer.');

it('leaves imports without WithCalculatedFormulas alone, whatever the mode', function (string $mode) {
    if (! RustReader::isAvailable()) {
        $this->markTestSkipped('The rusty_sheet extension is not loaded.');
    }

    // No calculated formulas: every formula cell is its text, cached or not, and the
    // option is never sent, so this holds on extensions older than 0.1.3 too.
    $import = importUncachedFormulaFixture('rust', $mode, new SimpleArrayImport);

    expect(array_column($import->result, 'formula_result'))->toBe(['=B2*2', '=B3+5', '=SUM(C2:C3)']);
})->with(['null', 'throw', 'formula']);

it('is ignored by the openspout reader, which cannot tell an uncached formula from an empty cell', function (string $mode) {
    $import = importUncachedFormulaFixture('openspout', $mode, new FormulaArrayImport);

    expect(array_column($import->result, 'formula_result'))->toBe([20, null, 50]);
})->with(['throw', 'formula']);

it('rejects an unknown mode before reading anything', function (string $driver) {
    $import = new FormulaArrayImport;

    expect(fn () => importUncachedFormulaFixture($driver, 'zero', $import))
        ->toThrow(InvalidArgumentException::class, "Unsupported formulas_without_cached_value value: 'zero'");

    expect($import->result)->toBe([]);
})->with(['openspout', 'rust']);

it('rejects an unknown mode when queueing an import', function () {
    config()->set('sheet-stream.formulas_without_cached_value', 'zero');

    expect(fn () => SheetStream::queueImport(new FormulaArrayImport, 'whatever.xlsx'))
        ->toThrow(InvalidArgumentException::class);
});

it('knows which extension versions accept the option', function (string $version, bool $supported) {
    expect(RustReader::supportsFormulaCacheOption($version))->toBe($supported);
})->with([
    ['0.1.0', false],
    ['0.1.2', false],
    ['0.1.3', true],
    ['0.1.10', true],
]);

it('never sends the option in the default mode, so older extensions keep working', function () {
    $options = new ReflectionMethod(RustReader::class, 'extensionOptions')
        ->invoke(new RustReader(['calculateFormulas' => true, 'formulasWithoutCachedValue' => 'null']), 'file.xlsx');

    expect($options)->not->toHaveKey('formulas_without_cached_value')
        ->and($options['calculated_formulas'])->toBeTrue();
});

it('sends the option only together with calculated formulas', function (bool $calculate, bool $sent) {
    $options = new ReflectionMethod(RustReader::class, 'extensionOptions')
        ->invoke(new RustReader(['calculateFormulas' => $calculate, 'formulasWithoutCachedValue' => 'throw']), 'file.xlsx');

    expect(array_key_exists('formulas_without_cached_value', $options))->toBe($sent);
})->with([[true, true], [false, false]])
    ->skip(fn () => ! rustSupportsFormulaCacheOption(), 'Needs rusty_sheet 0.1.3 or newer.');

it('refuses a non-default mode on an extension too old to honour it', function () {
    $reader = new RustReader(['calculateFormulas' => true, 'formulasWithoutCachedValue' => 'throw']);
    $fixture = (new FormulaXlsxFixtureBuilder)->buildWithUncachedFormula();

    expect(fn () => $reader->open($fixture->path()))
        ->toThrow(UnsupportedByEngine::class, "formulas_without_cached_value = 'throw' needs rusty_sheet 0.1.3 or newer");
})->skip(fn () => ! RustReader::isAvailable() || rustSupportsFormulaCacheOption(), 'Only reachable on rusty_sheet 0.1.0–0.1.2.');
