# Imports

An import class is a plain PHP class that implements one or more concern interfaces. The concerns you implement determine how the spreadsheet data is read, validated, and persisted.

## Entry point

```php
use MrDellimore\SheetStream\Facades\SheetStream;

// Synchronous import from a local file path
SheetStream::import(new YourImport, $filePath);

// Queue an import (if ShouldQueue is implemented, import() auto-queues)
SheetStream::queueImport(new YourImport, 'imports/file.xlsx', disk: 's3');
```

For synchronous imports, `$filePath` should be an absolute path to an `.xlsx`, `.csv`, `.tsv`, or `.ods` file. For queued imports with a `disk` parameter, the path is relative to the storage disk.

---

## Import concerns

### ToModel

Map each row to an Eloquent model. Models are saved row-by-row in a streaming fashion — this is the most memory-efficient import strategy.

```php
use App\Models\Claimant;
use Illuminate\Database\Eloquent\Model;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class ClaimantsImport implements ToModel, WithHeadingRow
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

Return `null` from `model()` to skip a row without persisting it.

### ToCollection

Receive all rows as an `Illuminate\Support\Collection` after the entire sheet has been read. Useful when you need to process rows as a batch (e.g. bulk comparisons, deduplication).

```php
use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class ClaimantsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            // $row is an associative array: ['name' => '...', 'email' => '...']
        }
    }
}
```

> **Note:** `ToCollection` buffers all rows in memory. For very large files, prefer `ToModel` which streams row-by-row.

### ToArray

Same as `ToCollection` but receives a plain PHP array.

```php
use MrDellimore\SheetStream\Concerns\ToArray;

class ClaimantsImport implements ToArray
{
    public function array(array $rows): void
    {
        // $rows is an array of arrays
    }
}
```

---

## Configuration concerns

### WithHeadingRow

Treats the first row of the sheet as column headings. Subsequent rows are delivered as associative arrays with lowercased, trimmed heading keys.

```php
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class ClaimantsImport implements ToModel, WithHeadingRow
{
    public function model(array $row): ?Model
    {
        // $row keys come from the first row: ['name' => 'Alice', 'email' => 'alice@example.com']
        return new Claimant($row);
    }
}
```

Without `WithHeadingRow`, rows are delivered as numerically-indexed arrays.

**Heading normalization:** headings are lowercased and trimmed. `" First Name "` becomes `"first name"`.

### WithBatchInserts

Controls the buffer size for `ToModel` imports. Models are accumulated in a buffer and flushed (saved) when the buffer reaches the specified size.

```php
use MrDellimore\SheetStream\Concerns\WithBatchInserts;

class ClaimantsImport implements ToModel, WithHeadingRow, WithBatchInserts
{
    public function model(array $row): ?Model { /* ... */ }

    public function batchSize(): int
    {
        return 500; // Flush every 500 rows
    }
}
```

If you don't implement `WithBatchInserts`, the default batch size from `config('sheet-stream.batch_size')` (default: 1000) is used.

### SkipsEmptyRows

Skip rows where every cell is `null` or an empty string. This is a marker interface — just implement it, no methods required.

```php
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;

class ClaimantsImport implements ToArray, SkipsEmptyRows
{
    public function array(array $rows): void
    {
        // Empty rows have been filtered out
    }
}
```

---

## Validation

### WithValidation

Validate each row using standard Laravel validation rules. If a row fails validation, a `ValidationException` is thrown immediately (unless combined with `SkipsOnFailure`).

```php
use MrDellimore\SheetStream\Concerns\WithValidation;

class ClaimantsImport implements ToModel, WithHeadingRow, WithValidation
{
    public function model(array $row): ?Model { /* ... */ }

    public function rules(): array
    {
        return [
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
        ];
    }
}
```

The rule keys match the row keys — when using `WithHeadingRow`, these are the lowercased heading names. Without `WithHeadingRow`, use numeric indices (`'0'`, `'1'`, etc.).

### SkipsOnFailure

Instead of throwing on the first invalid row, continue processing and collect **all** validation failures. After the sheet is fully processed, the `onFailure()` method is called once with a collection of `Failure` objects — each carrying the **row number**, **errors**, and **original values**.

```php
use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Imports\Failure;

class ClaimantsImport implements ToModel, WithHeadingRow, WithValidation, SkipsOnFailure
{
    public function model(array $row): ?Model { /* ... */ }

    public function rules(): array
    {
        return [
            'name'  => ['required'],
            'email' => ['required', 'email'],
        ];
    }

