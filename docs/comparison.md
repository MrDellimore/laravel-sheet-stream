# Comparison with Laravel Excel

Laravel Sheet Stream and [Laravel Excel](https://github.com/SpartnerNL/Laravel-Excel) solve the same problem — spreadsheet imports and exports in Laravel — but use different engines with different trade-offs.

## Feature matrix

| Capability | Sheet Stream (OpenSpout) | Laravel Excel (PhpSpreadsheet) |
|---|---|---|
| **Memory on large files** | Flat (streaming) | Grows with file size |
| **Streaming exports** | Yes | Limited |
| **Collect all validation failures** | Yes | First failure only |
| **`.xlsx` support** | Yes | Yes |
| **`.csv` / `.tsv` support** | Yes | Yes |
| **`.ods` support** | Yes | Yes |
| **Legacy `.xls` (binary)** | No | Yes |
| **Formula recalculation** | Cached values only | Yes |
| **Charts / drawings / images** | No | Yes |
| **`FromView` (Blade to xlsx)** | No | Yes |
| **Cell styling** | Not yet (planned) | Yes |
| **Auto column sizing** | No | Yes |
| **Queued imports/exports** | Not yet (planned) | Yes |
| **`WithChunkReading`** | Not needed (always streams) | Yes |

## When to use Sheet Stream

- You process **large files** (10k+ rows) and need flat memory usage
- You run imports in **Horizon jobs** and hit memory limits
- You want **all** validation failures, not just the first one
- You want **streamed exports** that don't write huge temp files
- You don't need legacy `.xls`, formula evaluation, charts, or images

## When to keep Laravel Excel

- You need **`.xls` (binary)** format support
- You need **formula recalculation** (not just cached values)
- You need **charts, drawings, or images** in your spreadsheets
- You use **`FromView`** to render Blade templates as Excel files
- You need **cell-level styling** or **auto column sizing** today

## Coexistence

Both packages can run side by side. They use different namespaces:

```php
// Laravel Excel
use Maatwebsite\Excel\Concerns\ToModel;

// Sheet Stream
use MrDellimore\SheetStream\Concerns\ToModel;
```

You can migrate imports incrementally — keep the old one on Laravel Excel while you build and test the replacement on Sheet Stream. See the [Migration Guide](migration-guide.md) for the step-by-step process.
