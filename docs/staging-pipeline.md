# Staging Pipeline

The staging pipeline is a two-phase producer-consumer pattern designed for importing very large spreadsheets across multiple queue workers. It splits the work into a fast **producer** phase (read + stage) and many parallel **consumer** phases (process + persist), so no single job can timeout on a large file.

## When to use it

Use the staging pipeline when:

- Your file has tens or hundreds of thousands of rows
- A single queued import job risks timing out
- You want to scale horizontally by adding more Horizon workers
- You need per-row validation on large files without OOM

For smaller files (under ~10k rows), the standard `ShouldQueue` import is simpler and sufficient.

## Quick start

Add the `UsesStagingTable` marker interface alongside `ShouldQueue`:

```php
use App\Models\Claimant;
use Illuminate\Database\Eloquent\Model;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class ClaimantsImport implements ToModel, WithHeadingRow, ShouldQueue, UsesStagingTable
{
    public function model(array $row): ?Model
    {
        return new Claimant([
            'name'  => $row['name'],
            'email' => $row['email'],
        ]);
    }
}
```

Then import as usual:

```php
SheetStream::import(new ClaimantsImport, $filePath);
```

The manager detects `UsesStagingTable` and automatically dispatches the two-phase pipeline instead of a single queued job.

---

## How it works

```
SheetStream::import(new MyImport, 'file.xlsx')
    │
    ▼
┌────────────────────────────────────────────┐
│  Phase 1: StagingProducerJob               │
│                                            │
│  1. Opens the file via the engine          │
│  2. Streams rows one at a time             │
│  3. Applies heading extraction             │
│  4. Filters empty rows (if configured)     │
│  5. Writes rows to staging storage         │
│     in batches (default: 500 rows)         │
│  6. Dispatches one consumer job per chunk  │
└──────────────┬─────────────────────────────┘
               │
               ▼  (one job per chunk)
┌──────────────┬──────────────┬──────────────┐
│  Chunk 0     │  Chunk 1     │  Chunk 2     │  ← Parallel on Horizon
│              │              │              │
│  Read rows   │  Read rows   │  Read rows   │
│  Validate    │  Validate    │  Validate    │
│  ToModel /   │  ToModel /   │  ToModel /   │
│  ToArray /   │  ToArray /   │  ToArray /   │
│  ToCollection│  ToCollection│  ToCollection│
│  Mark done   │  Mark done   │  Mark done   │
└──────────────┴──────────────┴──────────────┘
     StagingChunkProcessorJob (x N)
```

### Phase 1 -- Producer

The `StagingProducerJob` is the only job that touches the original file. It:

1. Opens the spreadsheet via the configured engine (OpenSpout or PhpSpreadsheet).
2. Streams through each row, applying heading extraction and empty-row filtering.
3. Pre-computes a **chunk number** for each row: `floor((rowNumber - 1) / chunkSize)`.
4. Writes rows to the staging storage in batches (configurable via `staging.insert_batch_size`).
5. After staging all rows, dispatches one `StagingChunkProcessorJob` per chunk.

The producer never runs import logic (no validation, no model creation). Its only job is reading the file and staging the data as fast as possible.

### Phase 2 -- Consumers

Each `StagingChunkProcessorJob` is an independent, parallelisable unit of work:

1. Reads its assigned rows from staging storage (by import ID, sheet index, and chunk number).
2. For each row, runs the import logic:
   - **WithValidation**: validates against the declared rules.
   - **SkipsOnFailure**: collects failures instead of throwing.
   - **ToModel**: calls `model()`, buffers and flushes in batches.
   - **ToArray / ToCollection**: accumulates rows, delivers once per chunk.
3. Marks rows as processed (database driver) or cleans up the chunk file (file driver).

Because each consumer job handles a fixed number of rows (default: 1000), memory stays predictable and no single job can timeout on a large file.

### Chunking algorithm

Rows are assigned to chunks sequentially:

| Row numbers | Chunk number (chunk_size=1000) |
|---|---|
| 1 -- 1000 | 0 |
| 1001 -- 2000 | 1 |
| 2001 -- 3000 | 2 |

Formula: `chunk_number = floor((row_number - 1) / chunk_size)`

---

## Staging drivers

The staging pipeline supports two storage drivers for the intermediate row data. The driver is selected via the `staging.driver` config option.

### Database driver (default)

```php
// config/sheet-stream.php
'staging' => [
    'driver' => 'database',
    'table'  => 'sheet_stream_staging',
    'insert_batch_size' => 500,
],
```

Rows are bulk-inserted into a database table. The consumer reads them via a query and marks each row as processed or failed with a timestamp.

**Setup:** publish and run the migration:

```bash
php artisan vendor:publish --tag=sheet-stream-migrations
php artisan migrate
```

