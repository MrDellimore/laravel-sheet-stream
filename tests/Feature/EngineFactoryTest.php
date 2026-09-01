<?php

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use MrDellimore\SheetStream\Facades\SheetStream;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleArrayImport;
use MrDellimore\SheetStream\Tests\Fixtures\XlsxFixtureBuilder;

it('creates an OpenSpout reader via the factory', function () {
    $reader = EngineFactory::reader('openspout');

    expect($reader)->toBeInstanceOf(OpenSpoutReader::class);
});

it('creates an OpenSpout writer via the factory', function () {
    $writer = EngineFactory::writer('openspout', 'xlsx');

    expect($writer)->toBeInstanceOf(OpenSpoutWriter::class);
});

it('throws for unsupported reader driver', function () {
    EngineFactory::reader('nonexistent');
})->throws(InvalidArgumentException::class, 'Unsupported reader driver: nonexistent');

it('throws for unsupported writer driver', function () {
    EngineFactory::writer('nonexistent', 'xlsx');
})->throws(InvalidArgumentException::class, 'Unsupported writer driver: nonexistent');

it('reads default_reader from config', function () {
    $fixture = (new XlsxFixtureBuilder)->write([
        ['Name', 'Email'],
        ['Alice', 'alice@example.com'],
    ]);

    $import = new SimpleArrayImport;
    SheetStream::import($import, $fixture->path());

    expect($import->result)->toHaveCount(1);
});

it('rejects CSV multi-sheet exports early in the manager', function () {
    $export = new class implements WithMultipleSheets
    {
        public function sheets(): array
        {
            return [
                new class implements FromCollection, WithHeadings
                {
                    public function collection(): Collection
                    {
                        return new Collection([['a']]);
                    }

                    public function headings(): array
                    {
                        return ['Col'];
                    }
                },
            ];
        }
    };

    SheetStream::download($export, 'test.csv');
})->throws(UnsupportedByEngine::class, 'CSV/TSV format does not support multiple sheets');
