# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Upgrading to OpenSpout 5

Sheet Stream now requires **OpenSpout `^5.12`** and **PHP `^8.4`**. OpenSpout 4 is no longer supported: the two APIs can't be served from one code path, so there is no compatibility layer.

Your code is affected if it implements a concern that returns an OpenSpout object — `WithHeadingStyle`, `WithDefaultRowStyle`, `WithColumnStyles`, `WithReaderOptions` or `WithWriterOptions`. In OpenSpout 5, `Style` and every `Options` class are `readonly`: setters and property assignment are gone. Pass values as named constructor arguments instead, or derive a copy with the `with*()` methods.

```php
// Before (OpenSpout 4)
$style = new Style;
$style->setFontBold();
$style->setFormat('#,##0.00');

$options = new \OpenSpout\Reader\CSV\Options;
$options->FIELD_DELIMITER = ';';

// After (OpenSpout 5)
$style = new Style(fontBold: true, format: '#,##0.00');
// or: (new Style)->withFontBold(true)->withFormat('#,##0.00')

$options = new \OpenSpout\Reader\CSV\Options(FIELD_DELIMITER: ';');
```

Code that uses OpenSpout directly is also affected: for example, `Row::getCells()` is now the `$row->cells` property and `Style::getFormat()` is `$style->format`. See OpenSpout's [UPGRADE.md](https://github.com/openspout/openspout/blob/5.x/UPGRADE.md).

OpenSpout 5 also renames the writer option `DEFAULT_ROW_STYLE` to `FALLBACK_STYLE` and stops merging it into cells that have their own style: a cell now gets one style or the other. Sheet Stream already gave each cell exactly one style (its `WithColumnStyles` entry, otherwise `WithDefaultRowStyle`), so exports built through these concerns are unchanged. If you set `DEFAULT_ROW_STYLE` through `WithWriterOptions` and relied on it being merged into styled cells, switch to `FALLBACK_STYLE` and put the shared properties into each style.

### Changed
- **Breaking:** requires `openspout/openspout` `^5.12` (was `^4.30`) and PHP `^8.4` (was `^8.3`). See *Upgrading to OpenSpout 5* above.
- XLSX rows are read at their own width, as before. OpenSpout 5's reader pads every row out to the sheet's full width with empty strings. Sheet Stream strips that trailing padding, so short rows still get `null` for missing heading columns, and a heading row narrower than the data doesn't gain blank `''` headings.
- `WithFormatData` no longer modifies the `Options` instance returned by `WithReaderOptions`. It builds a copy with `SHOULD_FORMAT_DATES` enabled.
- **Breaking:** date-styled cells now reach imports as **Excel serial numbers** (int, or float with a time part) instead of `DateTimeImmutable`, matching Laravel Excel. Import helpers written against Laravel Excel (`Date::excelToDateTimeObject()`, `Carbon::createFromFormat()`, `trim()` on every cell…) work without modification. Set `dates.import_as` to `'datetime'` to keep the previous behaviour.
- **Breaking:** the unused `dates.coerce` config key is replaced by `dates.import_as` (`'serial'` | `'datetime'`).
- `dates.timezone` is applied before serial conversion, so the serial reflects the local wall-clock time.
- `PhpSpreadsheetSheetReader` now takes the reader options array (same shape as `OpenSpoutSheetReader`) instead of positional timezone / formula arguments.