**Table schema:**

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint PK | Row identifier |
| `import_id` | char(36) | UUID grouping all rows from one import |
| `sheet_index` | smallint | Which sheet the row came from |
| `sheet_name` | string | Sheet display name |
| `chunk_number` | int | Pre-computed chunk assignment |
| `row_number` | int | 1-based data row within the sheet |
| `row_data` | longText | JSON-encoded row (headings already applied) |
| `processed_at` | timestamp | Set when the chunk processor finishes a row |
| `failed_at` | timestamp | Set when row validation fails |
| `error` | text | Validation error JSON |
| `created_at` | timestamp | When the row was staged |

**Index:** `(import_id, sheet_index, chunk_number)` for fast chunk lookups.

**Pros:**
- Full per-row audit trail (processed/failed timestamps, error details)
- Retry-safe: on consumer failure, only unprocessed rows are re-read
- Works across multiple servers (shared database)

**Cons:**
- Requires a migration and database table
- Per-row `UPDATE` queries in the consumer (1 per row) add overhead

### File driver

```php
// config/sheet-stream.php
'staging' => [
    'driver' => 'file',
    'path'   => null,   // null = temp_path/sheet_stream_staging
    'insert_batch_size' => 500,
],
```

Or via environment variable:

```env
SHEET_STREAM_STAGING_DRIVER=file
```

Rows are written as NDJSON files (one file per chunk). The consumer reads the file and deletes it after processing.

**File layout:**

```
{staging_path}/{import_id}/
    s0_c0.ndjson    ← sheet 0, chunk 0
    s0_c1.ndjson    ← sheet 0, chunk 1
    s0_c2.ndjson    ← sheet 0, chunk 2
    s1_c0.ndjson    ← sheet 1, chunk 0
```

Each line in the NDJSON file is:

```json
{"row_number":1,"row_data":{"name":"Alice","email":"alice@example.com"}}
```

**Pros:**
- No database table or migration required
- No per-row `UPDATE` queries (the biggest speed gain)
- File I/O is local with no SQL parsing or network roundtrips
- Chunk files are automatically cleaned up after processing

**Cons:**
- No per-row audit trail (no `processed_at` / `failed_at` tracking)
- If a consumer job fails mid-chunk, the entire chunk is reprocessed on retry
- Requires a shared filesystem if running workers on multiple servers (NFS, EFS, etc.)

### Performance comparison

For a 100,000-row file with `chunk_size=1000` (100 chunks):

| Operation | Database driver | File driver |
|---|---|---|
| Producer writes | 200 INSERT queries | 200 file appends |
| Consumer reads | 100 SELECT queries | 100 `file_get_contents` calls |
| Per-row processed marks | **100,000 UPDATE queries** | **None (no-op)** |
| Per-row failed marks | K UPDATE queries | None (no-op) |
| Total DB queries | ~100,200+ | 0 |

The file driver's main advantage is eliminating the per-row `UPDATE` queries in the consumer phase. With 1000 rows per chunk, that is 1000 fewer database round-trips per chunk job.

### Choosing a driver

| Scenario | Recommended driver |
|---|---|
| Single server, speed is priority | `file` |
| Multi-server with no shared filesystem | `database` |
| Need per-row error auditing | `database` |
| Serverless / ephemeral workers | `database` |
| Large files, idempotent import logic | `file` |

---

## Configuration reference

All staging options live under the `staging` key in `config/sheet-stream.php`:

```php
'staging' => [
    'driver'            => env('SHEET_STREAM_STAGING_DRIVER', 'database'),
    'table'             => 'sheet_stream_staging',
    'path'              => null,
    'insert_batch_size' => 500,
],
```

| Option | Default | Description |
|---|---|---|
| `driver` | `'database'` | `'database'` or `'file'` |
| `table` | `'sheet_stream_staging'` | Table name (database driver only) |
| `path` | `null` | Base directory for chunk files (file driver only). `null` defaults to `{temp_path}/sheet_stream_staging` |
| `insert_batch_size` | `500` | Rows per batch write in the producer. Applies to both drivers. |

The global `chunk_size` option (default: `1000`) controls how many rows each consumer job processes.

---

## Compatible concerns

The staging pipeline supports all standard import concerns:

| Concern | Supported | Notes |
|---|---|---|
| `ToModel` | Yes | Models are buffered and flushed per chunk |
| `ToArray` | Yes | `array()` is called once **per chunk**, not once for the entire sheet |
| `ToCollection` | Yes | `collection()` is called once **per chunk** |
| `WithHeadingRow` | Yes | Headings extracted by the producer; rows arrive with keys applied |
| `WithBatchInserts` | Yes | Controls model flush size within each chunk |
| `SkipsEmptyRows` | Yes | Empty rows filtered by the producer before staging |
| `WithValidation` | Yes | Validation runs in the consumer per row |
| `SkipsOnFailure` | Yes | Failures collected per chunk, delivered via `onFailure()` |
| `WithMultipleSheets` | Yes | Each sheet staged independently, tagged by sheet index |
| `WithReaderOptions` | Yes | Passed through to the engine in the producer |

### Important: ToArray / ToCollection behavior

In the staging pipeline, `array()` and `collection()` are called **once per chunk**, not once for the entire sheet. Design your handler to work incrementally:

