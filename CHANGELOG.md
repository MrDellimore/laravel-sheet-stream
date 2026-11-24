# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.3.0] — 2026-11-24

This release closes the gaps that kept Laravel Excel `ToCollection` importers from moving over unchanged. Several defaults change to match Laravel Excel; each has a config key or an opt-out where one makes sense. Read **Behaviour changes** before upgrading.

### Behaviour changes

- **`ToCollection` rows are `Illuminate\Support\Collection` instances**, as in Laravel Excel, so `$row->toArray()` and `$row->get('x')` work. Array access, `isset()` and `foreach` are unaffected; `is_array($row)` and `array_*($row)` are not. Set `collection_rows` to `'array'` to keep plain arrays. `ToArray` rows are still arrays.
- **Blank cells reach the import as `null`**, wherever they sit in the row. Before, a blank inside a row was `''` and only trailing blanks were `null`. Validation now sees `null` too, so a rule such as `'qty' => 'integer'` fails on a blank cell unless it is `nullable`. A cell that explicitly stores an empty string is also `null`. Set `blank_cells` to `''` for the previous behaviour.
- **`ToModel` + `WithBatchInserts` (batch size above 1) bulk-inserts**: one `INSERT` per model class per batch, as Laravel Excel does. Model events, observers and auditing do not fire for those rows, and the models' keys are not filled in afterwards. Imports without `WithBatchInserts` still `save()` each model. Models that already exist are still updated through `save()`.
- **A sub-import's own `BeforeSheet` / `AfterSheet` / `AfterChunk` listeners now fire** for its sheet, on every path. When a sub-import registers a listener for one of those events, the parent import's listener for the same event no longer fires for that sheet (Laravel Excel's behaviour). Sheets that register nothing keep receiving the parent's listeners.
- **Queued paths no longer fire sheet events twice.** With `WithParallelSheets` and in the staging pipeline, parent and sheet listeners both used to run; a staged single-sheet import fired every `AfterChunk` listener twice. Each now fires once. Sub-imports no longer receive `ImportFailed`; only the top-level import does.
- **Row numbers are the file's own on the `rust` and `phpspreadsheet` readers.** `Failure::row()`, `setRowNumber()` and `setChunkOffset()` used to count yielded rows, so every row below a blank line was numbered low on the `rust` reader. The `openspout` reader (and the files the `rust` reader hands to it) still counts yielded rows.
- **Date-styled cells before 1900-03-01, including every time-only cell, are no longer a day low** on the `openspout` reader, or on the `rust` reader with `dates.timezone` set: `18:00` is `0.75`, not `-0.25`. `CellNormalizer::dateToSerial()` no longer subtracts a day below serial 61.
- `SkipsOnFailure` without `WithValidation` is allowed (it never fires) instead of throwing `InvalidConcernCombination`.

### Added

- **`WithChunkReading`** (`chunkSize(): int`). `collection()` / `array()` receive one chunk at a time instead of the whole sheet, so memory stays flat. Per chunk: `onFailure()` → `collection()` / `array()` / the remaining `ToModel` batch → `AfterChunk`. A chunk is N consecutive data rows that survive `SkipsEmptyRows`, counted before validation, so `ceil(rows / chunkSize)` is the exact number of `AfterChunk` events. On a `WithMultipleSheets` import the parent's `chunkSize()` applies to sheets that declare none. In a chunk, `getChunkOffset() + $key` is the row's number. Without the concern nothing changes: one call per sheet, `AfterChunk` per `batchSize`. See [imports](docs/imports.md#chunk-reading).
- `AfterChunk::$startRow`: the row number of the chunk's first row.
- `SheetStream::headers($path, $disk)`: the first row of every sheet, keyed by sheet name, without reading the rest of the file. `SheetStream::countRows($import, $path, $disk)`: the number of data rows an import of the file would deliver, per sheet. Both use the configured reader. See [imports](docs/imports.md#inspecting-a-file-before-importing).
- `EventBus::forSheet()` and `EventBus::SHEET_EVENTS`.
- Config `formulas_without_cached_value` (`'null'` | `'throw'` | `'formula'`): what a `WithCalculatedFormulas` import receives for a formula the file holds no cached result for. `rust` reader only; `'throw'` and `'formula'` need `rusty_sheet` 0.1.3.
- Config `collection_rows` and `blank_cells` (see above).
- `RemembersChunkOffset` and `RemembersRowNumber` work without also implementing `WithChunkOffset` / `WithRowNumber`.

### Changed

- The staging producer calls the import's `sheets()` once per import instead of once per sheet.

### Deprecated

- `EventBus::merge()`. Nothing in the package uses it any more.

---

## [1.2.0] — 2026-11-24

### Added

- **`rust` reader driver** (`'default_reader' => 'rust'`), backed by the `rusty_sheet` PHP extension. It is a streaming XLSX reader that read a real 86 MB, 396k-row workbook in 3.4 s, against about 25 minutes with the OpenSpout driver. It also reads `.csv`/`.tsv` (about 10× faster) and supports `WithFormatData`. `.ods` and non-UTF-8 CSV fall back to OpenSpout per file. It throws if the extension isn't loaded. See [configuration](docs/configuration.md#default_reader) for its small, deliberate differences from the OpenSpout driver.
- **`rust` writer driver** (`'default_writer' => 'rust'`): writes the same XLSX and CSV files as the OpenSpout driver, about 7× faster for XLSX and about 3× faster for CSV, with flat memory. It supports Style objects, native rows and cells, and `WithSheetSetup` (column widths, frozen panes, gridlines). Cell borders, hyperlinks, comments, merged cells, data validation and a `WithSheetSetup` autofilter are written like OpenSpout writes them. `.ods` and other non-default `XlsxOptions` fall back to OpenSpout; images, rich text and hidden or outlined rows raise `UnsupportedByEngine`.
- The `rust` drivers need `rusty_sheet` `^0.1` (`pie install "mrdellimore/rusty-sheet:^0.1"`). They throw a clear error when the extension is missing or its version is outside that range, and `composer.json` suggests `ext-rusty_sheet`.
- `benchmarks/bench-reader.php` and `benchmarks/bench-writer.php`: time any reader or writer driver.
- Run the whole test suite against other drivers with `SHEET_STREAM_TEST_READER=rust` / `SHEET_STREAM_TEST_WRITER=rust`.

### Changed

- CI no longer runs a Laravel 11 job. An advisory fixed only in Laravel 12.71.0 and 13.40.0 (CVE-2026-128083) blocks every 11.x release. `composer.json` still allows `^11.0`, but it is untested.

---

## [1.1.0] — 2026-11-10

> **Note:** this is a minor release, but it contains breaking changes — it
> requires PHP `^8.4` (was `^8.3`) and OpenSpout `^5.12` (was `^4.30`). A `^1.0`
> constraint will pick it up automatically. Pin to `1.0.*` if you are not ready
> to move, and read *Upgrading to OpenSpout 5* below before updating.

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

### Added
- Export rows may contain OpenSpout `Cell` objects, written as they are with their own style (per-cell styling; text starting with `=` stays text). The phpspreadsheet driver writes the cell's plain value.
- Export rows may be OpenSpout `Row` objects yielded by a `FromGenerator` / `FromCollection` / `FromQuery` source, written as they are, row height included.
- `WithSheetSetup` concern: configure the engine's native sheet (frozen panes, gridlines, column widths) before any row is written. Backed by the new `Engine\Contracts\ExposesNativeSheet`, implemented by both writers.
- `AfterWriting` export event with the finished file's local path, fired after the writer closes and before the file is stored, downloaded or uploaded by `QueuedExportJob`; the temporary file is removed if a listener throws.
- `Engine\Contracts\WritesNativeRows`, implemented by the OpenSpout writer.

---

## [1.0.0] — 2026-09-23

### Changed
- **Breaking:** date-styled cells now reach imports as **Excel serial numbers** (int, or float with a time part) instead of `DateTimeImmutable`, matching Laravel Excel. Import helpers written against Laravel Excel (`Date::excelToDateTimeObject()`, `Carbon::createFromFormat()`, `trim()` on every cell…) work without modification. Set `dates.import_as` to `'datetime'` to keep the previous behaviour.
- **Breaking:** the unused `dates.coerce` config key is replaced by `dates.import_as` (`'serial'` | `'datetime'`).
- `dates.timezone` is applied before serial conversion, so the serial reflects the local wall-clock time.
- `PhpSpreadsheetSheetReader` now takes the reader options array (same shape as `OpenSpoutSheetReader`) instead of positional timezone / formula arguments.

### Added
- `WithFormatData` concern — receive cells as display strings rendered through their number format (Laravel Excel parity). Enables `SHOULD_FORMAT_DATES` on the OpenSpout engine, layered onto any `WithReaderOptions`; uses `NumberFormat::toFormattedString()` on the PhpSpreadsheet engine.
- `Support\CellNormalizer` — converts `DateTimeInterface` / `DateInterval` cells to 1900-system Excel serials, including the 1900 leap-year quirk for dates before March 1900 (removed in the next release: the quirk made those serials a day low).
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
