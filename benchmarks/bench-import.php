<?php

/**
 * Memory benchmark: import a large .xlsx via SheetStream's streaming engine.
 *
 * Usage:  php benchmarks/bench-import.php [rows]
 * Default: 200000 rows
 *
 * Requires the fixture to be generated first:
 *   php benchmarks/generate-fixture.php [rows]
 */

require __DIR__ . '/../vendor/autoload.php';

use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutReader;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutSheetReader;

$rowCount = (int) ($argv[1] ?? 200_000);
$path = __DIR__ . "/fixture-{$rowCount}.xlsx";

if (! file_exists($path)) {
    echo "Fixture not found: {$path}\n";
    echo "Run: php benchmarks/generate-fixture.php {$rowCount}\n";
    exit(1);
}

echo "=== SheetStream (OpenSpout streaming) ===\n";
echo "File: fixture-{$rowCount}.xlsx\n\n";

// Reset peak memory before the benchmark
memory_reset_peak_usage();
$startMem = memory_get_usage(true);
$startTime = microtime(true);

$reader = new OpenSpoutReader();
$reader->open($path);

$processed = 0;
$headings = null;

foreach ($reader->sheets() as $sheet) {
    foreach ($sheet->rows() as $row) {
        if ($headings === null) {
            $headings = $row;
            continue;
        }

        // Simulate a minimal import: combine headings + row (what ImportRunner does)
        $assoc = array_combine($headings, array_pad($row, count($headings), null));
        $processed++;

        if ($processed % 50000 === 0) {
            $currentMem = round(memory_get_usage(true) / 1024 / 1024, 2);
            echo "  ...{$processed} rows | current memory: {$currentMem} MB\n";
        }
    }
}

$reader->close();

$elapsed = round(microtime(true) - $startTime, 2);
$peakMem = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
$endMem = round(memory_get_usage(true) / 1024 / 1024, 2);

echo "\nResults:\n";
echo "  Rows processed:  {$processed}\n";
echo "  Wall time:       {$elapsed}s\n";
echo "  Peak memory:     {$peakMem} MB\n";
echo "  Final memory:    {$endMem} MB\n";
