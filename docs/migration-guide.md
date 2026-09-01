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

## What changes beyond the namespace

### SkipsOnFailure signature

**Laravel Excel** calls `onFailure()` with an array of `Maatwebsite\Excel\Validators\Failure` objects.

**Sheet Stream** calls `onFailure()` once after all rows are processed, with a `Collection<int, ValidationException>`:

```php
// Laravel Excel
public function onFailure(Failure ...$failures): void { }

// Sheet Stream
public function onFailure(Collection $failures): void
{
    // Each item is an Illuminate\Validation\ValidationException
    foreach ($failures as $failure) {
        $failure->errors(); // ['email' => ['The email field is required.']]
    }
}
```

Key difference: Sheet Stream collects **all** failures and delivers them in one call. Laravel Excel delivers them incrementally with a variadic signature.

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

### Facade / entry point

```diff
- use Maatwebsite\Excel\Facades\Excel;
+ use MrDellimore\SheetStream\Facades\SheetStream;

- Excel::import(new UsersImport, $path);
+ SheetStream::import(new UsersImport, $path);

- return Excel::download(new UsersExport, 'users.xlsx');
+ return SheetStream::download(new UsersExport, 'users.xlsx');
```

## Unsupported Laravel Excel features

These Laravel Excel features are **not available** in Sheet Stream. If your import/export uses them, it cannot be migrated yet:

| Feature | Status | Workaround |
|---|---|---|
| `.xls` (legacy binary) | Not supported (engine limit) | Convert to `.xlsx` before import |
| `FromView` | Not supported | Use `FromCollection` or `FromQuery` |
| `WithStyles` / `WithColumnFormatting` | Planned (v0.6) | — |
| `ShouldQueue` | Planned (v0.5) | Run in a job manually |
| `WithColumnWidths` / auto-sizing | Not supported | — |
| `WithDrawings` / `WithCharts` | Not supported (engine limit) | — |
| Formula recalculation | Cached values only | — |
| `RemembersRowNumber` | Not supported | — |
| `WithCustomCsvSettings` | Not supported | — |

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
