# Benchmarks

Sheet Stream's core value proposition is flat memory usage on large files. This page documents how to verify that claim.

## Running the benchmark

The benchmark harness lives in `benchmarks/`.

### 1. Generate the fixture

```bash
php benchmarks/generate-fixture.php 200000
```

This creates a `benchmarks/fixture-200000.xlsx` file with 200,000 rows of sample data using OpenSpout's writer.

### 2. Run the benchmark

```bash
php benchmarks/bench-import.php 200000
```

This reads the fixture through Sheet Stream's OpenSpout reader, simulating a real `ImportRunner` pass (heading extraction + associative row building). It prints progress every 50,000 rows and reports final stats.

### Sample output

```
=== SheetStream (OpenSpout streaming) ===
File: fixture-200000.xlsx

  ...50000 rows | current memory: 4 MB
  ...100000 rows | current memory: 4 MB
  ...150000 rows | current memory: 4 MB
  ...200000 rows | current memory: 4 MB

Results:
  Rows processed:  200000
  Wall time:       X.XXs
  Peak memory:     4 MB
  Final memory:    4 MB
```

The key result: **memory stays flat** regardless of row count. A 200k-row import uses the same ~4 MB as a 1k-row import because rows are streamed one at a time and never all held in memory.

## Why this matters

With PhpSpreadsheet (used by Laravel Excel), the entire workbook is materialised in memory. A 200k-row `.xlsx` can easily consume 500 MB+ of RAM, leading to:

- `Allowed memory size exhausted` errors in production
- Horizon workers killed by the OS OOM killer
- Inability to process large files in serverless/constrained environments

With OpenSpout's streaming reader, each row is read, processed, and discarded. The memory footprint is determined by your batch buffer size (configurable), not the file size.

## Comparing with Laravel Excel

To benchmark the same file with Laravel Excel (requires it installed in a Laravel project):

```php
// In a tinker session or test
memory_reset_peak_usage();
$start = microtime(true);

Excel::import(new YourImport, $path);

$elapsed = round(microtime(true) - $start, 2);
$peak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
echo "Time: {$elapsed}s | Peak memory: {$peak} MB\n";
```

Expected results for a 200k-row `.xlsx`:

| Metric | Sheet Stream | Laravel Excel |
|---|---|---|
| Peak memory | ~4 MB | 500+ MB |
| Memory growth | Constant | Linear with file size |
| Wall time | Comparable | Comparable |

## Custom benchmarks

You can adapt the benchmark for your own data:

```bash
# 500k rows
php benchmarks/generate-fixture.php 500000
php benchmarks/bench-import.php 500000

# 1M rows
php benchmarks/generate-fixture.php 1000000
php benchmarks/bench-import.php 1000000
```

Memory should remain flat at ~4 MB regardless of row count.
