<?php

/**
 * Read a workbook end to end through a reader driver, the way ImportRunner does
 * (first row of each sheet = headings, rows combined with them).
 *
 * Usage:  php [-d extension=…/librusty_sheet.so] benchmarks/bench-reader.php <file> [openspout|rust|phpspreadsheet] [serial|datetime]
 */

require __DIR__.'/../vendor/autoload.php';

use MrDellimore\SheetStream\Engine\EngineFactory;

$path = $argv[1] ?? exit("usage: php benchmarks/bench-reader.php <file> [driver] [serial|datetime]\n");
$driver = $argv[2] ?? 'openspout';
$dates = $argv[3] ?? 'serial';

memory_reset_peak_usage();
$start = microtime(true);

$reader = EngineFactory::reader($driver, ['dates' => ['import_as' => $dates]]);
$reader->open($path);

$sheets = [];
foreach ($reader->sheets() as $sheet) {
    $headings = null;
    $rows = 0;
    foreach ($sheet->rows() as $row) {
        $row = array_values($row);
        if ($headings === null) {
            $headings = $row;

            continue;
        }
        $assoc = array_combine($headings, array_slice(array_pad($row, count($headings), null), 0, count($headings)));
        $rows++;
    }
    $sheets[$sheet->name()] = $rows;
}
$reader->close();

$secs = microtime(true) - $start;
$total = array_sum($sheets);
printf(
    "driver=%s dates=%s  %s\n  data rows: %d %s\n  time: %.2fs  rows/s: %s  PHP peak memory: %.1f MB\n",
    $driver, $dates, basename($path), $total, json_encode($sheets),
    $secs, number_format($total / max($secs, 1e-9)), memory_get_peak_usage(true) / 1048576,
);
