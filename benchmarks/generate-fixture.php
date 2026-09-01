<?php

/**
 * Generate a large .xlsx fixture for memory benchmarking.
 *
 * Usage:  php benchmarks/generate-fixture.php [rows]
 * Default: 200000 rows
 *
 * Output: benchmarks/fixture-{rows}.xlsx
 */

require __DIR__ . '/../vendor/autoload.php';

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

$rowCount = (int) ($argv[1] ?? 200_000);
$path = __DIR__ . "/fixture-{$rowCount}.xlsx";

if (file_exists($path)) {
    echo "Fixture already exists: {$path}\n";
    echo "Delete it first if you want to regenerate.\n";
    exit(0);
}

echo "Generating {$rowCount}-row fixture...\n";

$start = microtime(true);
$writer = new Writer();
$writer->openToFile($path);

// Header row
$writer->addRow(Row::fromValues(['id', 'name', 'email', 'amount', 'date']));

for ($i = 1; $i <= $rowCount; $i++) {
    $writer->addRow(Row::fromValues([
        $i,
        "User {$i}",
        "user{$i}@example.com",
        round(mt_rand(100, 100000) / 100, 2),
        date('Y-m-d', strtotime("2024-01-01 +{$i} days")),
    ]));

    if ($i % 50000 === 0) {
        echo "  ...{$i} rows written\n";
    }
}

$writer->close();

$elapsed = round(microtime(true) - $start, 2);
$size = round(filesize($path) / 1024 / 1024, 2);

echo "Done: {$path} ({$size} MB, {$elapsed}s)\n";
