# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added
- `ImportRunner`: streaming import orchestration loop — one row in memory at a time
- `ExportRunner`: streaming export orchestration — `FromCollection`, `FromQuery` (via
  `lazy()`), `FromGenerator`
- `SheetStreamManager`: `import()`, `download()` (StreamedResponse), `store()` (Laravel
  filesystem disk)
- Import concerns: `ToModel`, `ToCollection`, `ToArray`, `WithHeadingRow`, `WithBatchInserts`,
  `WithValidation`, `SkipsOnFailure`, `SkipsEmptyRows`, `WithMultipleSheets`
- Export concerns: `FromCollection`, `FromQuery`, `FromGenerator`, `WithHeadings`,
  `WithMapping`, `WithTitle`, `WithMultipleSheets`
- Engine contracts: `Reader`, `SheetReader`, `Writer`
- OpenSpout engine: `OpenSpoutReader`, `OpenSpoutSheetReader`, `OpenSpoutWriter` (XLSX, CSV, ODS)
- `UnsupportedByEngine` exception for `.xls` and unsupported formats
- `SheetStreamServiceProvider` with auto-discovery and config publishing
- `SheetStream` facade
- `config/sheet-stream.php` with `batch_size`, `chunk_size`, `dates` settings
- Pest test suite: package boot, import runner, export round-trip

---

## [0.1.0] — planned

First tagged release. See [milestone roadmap](laravel-sheet-stream-PLAN.md#10-milestone-roadmap).
