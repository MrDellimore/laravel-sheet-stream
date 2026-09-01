# Laravel Sheet Stream

Streaming Excel **imports & exports** for Laravel, powered by
[OpenSpout](https://github.com/openspout/openspout). A **drop-in, Laravel-Excel-style**
concern API — with a streaming engine that keeps memory flat on large files.

> **Note:** This is an independent package. It is **not** affiliated with, endorsed by, or the
> official Laravel Excel package (by Spartner).

[![Tests](https://github.com/MrDellimore/laravel-sheet-stream/actions/workflows/ci.yml/badge.svg)](https://github.com/MrDellimore/laravel-sheet-stream/actions/workflows/ci.yml)
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
| PHP | `^8.2` |
| Laravel | `^11.0 \| ^12.0 \| ^13.0` |
| OpenSpout | `^4.30` |

---

## Installation

```bash
composer require mrdellimore/laravel-sheet-stream
```

Laravel auto-discovers the service provider. To publish the config file:

```bash
php artisan vendor:publish --tag=sheet-stream-config
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

    // Called once with ALL failures after all rows are processed.
    public function onFailure(Collection $failures): void
    {
        // log, notify, etc.
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
| `SkipsOnFailure` | Skip invalid rows; receive **all** failures at once after processing |
| `SkipsEmptyRows` | Skip rows where every cell is null or empty string |
| `WithMultipleSheets` | Route each sheet to a different import object |

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

---

## How this differs from Laravel Excel

| Capability | This package (OpenSpout) | Laravel Excel (PhpSpreadsheet) |
|---|---|---|
| Memory on large files | Flat (streaming) | Grows with file size |
| Legacy `.xls` (binary) | Not supported | Supported |
| Formula recalculation | Cached values only | Supported |
| Charts / drawings / images | Not supported | Supported |
| `FromView` (Blade → xlsx) | Not supported | Supported |
| Streaming exports | Supported | Limited |
| Collect **all** validation failures | Supported | First failure only |

---

## Configuration

```php
// config/sheet-stream.php
return [
    'default_reader' => 'openspout',
    'default_writer' => 'openspout',
    'batch_size'     => 1000,   // rows per DB insert buffer (ToModel)
    'chunk_size'     => 1000,   // rows per lazy-load chunk (FromQuery)
    'dates' => [
        'coerce'   => true,
        'timezone' => null,
    ],
];
```

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