    /** @param Collection<int, Failure> $failures */
    public function onFailure(Collection $failures): void
    {
        foreach ($failures as $failure) {
            logger()->warning('Import row failed', [
                'row'    => $failure->row(),     // 1-based spreadsheet row number
                'errors' => $failure->errors(),  // ['email' => ['The email field is required.']]
                'values' => $failure->values(),  // ['name' => 'Bob', 'email' => 'not-valid']
            ]);
        }
    }
}
```

#### The `Failure` object

| Method | Returns | Description |
|---|---|---|
| `row()` | `int` | 1-based row number in the spreadsheet (includes the heading row, so data starts at row 2) |
| `errors()` | `array<string, list<string>>` | Validation errors keyed by attribute name |
| `values()` | `array<string, mixed>` | The original row data that failed validation |

> **This is an improvement over Laravel Excel**, which only returns the first validation failure. Sheet Stream collects them all, with row numbers so you can tell the user exactly where to look.

---

## Reader options

### WithReaderOptions

Pass native OpenSpout reader options to customize format-specific behavior like CSV delimiters, encoding, or XLSX date formatting.

```php
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithReaderOptions;
use OpenSpout\Reader\CSV\Options;

class SemicolonImport implements ToArray, WithHeadingRow, WithReaderOptions
{
    public function array(array $rows): void { /* ... */ }

    public function readerOptions(): object
    {
        $options = new Options;
        $options->FIELD_DELIMITER = ';';
        $options->ENCODING = 'UTF-8';

        return $options;
    }
}
```

Available option classes by format:

| Format | Options class | Key properties |
|---|---|---|
| CSV | `OpenSpout\Reader\CSV\Options` | `FIELD_DELIMITER`, `FIELD_ENCLOSURE`, `ENCODING`, `SHOULD_PRESERVE_EMPTY_ROWS` |
| XLSX | `OpenSpout\Reader\XLSX\Options` | `SHOULD_FORMAT_DATES`, `SHOULD_PRESERVE_EMPTY_ROWS`, `SHOULD_USE_1904_DATES`, `SHOULD_LOAD_MERGE_CELLS`, temp folder |
| ODS | `OpenSpout\Reader\ODS\Options` | `SHOULD_FORMAT_DATES`, `SHOULD_PRESERVE_EMPTY_ROWS` |

---

## Queued imports

### ShouldQueue

Dispatch an import to run in a queue worker instead of synchronously. Add the `ShouldQueue` marker interface to your import class:

```php
use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;

class UsersImport implements ToModel, WithHeadingRow, ShouldQueue
{
    public function model(array $row): ?Model { /* ... */ }
}
```

When `ShouldQueue` is implemented, `import()` automatically dispatches to the queue:

```php
// Dispatches a QueuedImportJob — returns PendingDispatch
SheetStream::import(new UsersImport, storage_path('imports/users.xlsx'));
```

You can also call `queueImport()` explicitly, optionally specifying a storage disk:

```php
// Import a file stored on S3
SheetStream::queueImport(new UsersImport, 'imports/users.xlsx', disk: 's3');
```

When a `disk` is provided, the file is copied from the storage disk to a local temp file before processing (OpenSpout requires local file access). The temp file is cleaned up automatically.

### Queue configuration

Control queue behavior by adding public properties to your import class:

```php
class UsersImport implements ToModel, WithHeadingRow, ShouldQueue
{
    public ?string $queue = 'imports';
    public ?string $connection = 'redis';
    public ?int $tries = 3;
    public ?int $timeout = 600;

    public function model(array $row): ?Model { /* ... */ }
}
```

---

## Multiple sheets

### WithMultipleSheets

Route each sheet in a workbook to a different import class. The `sheets()` method returns an array mapping sheet indices (or names) to import objects.

```php
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;

class WorkbookImport implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            0 => new UsersSheetImport,      // First sheet
            1 => new OrdersSheetImport,     // Second sheet
            // 'Sheet Name' => new NamedSheetImport,  // By name also works
        ];
    }
}
```

Each sub-import is a normal import class implementing its own concerns (`ToModel`, `WithHeadingRow`, etc.). Sheets not listed in the array are skipped.

Without `WithMultipleSheets`, only the first sheet is processed.

---

## How the import pipeline works

The `ImportRunner` processes each sheet in a streaming loop:

1. **Open** the file via the engine (OpenSpout).
2. For each sheet, resolve the import object (or sub-import for multi-sheet).
3. If `WithHeadingRow`: capture the first row as column keys.
4. For each subsequent row:
   - If `SkipsEmptyRows` and the row is empty: skip.
   - If headings exist: combine headings + row values into an associative array.
   - If `WithValidation`: validate the row. On failure, either throw or collect (if `SkipsOnFailure`).
   - If `ToModel`: call `model()`, buffer the result, flush when batch size is reached.
   - If `ToArray`/`ToCollection`: accumulate the row.
5. Flush remaining buffered models.
6. Deliver collected failures to `onFailure()` (if `SkipsOnFailure`).
7. Deliver accumulated rows to `array()` or `collection()` (if `ToArray`/`ToCollection`).
8. **Close** the file.

Memory stays constant for `ToModel` imports because rows are never all held in memory at once.
