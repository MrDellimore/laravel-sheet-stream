<?php

declare(strict_types=1);

use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithRequiredHeadings;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;
use MrDellimore\SheetStream\Exceptions\MissingHeadingsException;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\MultiSheetXlsxFixtureBuilder;
use MrDellimore\SheetStream\Tests\Fixtures\RequiredHeadingsImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

it('processes rows normally when all required headings are present', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
        ['Bob', 'bob@example.com'],
    ]);

    $import = new RequiredHeadingsImport(['name', 'email']);
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    (new ImportRunner)->run($import, $reader);
    $reader->close();

    expect($import->result)
        ->toHaveCount(2)
        ->and($import->result[0])->toMatchArray(['name' => 'Alice', 'email' => 'alice@example.com'])
        ->and($import->result[1])->toMatchArray(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('throws MissingHeadingsException before processing any rows when a required heading is absent', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name'],
        ['Alice'],
        ['Bob'],
    ]);

    $import = new RequiredHeadingsImport(['name', 'email']);
    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    try {
        (new ImportRunner)->run($import, $reader);
    } finally {
        $reader->close();
    }

    expect($import->result)->toBeEmpty();
})->throws(MissingHeadingsException::class, 'email');

it('throws InvalidConcernCombination when WithRequiredHeadings is used without WithHeadingRow', function () {
    $import = new class implements ToArray, WithRequiredHeadings
    {
        public function array(array $array): void {}

        public function requiredHeadings(): array
        {
            return ['name'];
        }
    };

    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name'],
        ['Alice'],
    ]);

    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    try {
        (new ImportRunner)->run($import, $reader);
    } finally {
        $reader->close();
    }
})->throws(InvalidConcernCombination::class, 'WithRequiredHeadings requires WithHeadingRow');

it('throws MissingHeadingsException for a failing sheet in a multi-sheet import without processing its data rows', function () {
    $fixture = (new MultiSheetXlsxFixtureBuilder)->write([
        'Users' => [
            ['Name', 'Email'],
            ['Alice', 'alice@example.com'],
        ],
        'Orders' => [
            ['Product'],
            ['Widget'],
        ],
    ]);

    $usersImport = new RequiredHeadingsImport(['name', 'email']);
    $ordersImport = new RequiredHeadingsImport(['product', 'qty']);

    $import = new readonly class($usersImport, $ordersImport) implements WithMultipleSheets
    {
        public function __construct(
            private object $users,
            private object $orders,
        ) {}

        public function sheets(): array
        {
            return [
                0 => $this->users,
                1 => $this->orders,
            ];
        }
    };

    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    try {
        (new ImportRunner)->run($import, $reader);
    } finally {
        $reader->close();
    }

    expect($ordersImport->result)->toBeEmpty();
})->throws(MissingHeadingsException::class, 'qty');
