# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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
