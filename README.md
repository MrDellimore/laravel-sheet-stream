# Laravel Sheet Stream

Streaming Excel **imports & exports** for Laravel, powered by
[OpenSpout](https://github.com/openspout/openspout). A **drop-in, Laravel-Excel-style**
concern API — with a streaming engine that keeps memory flat on large files.

> **Note:** This is an independent package. It is **not** affiliated with, endorsed by, or the
> official Laravel Excel package (by Spartner).

[![CI](https://github.com/MrDellimore/laravel-sheet-stream/actions/workflows/ci.yml/badge.svg)](https://github.com/MrDellimore/laravel-sheet-stream/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrdellimore/laravel-sheet-stream.svg)](https://packagist.org/packages/mrdellimore/laravel-sheet-stream)
[![Total Downloads](https://img.shields.io/packagist/dt/mrdellimore/laravel-sheet-stream.svg)](https://packagist.org/packages/mrdellimore/laravel-sheet-stream)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

---

## Why this exists

Laravel Excel is excellent, but its PhpSpreadsheet engine materialises the entire workbook
in memory. On large imports this means `Allowed memory size exhausted` errors in Horizon
jobs and on import queues. This package keeps the ergonomics you already know while
streaming rows one at a time — memory stays flat regardless of file size.

---

## Requirements

| | |
|---|---|
| PHP | `^8.3` |
| Laravel | `^11.0 \| ^12.0 \| ^13.0` |
| OpenSpout | `^4.30` |
| Extensions | `ext-zip`, `ext-xmlreader` |

---

## Installation

```bash
composer require mrdellimore/laravel-sheet-stream
```

Laravel auto-discovers the service provider. To publish the config file:

```bash
php artisan vendor:publish --tag=sheet-stream-config
```

If you plan to use the [staging pipeline](#staging-pipeline) for large queued imports, also publish and run the migrations:

```bash
php artisan vendor:publish --tag=sheet-stream-migrations
php artisan migrate
```

---

## Quick start

### Import

```php
use MrDellimore\SheetStream\Facades\SheetStream;

SheetStream::import(new ClaimantsImport, storage_path('imports/claimants.xlsx'));
```

### Export (streamed download)

```php
return SheetStream::download(new ClaimantsExport, 'claimants.xlsx');
```

### Store to disk

```php
SheetStream::store(new ClaimantsExport, 'exports/claimants.xlsx', disk: 's3');
```

### Queued import/export

```php
use MrDellimore\SheetStream\Concerns\ShouldQueue;

class ClaimantsImport implements ToModel, WithHeadingRow, ShouldQueue { /* ... */ }

// Auto-detects ShouldQueue and dispatches to your queue:
SheetStream::import(new ClaimantsImport, 'imports/claimants.xlsx');

// Or call explicitly:
SheetStream::queueImport(new ClaimantsImport, 'imports/claimants.xlsx', disk: 's3');
SheetStream::queueExport(new ClaimantsExport, 'exports/claimants.xlsx', disk: 's3');
```

---

## Writing an import class

```php
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;

class ClaimantsImport implements ToModel, WithHeadingRow, WithBatchInserts
{
    public function model(array $row): Claimant
    {
        return new Claimant([
            'name'  => $row['name'],
            'email' => $row['email'],
        ]);
    }

    public function batchSize(): int
    {
        return 500;
    }
}
```

### With validation

```php
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Imports\Failure;
use Illuminate\Support\Collection;

class ClaimantsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure
{
    public function model(array $row): Claimant { /* ... */ }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string'],
            'email' => ['required', 'email'],
        ];
    }

    // Called once with ALL failures (with row numbers) after all rows are processed.
    public function onFailure(Collection $failures): void
    {
        foreach ($failures as $failure) {
            // $failure->row()    — 1-based spreadsheet row number
            // $failure->errors() — ['attr' => ['messages...']]
            // $failure->values() — original row data
        }
    }
}
```

---

## Writing an export class

```php
use MrDellimore\SheetStream\Concerns\FromQuery;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithMapping;
use Illuminate\Database\Eloquent\Builder;

class ClaimantsExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder
    {
        return Claimant::query()->orderBy('name');
    }

    public function headings(): array
    {
        return ['Name', 'Email', 'Created'];
    }

    public function map(mixed $row): array
    {
        return [
            $row->name,
            $row->email,
            $row->created_at->toDateString(),
        ];
    }
}
```

---

## Supported concerns

### Import concerns

| Concern | Description |
|---|---|
| `ToModel` | Map each row to an Eloquent model; saves row-by-row (streaming) |
| `ToCollection` | Receive all rows as a `Collection` (buffered) |
| `ToArray` | Receive all rows as a plain `array` (buffered) |
| `WithHeadingRow` | Use the first row as associative keys (lowercased) |
| `WithBatchInserts` | Control the flush buffer size for `ToModel` |
| `WithValidation` | Validate each row with Laravel validation rules |
| `SkipsOnFailure` | Skip invalid rows; receive **all** failures (with row numbers) at once after processing |
| `SkipsEmptyRows` | Skip rows where every cell is null or empty string |
| `WithMultipleSheets` | Route each sheet to a different import object |
| `WithReaderOptions` | Pass native OpenSpout reader options (CSV delimiter, encoding, etc.) |
| `ShouldQueue` | Dispatch the import to a queue worker |
| `UsesStagingTable` | Two-phase staging pipeline for very large queued imports ([details](#staging-pipeline)) |
| `WithParallelSheets` | Dispatch one job per sheet for queued multi-sheet imports (combine with `WithMultipleSheets` + `ShouldQueue`) |

### Export concerns

| Concern | Description |
|---|---|
| `FromCollection` | Source rows from an `Illuminate\Support\Collection` |
| `FromQuery` | Source rows from an Eloquent query; chunked via `lazy()` |
| `FromGenerator` | Source rows from a PHP `Generator` |
| `WithHeadings` | Write a heading row before data |
| `WithMapping` | Transform each row/model before writing cells |
| `WithTitle` | Set the sheet name |
| `WithMultipleSheets` | Write multiple sheets in a single file |
| `WithHeadingStyle` | Apply a style to the heading row (bold, font size, etc.) |
| `WithDefaultRowStyle` | Apply a default style to every data row |
| `WithColumnStyles` | Apply per-column styles (number formats, colors, etc.) |
| `WithWriterOptions` | Pass native OpenSpout writer options (CSV delimiter, BOM, etc.) |
| `ShouldQueue` | Dispatch the export to a queue worker |

---

## How this differs from Laravel Excel

| Capability | OpenSpout driver (default) | PhpSpreadsheet driver | Laravel Excel |
|---|---|---|---|
| Memory on large files | Flat (streaming) | Grows with file size | Grows with file size |
| Legacy `.xls` (binary) | Not supported | Supported | Supported |
| Formula recalculation | Cached values only | Supported | Supported |
| Charts / drawings / images | Not supported | Via PhpSpreadsheet | Supported |
| `FromView` (Blade → xlsx) | Not supported | Not supported | Supported |
| Streaming exports | Supported | No | Limited |
| Collect **all** validation failures | Yes (with row numbers) | Yes (with row numbers) | First failure only |
| Queued imports/exports | Yes (`ShouldQueue`) | Yes (`ShouldQueue`) | Yes |
| Row/column styling | Yes (XLSX/ODS) | No | Yes |
| CSV delimiter/encoding options | Yes (`WithReaderOptions` / `WithWriterOptions`) | No | Yes |

> **Two engines, one API.** Switch between `openspout` (streaming) and `phpspreadsheet` (full-featured) via config — your import/export classes stay the same. Install the PhpSpreadsheet driver with: `composer require phpoffice/phpspreadsheet`

---

## Configuration

```php
// config/sheet-stream.php
return [
    'default_reader' => 'openspout',
    'default_writer' => 'openspout',
    'batch_size'     => 1000,   // rows per DB insert batch (ToModel)
    'chunk_size'     => 1000,   // rows per queued chunk job
    'temp_path'      => null,   // null = sys_get_temp_dir()
    'dates' => [
        'coerce'          => true,
        'timezone'        => null,
        'format'          => 'yyyy-mm-dd',             // Excel number format for date-only exports
        'datetime_format' => 'yyyy-mm-dd hh:mm:ss',   // Excel number format for date+time exports
    ],
    'staging' => [
        'driver'           => env('SHEET_STREAM_STAGING_DRIVER', 'file'), // 'file' (fast, no migration) or 'database' (audit trail, retry safety)
        'table'            => 'sheet_stream_staging',   // table name (database driver)
        'path'             => null,                     // base path (file driver); null = temp_path
        'insert_batch_size' => 500,                     // rows per bulk INSERT in the producer job
    ],
];
```

---

## Staging pipeline

For very large queued imports (millions of rows), the `UsesStagingTable` concern switches to a two-phase producer-consumer pattern:

1. **Producer job** — streams the file and bulk-inserts all rows into a staging table.
2. **Chunk-processor jobs** — dispatched in parallel, each processing a pre-assigned chunk.

```php
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\UsesStagingTable;

class MassiveImport implements ToModel, ShouldQueue, UsesStagingTable
{
    public function model(array $row): Claimant
    {
        return new Claimant($row);
    }
}
```

This requires the staging migrations to be published and run (see [Installation](#installation)). Choose between `database` and `file` staging drivers via the `staging.driver` config key. For full details, see [docs/staging-pipeline.md](docs/staging-pipeline.md).

---

## Credits & attribution

This package's **API is designed to be drop-in compatible with**
[Laravel Excel](https://github.com/SpartnerNL/Laravel-Excel) by **Spartner** (MIT). Huge
thanks to their maintainers — this project stands on their concern-based design. The
reading/writing engine is [OpenSpout](https://github.com/openspout/openspout) (MIT). This
is a clean-room re-implementation of the concern contracts on a different engine; it does
not vendor Laravel Excel's source.

---

## License

MIT © 2026 Andrew M. Dellimore. See [LICENSE](LICENSE) (includes Spartner attribution).