```php
class MyImport implements ToArray, ShouldQueue, UsesStagingTable, WithHeadingRow
{
    public function array(array $rows): void
    {
        // This is called once per chunk (e.g. 1000 rows at a time).
        // Use upserts or append to a results table — don't assume
        // you're seeing the complete dataset.
        DB::table('results')->insert(
            array_map(fn ($row) => [...$row, 'created_at' => now()], $rows)
        );
    }
}
```

---

## Queue configuration

Control queue and retry behavior via public properties on your import class:

```php
class LargeImport implements ToModel, WithHeadingRow, ShouldQueue, UsesStagingTable
{
    public ?string $queue = 'imports';
    public ?string $connection = 'redis';
    public ?int $tries = 3;
    public ?int $timeout = 600; // seconds

    public function model(array $row): ?Model { /* ... */ }
}
```

These properties are forwarded to both the producer and consumer jobs. For multi-sheet imports, sub-import properties take precedence over the parent for consumer jobs.

---

## Multiple sheets

The staging pipeline fully supports `WithMultipleSheets`. Each sheet is staged independently with its own sheet index, and consumer jobs are dispatched per-sheet-per-chunk:

```php
class WorkbookImport implements WithMultipleSheets, ShouldQueue, UsesStagingTable
{
    public function sheets(): array
    {
        return [
            0 => new UsersSheetImport,
            1 => new OrdersSheetImport,
        ];
    }
}
```

Sheets not listed in the `sheets()` array are skipped entirely (no rows staged, no consumer jobs dispatched).

---

## Architecture

### Key classes

| Class | Role |
|---|---|
| `UsesStagingTable` | Marker interface — opt your import into the staging pipeline |
| `StagingStore` | Interface abstracting the storage mechanism |
| `DatabaseStagingStore` | Implements `StagingStore` using a database table |
| `FileStagingStore` | Implements `StagingStore` using per-chunk NDJSON files |
| `StagingProducerJob` | Phase 1 — reads file, writes to staging, dispatches consumers |
| `StagingChunkProcessorJob` | Phase 2 — reads chunk from staging, runs import logic |
| `StagedRow` | Eloquent model for the staging table (database driver) |
| `SheetStreamManager` | Detects `UsesStagingTable` and routes to `StagingProducerJob` |

### File locations

```
src/
├── Concerns/
│   └── UsesStagingTable.php          # Marker interface
├── Jobs/
│   ├── StagingProducerJob.php        # Phase 1: read + stage
│   └── StagingChunkProcessorJob.php  # Phase 2: process + persist
├── Models/
│   └── StagedRow.php                 # Eloquent model (database driver)
├── Staging/
│   ├── StagingStore.php              # Driver interface
│   ├── DatabaseStagingStore.php      # Database implementation
│   └── FileStagingStore.php          # File implementation
└── SheetStreamManager.php            # Routes to staging pipeline

config/sheet-stream.php               # staging.driver, staging.table, staging.path
database/migrations/                  # Staging table migration
```

### Extending with a custom driver

Implement the `StagingStore` interface and bind it in a service provider:

```php
use MrDellimore\SheetStream\Staging\StagingStore;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StagingStore::class, fn () => new RedisStagingStore(
            config('sheet-stream.staging.redis_connection', 'default'),
        ));
    }
}
```

The `StagingStore` interface has five methods:

| Method | Purpose |
|---|---|
| `insertBatch(array $records)` | Write a batch of rows (called by producer) |
| `readChunk(importId, sheetIndex, chunkNumber)` | Read all rows for a chunk (called by consumer) |
| `markProcessed(rowId)` | Mark a row as successfully processed |
| `markFailed(rowId, errorJson)` | Mark a row as failed with error details |
| `cleanupChunk(importId, sheetIndex, chunkNumber)` | Clean up after a chunk is fully processed |

Records passed to `insertBatch` contain: `import_id`, `sheet_index`, `sheet_name`, `chunk_number`, `row_number`, `row_data` (raw PHP array), and `created_at`. The store is responsible for serialisation (JSON, msgpack, etc.).

Objects returned by `readChunk` must have `id`, `row_number`, and `row_data` (PHP array) properties.

---

## Running benchmarks

A benchmark test is included to measure pipeline performance against a real file:

```bash
# Place a large .xlsx at the repo root, or specify the path:
BENCH_FILE="path/to/large-file.xlsx" ./vendor/bin/pest --group=benchmark
```

The benchmark measures:
- **Producer time** — file read + staging writes (single process)
- **Chunk time** — all chunk jobs run sequentially (single process)
- **Parallel estimates** — chunk_time / N for 1, 5, 10, 20 workers

To compare drivers, run the benchmark twice — once with each driver configured:

```bash
# Database driver (default)
BENCH_FILE="file.xlsx" ./vendor/bin/pest --group=benchmark

# File driver
SHEET_STREAM_STAGING_DRIVER=file BENCH_FILE="file.xlsx" ./vendor/bin/pest --group=benchmark
```
