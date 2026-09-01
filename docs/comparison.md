# Comparison with Laravel Excel

Laravel Sheet Stream and [Laravel Excel](https://github.com/SpartnerNL/Laravel-Excel) solve the same problem — spreadsheet imports and exports in Laravel — but use different engines with different trade-offs.

## Feature matrix

| Capability | Sheet Stream (OpenSpout) | Sheet Stream (PhpSpreadsheet) | Laravel Excel |
|---|---|---|---|
| **Memory on large files** | Flat (streaming) | Grows with file size | Grows with file size |
| **Streaming exports** | Yes | No | Limited |
| **Collect all validation failures** | Yes (with row numbers) | Yes (with row numbers) | First failure only |
| **`.xlsx` support** | Yes | Yes | Yes |
| **`.csv` / `.tsv` support** | Yes | Yes | Yes |
| **`.ods` support** | Yes | Yes | Yes |
| **Legacy `.xls` (binary)** | No | Yes | Yes |
| **Formula recalculation** | Cached values only | Yes | Yes |
| **Charts / drawings / images** | No | Via PhpSpreadsheet | Yes |
| **`FromView` (Blade to xlsx)** | No | No | Yes |
| **Queued imports/exports** | Yes (`ShouldQueue`) | Yes (`ShouldQueue`) | Yes |
| **Row/column styling** | Yes (XLSX/ODS) | No | Yes |
| **CSV delimiter/encoding** | Yes (`WithReaderOptions` / `WithWriterOptions`) | No | Yes |
| **`WithChunkReading`** | Not needed (always streams) | Not needed | Yes |

Sheet Stream ships with **two engine drivers** that you switch via config:

- **`openspout`** (default) — streaming, flat memory, but no `.xls`/formula support
- **`phpspreadsheet`** — full-featured (`.xls`, formulas, charts), but loads the entire workbook into memory

You can mix drivers: use `phpspreadsheet` for reading `.xls` files and `openspout` for writing exports.

## When to use the OpenSpout driver (default)

- You process **large files** (10k+ rows) and need flat memory usage
- You run imports in **Horizon jobs** and hit memory limits
- You want **streamed exports** that don't write huge temp files
- Your files are `.xlsx`, `.csv`, or `.ods`

## When to use the PhpSpreadsheet driver

- You need **`.xls` (legacy binary)** format support
- You need **formula recalculation** (not just cached values)
- You're OK with the memory trade-off for a specific import

## When to keep Laravel Excel

- You use **`FromView`** to render Blade templates as Excel files
- You need **cell-range styling** (Sheet Stream supports row/column styles, not arbitrary cell ranges)
- You need features that Sheet Stream hasn't implemented yet

## Coexistence

Both packages can run side by side. They use different namespaces:

```php
// Laravel Excel
use Maatwebsite\Excel\Concerns\ToModel;

// Sheet Stream
use MrDellimore\SheetStream\Concerns\ToModel;
```

You can migrate imports incrementally — keep the old one on Laravel Excel while you build and test the replacement on Sheet Stream. See the [Migration Guide](migration-guide.md) for the step-by-step process.
