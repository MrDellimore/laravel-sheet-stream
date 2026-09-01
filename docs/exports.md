# Exports

An export class defines where data comes from and how it is written to a spreadsheet. Like imports, exports use a concern-based API.

## Entry points

```php
use MrDellimore\SheetStream\Facades\SheetStream;

// Streamed HTTP download
return SheetStream::download(new YourExport, 'filename.xlsx');

// Store to a filesystem disk
SheetStream::store(new YourExport, 'exports/filename.xlsx', disk: 's3');
```

Both methods accept `.xlsx`, `.csv`, `.tsv`, or `.ods` filenames. The format is determined by the file extension.

---

## Data source concerns

You must implement exactly one data source concern per export (or per sheet, in a multi-sheet export).

### FromCollection

Source rows from an `Illuminate\Support\Collection`.

```php
use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;

class UsersExport implements FromCollection
{
    public function collection(): Collection
    {
        return User::all();
    }
}
```

### FromQuery

Source rows from an Eloquent query builder. The query is executed using `lazy()`, which chunks internally and streams results — memory stays flat even for millions of rows.

```php
use Illuminate\Database\Eloquent\Builder;
use MrDellimore\SheetStream\Concerns\FromQuery;

class UsersExport implements FromQuery
{
    public function query(): Builder
    {
        return User::query()->where('active', true)->orderBy('name');
    }
}
```

The chunk size for `lazy()` is controlled by `config('sheet-stream.chunk_size')` (default: 1000).

> **`FromQuery` is the recommended data source for large exports.** It uses lazy collection chunking under the hood, so only one chunk of rows is in memory at a time.

### FromGenerator

Source rows from a PHP Generator. Ideal for computed data or data from non-Eloquent sources.

```php
use Generator;
use MrDellimore\SheetStream\Concerns\FromGenerator;

class SequenceExport implements FromGenerator
{
    public function generator(): Generator
    {
        for ($i = 1; $i <= 100000; $i++) {
            yield ['id' => $i, 'value' => "Row {$i}"];
        }
    }
}
```

---

## Formatting concerns

### WithHeadings

Write a header row before the data rows.

```php
use MrDellimore\SheetStream\Concerns\WithHeadings;

class UsersExport implements FromQuery, WithHeadings
{
    public function query(): Builder { /* ... */ }

    public function headings(): array
    {
        return ['Name', 'Email', 'Created At'];
    }
}
```

### WithMapping

Transform each row before it is written to the spreadsheet. Without `WithMapping`, each row is cast to an array using `(array) $row`.

```php
use MrDellimore\SheetStream\Concerns\WithMapping;

class UsersExport implements FromQuery, WithHeadings, WithMapping
{
    public function query(): Builder { /* ... */ }

    public function headings(): array
    {
        return ['Full Name', 'Email Address', 'Member Since'];
    }

    public function map(mixed $row): array
    {
        return [
            strtoupper($row->name),
            $row->email,
            $row->created_at->format('Y-m-d'),
        ];
    }
}
```

The `$row` parameter type depends on your data source — it's typically an Eloquent model (from `FromQuery`), an array element (from `FromCollection`), or whatever your generator yields.

### WithTitle

Set the worksheet name. Without this concern, the default sheet name from OpenSpout is used.

```php
use MrDellimore\SheetStream\Concerns\WithTitle;

class UsersExport implements FromCollection, WithTitle
{
    public function collection(): Collection { /* ... */ }

    public function title(): string
    {
        return 'Active Users';
    }
}
```

---

## Multiple sheets

### WithMultipleSheets

Write multiple worksheets to a single file. Each sheet is its own export object implementing its own data source and formatting concerns.

```php
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;

class WorkbookExport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new UsersSheet,
            new OrdersSheet,
        ];
    }
}
```

Each sheet class is a standalone export:

```php
use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\WithHeadings;
use MrDellimore\SheetStream\Concerns\WithTitle;

class UsersSheet implements FromCollection, WithHeadings, WithTitle
{
    public function collection(): Collection
    {
        return User::all();
    }

    public function headings(): array
    {
        return ['Name', 'Email'];
    }

    public function title(): string
    {
        return 'Users';
    }
}

class OrdersSheet implements FromCollection, WithHeadings, WithTitle
{
    public function collection(): Collection
    {
        return Order::all();
    }

    public function headings(): array
    {
        return ['Product', 'Quantity', 'Total'];
    }

    public function title(): string
    {
        return 'Orders';
    }
}
```

> **Note:** CSV files do not support multiple sheets. Attempting to write multiple sheets to a `.csv` file throws an `UnsupportedByEngine` exception.

---

## How the export pipeline works

The `ExportRunner` writes data in a streaming fashion:

1. If `WithMultipleSheets`: iterate each sheet object. Otherwise, treat the export as a single sheet.
2. For each sheet:
   - **Create** the worksheet (with name from `WithTitle` if implemented).
   - If `WithHeadings`: write the heading row first.
   - Iterate rows from the data source (`FromCollection`, `FromQuery`, or `FromGenerator`).
   - For each row: if `WithMapping`, call `map($row)`. Otherwise, cast to `(array)`.
   - **Write** the row directly to disk via the engine.
3. Close the writer.

For `download()`, the file is written to a temporary path, then streamed to the HTTP response with the correct MIME type. The temp file is cleaned up automatically.

For `store()`, the file is written to a temp path and then put onto the specified filesystem disk via Laravel's `Storage` facade.
