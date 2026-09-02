<?php

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\FromGenerator;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Imports\ImportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

it('throws when import has no output concern', function () {
    $import = new class implements WithHeadingRow {};

    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);

    $reader = new OpenSpoutReader;
    $reader->open($fixture->path());

    try {
        (new ImportRunner)->run($import, $reader);
    } finally {
        $reader->close();
    }
})->throws(InvalidConcernCombination::class, 'at least one of: ToModel, ToArray, or ToCollection');

it('throws when import has multiple output concerns', function () {
    $import = new class implements ToArray, ToCollection
    {
        public function array(array $array): void {}

        public function collection(Collection $collection): void {}
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
})->throws(InvalidConcernCombination::class, 'only one of: ToModel, ToArray, or ToCollection');

it('throws when SkipsOnFailure is used without WithValidation', function () {
    $import = new class implements SkipsOnFailure, ToArray
    {
        public function array(array $array): void {}

        public function onFailure(Collection $failures): void {}
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
})->throws(InvalidConcernCombination::class, 'SkipsOnFailure requires WithValidation');

it('throws when export has no data source concern', function () {
    $export = new class implements WithHeadings
    {
        public function headings(): array
        {
            return ['Name'];
        }
    };

    $path = tempnam(sys_get_temp_dir(), 'test_').'.xlsx';
    $writer = new OpenSpoutWriter('xlsx');
    $writer->openToFile($path);

    try {
        (new ExportRunner)->run($export, $writer);
    } finally {
        $writer->close();
        @unlink($path);
    }
})->throws(InvalidConcernCombination::class, 'at least one of: FromView, FromCollection, FromQuery, or FromGenerator');

it('throws when export has multiple data source concerns', function () {
    $export = new class implements FromCollection, FromGenerator, WithHeadings
    {
        public function collection(): Collection
        {
            return new Collection;
        }

        public function generator(): Generator
        {
            yield [];
        }

        public function headings(): array
        {
            return ['Name'];
        }
    };

    $path = tempnam(sys_get_temp_dir(), 'test_').'.xlsx';
    $writer = new OpenSpoutWriter('xlsx');
    $writer->openToFile($path);

    try {
        (new ExportRunner)->run($export, $writer);
    } finally {
        $writer->close();
        @unlink($path);
    }
})->throws(InvalidConcernCombination::class, 'only one of: FromCollection, FromQuery, or FromGenerator');

it('validates multi-sheet sub-imports eagerly', function () {
    $badSubImport = new class implements WithHeadingRow {};

    $import = new readonly class($badSubImport) implements WithMultipleSheets
    {
        public function __construct(private object $sub) {}

        public function sheets(): array
        {
            return [0 => $this->sub];
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
})->throws(InvalidConcernCombination::class, 'at least one of: ToModel, ToArray, or ToCollection');
