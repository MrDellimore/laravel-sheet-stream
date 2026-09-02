<?php

use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\FromView;
use MrDellimore\SheetStream\Concerns\WithTitle;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Engine\PhpSpreadsheet\PhpSpreadsheetWriter;
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\ViewExport;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

it('exports a Blade view to xlsx via PhpSpreadsheet driver', function () {
    $rows = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
    ];

    $export = new ViewExport($rows);

    $tmp = tempnam(sys_get_temp_dir(), 'from_view_test_').'.xlsx';

    try {
        $writer = new PhpSpreadsheetWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run($export, $writer);
        $writer->close();

        expect(file_exists($tmp))->toBeTrue()
            ->and(filesize($tmp))->toBeGreaterThan(0);

        // Re-read with PhpSpreadsheet to verify content
        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        expect($sheet->getTitle())->toBe('Summary');

        // Row 1: headers from <th>
        expect($sheet->getCell('A1')->getValue())->toBe('Name')
            ->and($sheet->getCell('B1')->getValue())->toBe('Email');

        // Row 2: first data row
        expect($sheet->getCell('A2')->getValue())->toBe('Alice')
            ->and($sheet->getCell('B2')->getValue())->toBe('alice@example.com');

        // Row 3: second data row
        expect($sheet->getCell('A3')->getValue())->toBe('Bob')
            ->and($sheet->getCell('B3')->getValue())->toBe('bob@example.com');

        $spreadsheet->disconnectWorksheets();
    } finally {
        @unlink($tmp);
    }
});

it('throws UnsupportedByEngine when using FromView with OpenSpout driver', function () {
    $export = new ViewExport([['name' => 'Alice', 'email' => 'alice@example.com']]);

    $tmp = tempnam(sys_get_temp_dir(), 'from_view_test_').'.xlsx';
    $writer = new OpenSpoutWriter('xlsx');
    $writer->openToFile($tmp);

    try {
        (new ExportRunner)->run($export, $writer);
    } finally {
        $writer->close();
        @unlink($tmp);
    }
})->throws(UnsupportedByEngine::class, 'FromView requires the phpspreadsheet engine driver');

it('throws when FromView is combined with FromCollection', function () {
    $export = new class implements FromCollection, FromView
    {
        public function view(): View
        {
            return view('sheet-stream-tests::export-table', ['rows' => []]);
        }

        public function collection(): Collection
        {
            return new Collection;
        }
    };

    $tmp = tempnam(sys_get_temp_dir(), 'from_view_test_').'.xlsx';
    $writer = new PhpSpreadsheetWriter('xlsx');
    $writer->openToFile($tmp);

    try {
        (new ExportRunner)->run($export, $writer);
    } finally {
        $writer->close();
        @unlink($tmp);
    }
})->throws(InvalidConcernCombination::class, 'FromView cannot be combined');

it('auto-selects phpspreadsheet driver via manager for FromView exports', function () {
    $rows = [
        ['name' => 'Alice', 'email' => 'alice@example.com'],
    ];

    $export = new ViewExport($rows);
    $filename = 'from-view-test.xlsx';

    $response = app('sheet-stream')->download($export, $filename);

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->getStatusCode())->toBe(200);
});

it('exports a view with styled HTML', function () {
    $styledExport = new class implements FromView, WithTitle
    {
        public function view(): View
        {
            // Return a view with inline styling
            return view('sheet-stream-tests::styled-export-table');
        }

        public function title(): string
        {
            return 'Styled';
        }
    };

    $tmp = tempnam(sys_get_temp_dir(), 'styled_view_test_').'.xlsx';

    try {
        $writer = new PhpSpreadsheetWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run($styledExport, $writer);
        $writer->close();

        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        // The header should be bold (from <th> or <b> tags)
        expect($sheet->getCell('A1')->getValue())->toBe('Status')
            ->and($sheet->getCell('B1')->getValue())->toBe('Count');

        // Data values should be present
        expect($sheet->getCell('A2')->getValue())->toBe('Active')
            ->and($sheet->getCell('B2')->getValue())->not->toBeNull();

        $spreadsheet->disconnectWorksheets();
    } finally {
        @unlink($tmp);
    }
});
