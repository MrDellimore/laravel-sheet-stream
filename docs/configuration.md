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
    'dates' => [
        'coerce'   => true,
        'timezone' => null,
    ],
];
```

## Options

### `default_reader`

**Default:** `'openspout'`

The engine driver used for reading spreadsheets. Currently only `'openspout'` is supported. A PhpSpreadsheet fallback driver is planned for a future release (to support `.xls`, formulas, and charts).

### `default_writer`

**Default:** `'openspout'`

The engine driver used for writing spreadsheets. Currently only `'openspout'` is supported.

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

### `dates.coerce`

**Default:** `true`

When enabled, the engine applies sane date coercion defaults. This addresses common issues where Excel stores dates as serial numbers or formatted strings.

### `dates.timezone`

**Default:** `null`

When set to a timezone string (e.g. `'America/New_York'`), date values read from spreadsheets are converted to this timezone. When `null`, no timezone conversion is applied.
