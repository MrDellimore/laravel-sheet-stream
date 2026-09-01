# Migration Guide: Coming from Laravel Excel

Sheet Stream uses the **same concern names** as Laravel Excel. Migrating an import or export is mostly a `use` statement swap. This guide walks through the process concern by concern.

## General strategy

1. **Don't do a big-bang migration.** Both packages coexist — different namespaces, no conflicts.
2. **Start with the worst offender** — the import that OOMs or uses the most memory.
3. **Build a parallel class** (e.g. `ClaimantsStreamImport`) using Sheet Stream concerns.
4. **Test equivalence** — same input file, same database result.
5. **Switch over** in your controller or job. Delete the old class once stable.
6. Repeat for the next import/export.

## Concern-by-concern mapping

### Import concerns

| Laravel Excel | Sheet Stream | Change required |
|---|---|---|
| `Maatwebsite\Excel\Concerns\ToModel` | `MrDellimore\SheetStream\Concerns\ToModel` | Namespace only |
| `Maatwebsite\Excel\Concerns\ToCollection` | `MrDellimore\SheetStream\Concerns\ToCollection` | Namespace only |
| `Maatwebsite\Excel\Concerns\ToArray` | `MrDellimore\SheetStream\Concerns\ToArray` | Namespace only |
| `Maatwebsite\Excel\Concerns\WithHeadingRow` | `MrDellimore\SheetStream\Concerns\WithHeadingRow` | Namespace only |
| `Maatwebsite\Excel\Concerns\WithBatchInserts` | `MrDellimore\SheetStream\Concerns\WithBatchInserts` | Namespace only |
| `Maatwebsite\Excel\Concerns\SkipsEmptyRows` | `MrDellimore\SheetStream\Concerns\SkipsEmptyRows` | Namespace only |
| `Maatwebsite\Excel\Concerns\WithValidation` | `MrDellimore\SheetStream\Concerns\WithValidation` | Namespace only |
| `Maatwebsite\Excel\Concerns\SkipsOnFailure` | `MrDellimore\SheetStream\Concerns\SkipsOnFailure` | Signature differs (see below) |
| `Maatwebsite\Excel\Concerns\WithMultipleSheets` | `MrDellimore\SheetStream\Concerns\WithMultipleSheets` | Namespace only |
| `Maatwebsite\Excel\Concerns\WithChunkReading` | *(not needed)* | Remove — streaming is built-in |
| `Maatwebsite\Excel\Concerns\WithCustomCsvSettings` | `MrDellimore\SheetStream\Concerns\WithReaderOptions` | Different API (see below) |
| `Illuminate\Contracts\Queue\ShouldQueue` | `MrDellimore\SheetStream\Concerns\ShouldQueue` | Namespace only |

### Export concerns

| Laravel Excel | Sheet Stream | Change required |
|---|---|---|
| `Maatwebsite\Excel\Concerns\FromCollection` | `MrDellimore\SheetStream\Concerns\FromCollection` | Namespace only |
| `Maatwebsite\Excel\Concerns\FromQuery` | `MrDellimore\SheetStream\Concerns\FromQuery` | Namespace only |
| `Maatwebsite\Excel\Concerns\FromGenerator` | `MrDellimore\SheetStream\Concerns\FromGenerator` | Namespace only |
| `Maatwebsite\Excel\Concerns\WithHeadings` | `MrDellimore\SheetStream\Concerns\WithHeadings` | Namespace only |
| `Maatwebsite\Excel\Concerns\WithMapping` | `MrDellimore\SheetStream\Concerns\WithMapping` | Namespace only |
| `Maatwebsite\Excel\Concerns\WithTitle` | `MrDellimore\SheetStream\Concerns\WithTitle` | Namespace only |
| `Maatwebsite\Excel\Concerns\WithMultipleSheets` | `MrDellimore\SheetStream\Concerns\WithMultipleSheets` | Namespace only |
| `Maatwebsite\Excel\Concerns\WithStyles` | `WithHeadingStyle` / `WithDefaultRowStyle` / `WithColumnStyles` | Different API (see below) |
| `Maatwebsite\Excel\Concerns\WithCustomCsvSettings` | `MrDellimore\SheetStream\Concerns\WithWriterOptions` | Different API (see below) |
| `Illuminate\Contracts\Queue\ShouldQueue` | `MrDellimore\SheetStream\Concerns\ShouldQueue` | Namespace only |