### Added
- Export rows may contain OpenSpout `Cell` objects, written as they are with their own style (per-cell styling; text starting with `=` stays text). The phpspreadsheet driver writes the cell's plain value.
- Export rows may be OpenSpout `Row` objects yielded by a `FromGenerator` / `FromCollection` / `FromQuery` source, written as they are, row height included.
- `WithSheetSetup` concern: configure the engine's native sheet (frozen panes, gridlines, column widths) before any row is written. Backed by the new `Engine\Contracts\ExposesNativeSheet`, implemented by both writers.
- `AfterWriting` export event with the finished file's local path, fired after the writer closes and before the file is stored, downloaded or uploaded by `QueuedExportJob`; the temporary file is removed if a listener throws.
- `Engine\Contracts\WritesNativeRows`, implemented by the OpenSpout writer.
- `WithFormatData` concern — receive cells as display strings rendered through their number format (Laravel Excel parity). Enables `SHOULD_FORMAT_DATES` on the OpenSpout engine, layered onto any `WithReaderOptions`; uses `NumberFormat::toFormattedString()` on the PhpSpreadsheet engine.
- `Support\CellNormalizer` — converts `DateTimeInterface` / `DateInterval` cells to 1900-system Excel serials, including the 1900 leap-year quirk for dates before March 1900.
- `dates.import_as` is validated; an unknown value throws `InvalidArgumentException`.

---

## [0.1.0] — 2026-09-01

### Added

#### Core
- `SheetStreamServiceProvider` with auto-discovery and config publishing
- `SheetStream` facade
- `SheetStreamManager`: `import()`, `download()` (StreamedResponse), `store()` (Laravel filesystem)
- `config/sheet-stream.php` with `batch_size`, `chunk_size`, `dates`, `temp_path`, and `staging` settings

#### Import concerns
- `ToModel`, `ToCollection`, `ToArray` — three output modes
- `WithHeadingRow` — first row as lowercase-trimmed column keys
- `WithBatchInserts` — configurable batch size for `ToModel` persistence
- `WithValidation` + `SkipsOnFailure` — **all** failures collected (fixes Laravel Excel #1959)
- `SkipsEmptyRows` — skip null/blank rows
- `WithMultipleSheets` — route sheets by index or name
- `ImportRunner`: streaming orchestration loop — one row in memory at a time

#### Export concerns
- `FromCollection`, `FromQuery` (via `lazy()`), `FromGenerator` — three data sources
- `WithHeadings`, `WithMapping`, `WithTitle`, `WithMultipleSheets`
- `WithHeadingStyle`, `WithDefaultRowStyle`, `WithColumnStyles` — OpenSpout style support
- `ExportRunner`: streaming export orchestration

#### Engine layer
- Pluggable engine contracts: `Reader`, `SheetReader`, `Writer`
- `EngineFactory` for driver resolution
- **OpenSpout driver**: `OpenSpoutReader`, `OpenSpoutSheetReader`, `OpenSpoutWriter` (XLSX, CSV, ODS)
- **PhpSpreadsheet driver**: optional fallback for `.xls`, formulas, charts
- `WithReaderOptions` / `WithWriterOptions` — pass native engine options
- `UnsupportedByEngine` exception for `.xls` on the OpenSpout driver

#### Queued imports/exports
- `ShouldQueue` concern — auto-detected by `import()` and `store()`
- `QueuedImportJob`, `QueuedExportJob` — Horizon-friendly queued processing
- `UsesStagingTable` concern + `StagingProducerJob` / `StagingChunkProcessorJob` — bulk staging pipeline

#### Date/number coercion
- `OpenSpoutWriter` applies proper Excel date format styles to `DateTimeInterface` values
- Dates survive XLSX round-trip as `DateTimeImmutable` (not Excel serial numbers)
- Configurable date/datetime formats via `dates.format` and `dates.datetime_format`
- Timezone support via `dates.timezone` config
- Regular numbers are never accidentally coerced to dates

#### Quality & CI
- Pest 4 test suite (74 tests, 323 assertions)
- `pint.json` (Laravel preset)
- `phpstan.neon.dist` (Larastan, level 5)
- `.github/workflows/ci.yml` — PHP 8.2/8.3/8.4 × Laravel 11/12/13 matrix
- Memory benchmark harness (`benchmarks/`)

#### Documentation
- README with honest "differs from Laravel Excel" comparison table
- Credits & attribution section (Spartner / OpenSpout)
- MIT LICENSE with dual attribution
- `docs/` directory: installation, quick start, imports, exports, benchmarks, migration guide
