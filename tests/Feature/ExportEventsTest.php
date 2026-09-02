<?php

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeExport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Events\BeforeWriting;
use MrDellimore\SheetStream\Exports\ExportRunner;
use MrDellimore\SheetStream\Tests\Fixtures\EventTrackingExport;
use MrDellimore\SheetStream\Tests\Fixtures\SimpleCollectionExport;

it('fires BeforeExport, BeforeSheet, AfterSheet, BeforeWriting in correct order', function () {
    $rows = [
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ];

    $export = new EventTrackingExport($rows);
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('export_events_', true).'.xlsx';

    try {
        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run($export, $writer);
        $writer->close();

        $classes = array_column($export->firedEvents, 'class');

        expect($classes)->toBe([
            BeforeExport::class,
            BeforeSheet::class,
            AfterSheet::class,
            BeforeWriting::class,
        ]);

        // Verify event properties
        $beforeExport = $export->firedEvents[0]['event'];
        expect($beforeExport->export)->toBe($export);

        $beforeSheet = $export->firedEvents[1]['event'];
        expect($beforeSheet->sheetIndex)->toBe(0);
    } finally {
        @unlink($tmp);
    }
});

it('does not crash when export does not implement WithEvents', function () {
    $export = new SimpleCollectionExport([
        ['Name' => 'Alice', 'Email' => 'alice@example.com'],
    ]);

    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('export_noevents_', true).'.xlsx';

    try {
        $writer = new OpenSpoutWriter('xlsx');
        $writer->openToFile($tmp);
        (new ExportRunner)->run($export, $writer);
        $writer->close();

        expect(file_exists($tmp))->toBeTrue()->and(filesize($tmp))->toBeGreaterThan(0);
    } finally {
        @unlink($tmp);
    }
});