## What changes beyond the namespace

### SkipsOnFailure signature

**Laravel Excel** calls `onFailure()` with a variadic array of `Maatwebsite\Excel\Validators\Failure` objects.

**Sheet Stream** calls `onFailure()` once after all rows are processed, with a `Collection<int, Failure>` where each `Failure` carries the row number, errors, and original values:

```php
// Laravel Excel
use Maatwebsite\Excel\Validators\Failure;

public function onFailure(Failure ...$failures): void
{
    foreach ($failures as $failure) {
        $failure->row();       // row number
        $failure->attribute(); // single attribute name
        $failure->errors();    // error messages for that attribute
    }
}

// Sheet Stream
use MrDellimore\SheetStream\Imports\Failure;
use Illuminate\Support\Collection;

public function onFailure(Collection $failures): void
{
    foreach ($failures as $failure) {
        $failure->row();    // 1-based spreadsheet row number
        $failure->errors(); // ['attr' => ['msg', ...]] — all errors for the row
        $failure->values(); // the original row data
    }
}
```

Key differences:
- Sheet Stream collects **all** failures and delivers them in one call (not incrementally).
- Each `Failure` contains **all** validation errors for the row (not one attribute at a time).
- `values()` gives you the full row data that failed, making it easy to report back to the user.

### WithChunkReading — just remove it

Sheet Stream streams every import by default. There is no `WithChunkReading` concern because chunking is always on. Simply remove the interface and the `chunkSize()` method.

```diff
- use Maatwebsite\Excel\Concerns\WithChunkReading;
  use MrDellimore\SheetStream\Concerns\ToModel;
  use MrDellimore\SheetStream\Concerns\WithHeadingRow;

- class UsersImport implements ToModel, WithHeadingRow, WithChunkReading
+ class UsersImport implements ToModel, WithHeadingRow
  {
      public function model(array $row): ?Model { /* ... */ }
-
-     public function chunkSize(): int
-     {
-         return 1000;
-     }
  }
```

### WithStyles → WithHeadingStyle / WithDefaultRowStyle / WithColumnStyles

**Laravel Excel** lets you imperatively style arbitrary cell ranges via a `WithStyles` interface that receives a `Worksheet` object. This requires the entire spreadsheet to be in memory.

**Sheet Stream** uses declarative, row-oriented styling that works with streaming. Instead of one `WithStyles`, there are three focused concerns:

```php
// Laravel Excel
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UsersExport implements WithStyles
{
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],       // Heading row
            'B' => ['numberFormat' => ['formatCode' => '#,##0.00']],
        ];
    }
}

// Sheet Stream
use MrDellimore\SheetStream\Concerns\WithHeadingStyle;
use MrDellimore\SheetStream\Concerns\WithColumnStyles;
use OpenSpout\Common\Entity\Style\Style;

class UsersExport implements WithHeadingStyle, WithColumnStyles
{
    public function headingStyle(): Style
    {
        $style = new Style;
        $style->setFontBold();
        return $style;
    }

    public function columnStyles(): array
    {
        $amountStyle = new Style;
        $amountStyle->setFormat('#,##0.00');
        return [1 => $amountStyle]; // Column B (0-indexed)
    }
}
```

### WithCustomCsvSettings → WithReaderOptions / WithWriterOptions

**Laravel Excel** uses `WithCustomCsvSettings` to configure CSV delimiters and enclosure.

**Sheet Stream** passes native OpenSpout Options objects via `WithReaderOptions` (for imports) and `WithWriterOptions` (for exports):

```php
// Laravel Excel
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;

class CsvImport implements WithCustomCsvSettings
{
    public function getCsvSettings(): array
    {
        return ['delimiter' => ';', 'enclosure' => "'"];
    }
}

// Sheet Stream
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use OpenSpout\Reader\CSV\Options;

class CsvImport implements WithReaderOptions
{
    public function readerOptions(): object
    {
        $options = new Options;
        $options->FIELD_DELIMITER = ';';
        $options->FIELD_ENCLOSURE = "'";
        return $options;
    }
}
```

