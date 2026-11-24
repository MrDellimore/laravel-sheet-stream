# Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=sheet-stream-config
```

This creates `config/sheet-stream.php`:

```php
return [
    'default_reader' => 'openspout',
    'default_writer' => 'openspout',
    'batch_size'     => 1000,
    'chunk_size'     => 1000,
    'temp_path'      => null,
    'dates' => [
        'import_as'       => 'serial',
        'timezone'        => null,
        'format'          => 'yyyy-mm-dd',
        'datetime_format' => 'yyyy-mm-dd hh:mm:ss',
    ],
    'staging' => [
        'driver'            => env('SHEET_STREAM_STAGING_DRIVER', 'file'),
        'table'             => 'sheet_stream_staging',
        'path'              => null,
        'insert_batch_size' => 500,
    ],
];
```

## Options

### `default_reader`

**Default:** `'openspout'`

The engine driver used for reading spreadsheets.

| Driver | Install | Streaming | `.xls` | Formulas | Memory |
|---|---|---|---|---|---|
| `'openspout'` | Included | Yes | No | Cached values only | Flat |
| `'phpspreadsheet'` | `composer require phpoffice/phpspreadsheet` | No | Yes | Yes | Grows with file size |
| `'rust'` | `pie install "mrdellimore/rusty-sheet:^0.1"` ([details](installation.md#optional-rust-driver)) | Yes | No | Cached values only | Flat (plus the shared-string table) |

**Use `'openspout'`** (default) for most imports — it streams rows one at a time with flat memory.

**Use `'rust'`** for large imports. It reads `.xlsx`, `.csv` and `.tsv` through the `rusty_sheet` extension, a streaming reader written in Rust. On a real 86 MB, 396k-row workbook it took 3.4 s, against about 25 minutes for the OpenSpout driver; CSV is about 10× faster. Rows are the same as the OpenSpout driver's, with four fixes:

- shared (filled-down) formulas return their real formula instead of `"="`;
- an unparseable number cell yields `null` instead of `0.0`;
- with `WithCalculatedFormulas`, text starting with `=` (such as `=== Totals ===`) is kept, where the OpenSpout driver returns `null`;
- with `dates.import_as = 'serial'` (and no timezone), dates before 1900-03-01 yield their true serial. This includes every time-only value: 18:00 is `0.75`, where the OpenSpout driver gives `-0.25`.

`WithFormatData` and CSV `WithReaderOptions` (delimiter, enclosure, escape) are supported. Anything the extension doesn't handle yet is read by OpenSpout automatically, per file: `.ods` files, CSV in an encoding other than UTF-8, and `WithReaderOptions` that set `SHOULD_USE_1904_DATES`. If the extension isn't loaded, the `rust` driver throws instead of silently falling back, so a misconfigured server is noticed. With this driver, `WithCsvPreConversion` is no longer needed: reading the XLSX directly is faster than converting it.

**Use `'phpspreadsheet'`** when you need to read `.xls` (legacy binary) files or require formula evaluation. Note: PhpSpreadsheet loads the entire workbook into memory.

You can mix drivers — use `phpspreadsheet` for reading and `openspout` for writing:

```php
'default_reader' => 'phpspreadsheet',  // supports .xls
'default_writer' => 'openspout',       // streams exports
```

### `default_writer`

**Default:** `'openspout'`

The engine driver used for writing spreadsheets. The same two drivers are available.

**Use `'openspout'`** (default) for most exports — it streams rows directly to disk.

**Use `'phpspreadsheet'`** when you need to write `.xls` format or need PhpSpreadsheet-specific features (cell-level styling, formula writing, etc.).

### `batch_size`

**Default:** `1000`

The number of Eloquent models buffered before flushing to the database during `ToModel` imports. This is the default value — individual imports can override it by implementing `WithBatchInserts`:

```php
use MrDellimore\SheetStream\Concerns\WithBatchInserts;

class MyImport implements ToModel, WithBatchInserts
{
    public function batchSize(): int
    {
        return 500; // Override the config default
    }
}
```

**Tuning:** Larger batch sizes mean fewer database round-trips but more memory. For most workloads, 500 to 2000 is a good range.

### `chunk_size`

**Default:** `1000`

The number of rows per chunk when using `FromQuery` exports. The query is executed via Eloquent's `lazy()` method with this chunk size, keeping memory flat for large result sets.

**Tuning:** Larger chunks reduce the number of database queries but use more memory per chunk. The default of 1000 works well for most cases.

### `temp_path`

**Default:** `null` (uses `sys_get_temp_dir()`)

Directory for temporary files created during exports. Set this when the default system temp directory is unsuitable (e.g., serverless environments with read-only filesystem where only a specific path is writable).

```php
'temp_path' => storage_path('app/temp'),
```

### `dates.import_as`

**Default:** `'serial'`

How a date-styled cell reaches your import class.

| Value | Cell `2026-09-16` arrives as | Notes |
|---|---|---|
| `'serial'` | `46281` (int, or float when the cell has a time part) | Laravel Excel compatible. Convert with `PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject()` or your own helper. |
| `'datetime'` | `DateTimeImmutable` | Converted to `dates.timezone` when set. |

Serials are always emitted in the 1900 date system, so `excelToDateTimeObject()` needs no calendar switch even for workbooks saved with the 1904 system.

To receive the string a spreadsheet application would display instead (e.g. `"09/16/2026"`), implement the [`WithFormatData`](imports.md#withformatdata) concern on the import; it overrides this setting for that import.

### `dates.timezone`

**Default:** `null`

When set to a timezone string (e.g. `'America/New_York'`), date values read from spreadsheets are converted to this timezone before being returned (in `datetime` mode) or converted to a serial (in `serial` mode, the serial reflects the local wall-clock time). When `null`, no timezone conversion is applied.

---

## Staging pipeline options

These options control the [staging pipeline](staging-pipeline.md) used when an import implements `UsesStagingTable`. All options live under the `staging` key.

### `staging.driver`

**Default:** `'file'`

The storage backend for staged rows. Set via config or the `SHEET_STREAM_STAGING_DRIVER` environment variable.

| Driver | Description | Requires |
|---|---|---|
| `'file'` | Rows stored as NDJSON files on the filesystem (fast, no migration needed) | Writable temp directory; shared filesystem for multi-server |
| `'database'` | Rows stored in a database table with per-row audit trail and retry safety | Migration published and run |

### `staging.table`

**Default:** `'sheet_stream_staging'`

The database table name used by the `database` driver. Ignored when using the `file` driver.

### `staging.path`

**Default:** `null` (uses `{temp_path}/sheet_stream_staging`)

Base directory for chunk files when using the `file` driver. Set this to a shared mount when running workers across multiple servers. Ignored when using the `database` driver.

```php
'staging' => [
    'driver' => 'file',
    'path'   => '/mnt/shared/sheet_stream_staging',
],
```

### `staging.insert_batch_size`

**Default:** `500`

Number of rows per batch write in the producer job. Applies to both drivers. Larger batches mean fewer write operations but more memory per batch.

**Tuning:** 500 is a good default. For the database driver, this maps to the number of rows per `INSERT` statement. For the file driver, this is the number of NDJSON lines written per `file_put_contents` call.
