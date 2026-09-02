<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\FormulaArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\FormulaXlsxFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;

it('returns computed values for formula cells when WithCalculatedFormulas is used', function () {
    $fixture = (new FormulaXlsxFixtureBuilder)->build();

    $import = new FormulaArrayImport;
    $reader = new OpenSpoutReader(['calculateFormulas' => true]);
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(3);

    // Row 1: alpha, 10, =B2*2 → 20
    expect($import->result[0]['label'])->toBe('alpha')
        ->and($import->result[0]['value'])->toBe(10)
        ->and($import->result[0]['formula_result'])->toBe(20);

    // Row 2: beta, 25, =B3+5 → 30
    expect($import->result[1]['label'])->toBe('beta')
        ->and($import->result[1]['value'])->toBe(25)
        ->and($import->result[1]['formula_result'])->toBe(30);

    // Row 3: total, =SUM(B2:B3) → 35, =SUM(C2:C3) → 50
    expect($import->result[2]['label'])->toBe('total')
        ->and($import->result[2]['value'])->toBe(35)
        ->and($import->result[2]['formula_result'])->toBe(50);
});

it('returns formula strings when WithCalculatedFormulas is NOT used', function () {
    $fixture = (new FormulaXlsxFixtureBuilder)->build();

    $import = new SimpleArrayImport;
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)->toHaveCount(3);

    // Without the concern, formula cells return the formula string
    expect($import->result[0]['formula_result'])->toBeString()
        ->and($import->result[0]['formula_result'])->toStartWith('=');
});
