# Exports

An export class defines where data comes from and how it is written to a spreadsheet. Like imports, exports use a concern-based API.

## Entry points

```php
use MrDellimore\SheetStream\Facades\SheetStream;

// Streamed HTTP download
return SheetStream::download(new YourExport, 'filename.xlsx');

// Store to a filesystem disk
SheetStream::store(new YourExport, 'exports/filename.xlsx', disk: 's3');

// Queue an export (if ShouldQueue is implemented, store() auto-queues)
SheetStream::queueExport(new YourExport, 'exports/filename.xlsx', disk: 's3');
```

All methods accept `.xlsx`, `.csv`, `.tsv`, or `.ods` filenames. The format is determined by the file extension.

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

## Styling concerns

Styling is supported for XLSX and ODS exports. Styles are silently ignored for CSV exports.

### WithHeadingStyle

Apply a style to the heading row.

```php
use MrDellimore\SheetStream\Concerns\WithHeadingStyle;
use OpenSpout\Common\Entity\Style\Style;

class UsersExport implements FromQuery, WithHeadings, WithHeadingStyle
{
    public function query(): Builder { /* ... */ }

    public function headings(): array
    {
        return ['Name', 'Email'];
    }

    public function headingStyle(): Style
    {
        $style = new Style;
        $style->setFontBold();
        $style->setFontSize(14);

        return $style;
    }
}
```

### WithDefaultRowStyle

Apply a style to every data row (not the heading row).

```php
use MrDellimore\SheetStream\Concerns\WithDefaultRowStyle;
use OpenSpout\Common\Entity\Style\Style;

class UsersExport implements FromQuery, WithHeadings, WithDefaultRowStyle
{
    public function query(): Builder { /* ... */ }
    public function headings(): array { /* ... */ }

    public function defaultRowStyle(): Style
    {
        $style = new Style;
        $style->setFontSize(11);
        $style->setFontName('Arial');

        return $style;
    }
}
```

### WithColumnStyles

Apply per-column styles, keyed by 0-based column index. Useful for number formats, date formats, or column-specific colors.

```php
use MrDellimore\SheetStream\Concerns\WithColumnStyles;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;

class InvoicesExport implements FromQuery, WithHeadings, WithColumnStyles
{
    public function query(): Builder { /* ... */ }

    public function headings(): array
    {
        return ['Description', 'Amount', 'Date'];
    }

    /** @return array<int, Style> */
    public function columnStyles(): array
    {
        $amountStyle = new Style;
        $amountStyle->setFormat('#,##0.00');
        $amountStyle->setFontColor(Color::DARK_BLUE);

        $dateStyle = new Style;
        $dateStyle->setFormat('yyyy-mm-dd');

        return [
            1 => $amountStyle,  // Column B (Amount)
            2 => $dateStyle,    // Column C (Date)
        ];
    }
}
```

You can combine all three styling concerns on the same export class.

> **Note:** OpenSpout styles use the `OpenSpout\Common\Entity\Style\Style` class. See the [OpenSpout documentation](https://github.com/openspout/openspout) for the full list of style properties (borders, alignment, background color, text rotation, etc.).

---

## Writer options

### WithWriterOptions

Pass native OpenSpout writer options to customize format-specific behavior like CSV delimiters, BOM, or XLSX inline strings.

```php
use MrDellimore\SheetStream\Concerns\WithWriterOptions;
use OpenSpout\Writer\CSV\Options;

class UsersExport implements FromQuery, WithHeadings, WithWriterOptions
{
    public function query(): Builder { /* ... */ }
    public function headings(): array { /* ... */ }

    public function writerOptions(): object
    {
        $options = new Options;
        $options->FIELD_DELIMITER = ';';
        $options->FIELD_ENCLOSURE = "'";
        $options->SHOULD_ADD_BOM = false;

        return $options;
    }
}
```

Available option classes by format:

| Format | Options class | Key properties |
|---|---|---|
| CSV | `OpenSpout\Writer\CSV\Options` | `FIELD_DELIMITER`, `FIELD_ENCLOSURE`, `SHOULD_ADD_BOM`, `FLUSH_THRESHOLD` |
| XLSX | `OpenSpout\Writer\XLSX\Options` | `SHOULD_USE_INLINE_STRINGS`, column widths, page setup, merge cells |
| ODS | `OpenSpout\Writer\ODS\Options` | Column widths, default row/column sizes |

---

## Queued exports

### ShouldQueue

Dispatch an export to run in a queue worker instead of synchronously. Add the `ShouldQueue` marker interface to your export class:

```php
use MrDellimore\SheetStream\Concerns\FromQuery;
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\WithHeadings;

class UsersExport implements FromQuery, WithHeadings, ShouldQueue
{
    public function query(): Builder { /* ... */ }
    public function headings(): array { /* ... */ }
}
```

When `ShouldQueue` is implemented, `store()` automatically dispatches to the queue:

```php
// Dispatches a QueuedExportJob — returns PendingDispatch
SheetStream::store(new UsersExport, 'exports/users.xlsx', disk: 's3');
```

You can also call `queueExport()` explicitly:

```php
SheetStream::queueExport(new UsersExport, 'exports/users.xlsx', disk: 's3');
```

### Queue configuration

Control queue behavior by adding public properties to your export class:

```php
class UsersExport implements FromQuery, WithHeadings, ShouldQueue
{
    public ?string $queue = 'exports';
    public ?string $connection = 'redis';
    public ?int $tries = 3;
    public ?int $timeout = 300;

    // ...
}
```

> **Note:** `download()` always runs synchronously — you cannot queue an HTTP download. Use `store()` or `queueExport()` for background exports, then serve the stored file.

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
   - If `WithHeadings`: write the heading row first (with `WithHeadingStyle` if implemented).
   - Iterate rows from the data source (`FromCollection`, `FromQuery`, or `FromGenerator`).
   - For each row: if `WithMapping`, call `map($row)`. Otherwise, cast to `(array)`.
   - **Write** the row directly to disk via the engine, applying `WithDefaultRowStyle` and `WithColumnStyles` if implemented.
3. Close the writer.

For `download()`, the file is written to a temporary path, then streamed to the HTTP response with the correct MIME type. The temp file is cleaned up automatically.

For `store()`, the file is written to a temp path and then streamed onto the specified filesystem disk via Laravel's `Storage::writeStream()`. The file contents are never loaded into PHP memory.

For queued exports (`ShouldQueue`), `store()` dispatches a `QueuedExportJob` that performs the same write-then-upload flow in a queue worker.