### ShouldQueue

**Laravel Excel** uses `Illuminate\Contracts\Queue\ShouldQueue` on the import/export class itself.

**Sheet Stream** uses its own `MrDellimore\SheetStream\Concerns\ShouldQueue` marker interface. When implemented, `import()` and `store()` automatically dispatch to the queue:

```diff
- use Illuminate\Contracts\Queue\ShouldQueue;
+ use MrDellimore\SheetStream\Concerns\ShouldQueue;

  class UsersImport implements ToModel, WithHeadingRow, ShouldQueue
  {
+     public ?string $queue = 'imports';   // optional
+     public ?int $timeout = 600;          // optional
      // ...
  }
```

Queue config properties (`$queue`, `$connection`, `$tries`, `$timeout`) are set as public properties on your class, rather than using Laravel Excel's `ShouldQueue` trait methods.

### Facade / entry point

```diff
- use Maatwebsite\Excel\Facades\Excel;
+ use MrDellimore\SheetStream\Facades\SheetStream;

- Excel::import(new UsersImport, $path);
+ SheetStream::import(new UsersImport, $path);

- return Excel::download(new UsersExport, 'users.xlsx');
+ return SheetStream::download(new UsersExport, 'users.xlsx');

- Excel::store(new UsersExport, 'exports/users.xlsx', 's3');
+ SheetStream::store(new UsersExport, 'exports/users.xlsx', disk: 's3');
```

Sheet Stream also adds two explicit queue methods not available in Laravel Excel:

```php
SheetStream::queueImport(new UsersImport, 'imports/users.xlsx', disk: 's3');
SheetStream::queueExport(new UsersExport, 'exports/users.xlsx', disk: 's3');
```

## Unsupported Laravel Excel features

These Laravel Excel features are **not available** in Sheet Stream. If your import/export uses them, it cannot be migrated yet:

| Feature | Status | Workaround |
|---|---|---|
| `.xls` (legacy binary) | Use `phpspreadsheet` driver | Set `default_reader` to `'phpspreadsheet'` |
| `FromView` | Not supported | Use `FromCollection` or `FromQuery` |
| `WithStyles` (cell-range) | Row/column styles only | Use `WithHeadingStyle`, `WithDefaultRowStyle`, `WithColumnStyles` |
| `WithColumnWidths` / auto-sizing | Not supported | Use `WithWriterOptions` with XLSX Options for column widths |
| `WithDrawings` / `WithCharts` | Not supported (engine limit) | — |
| Formula recalculation | Cached values only | — |
| `RemembersRowNumber` | Not supported | — |

## Step-by-step example

Migrating a Laravel Excel import class:

**Before (Laravel Excel):**

```php
<?php

namespace App\Imports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class UsersImport implements ToModel, WithHeadingRow, WithBatchInserts, WithChunkReading, WithValidation
{
    public function model(array $row): ?Model
    {
        return new User([
            'name'  => $row['name'],
            'email' => $row['email'],
        ]);
    }

    public function batchSize(): int
    {
        return 500;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string'],
            'email' => ['required', 'email'],
        ];
    }
}
```

**After (Sheet Stream):**

```php
<?php

namespace App\Imports;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithValidation;

class UsersImport implements ToModel, WithHeadingRow, WithBatchInserts, WithValidation
{
    public function model(array $row): ?Model
    {
        return new User([
            'name'  => $row['name'],
            'email' => $row['email'],
        ]);
    }

    public function batchSize(): int
    {
        return 500;
    }

    // chunkSize() removed — streaming is automatic

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string'],
            'email' => ['required', 'email'],
        ];
    }
}
```

Changes:
1. Swapped four `use` statements from `Maatwebsite\Excel\Concerns\*` to `MrDellimore\SheetStream\Concerns\*`
2. Removed `WithChunkReading` from the `implements` clause
3. Deleted `chunkSize()` method
4. Everything else is identical
