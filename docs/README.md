# Laravel Sheet Stream Documentation

Streaming Excel imports & exports for Laravel, powered by OpenSpout.

## Table of contents

1. **[Installation](installation.md)** — Requirements, Composer install, config publishing
2. **[Quick Start](quick-start.md)** — Your first import and export in five minutes
3. **[Imports](imports.md)** — All import concerns: ToModel, ToCollection, ToArray, WithHeadingRow, WithBatchInserts, SkipsEmptyRows, WithValidation, SkipsOnFailure, WithMultipleSheets
4. **[Exports](exports.md)** — All export concerns: FromCollection, FromQuery, FromGenerator, WithHeadings, WithMapping, WithTitle, WithMultipleSheets
5. **[Staging Pipeline](staging-pipeline.md)** — Two-phase producer-consumer pattern for very large files: UsesStagingTable, database vs file drivers, architecture, and benchmarks
6. **[Configuration](configuration.md)** — Config reference: batch sizes, chunk sizes, date coercion, staging drivers
7. **[Comparison with Laravel Excel](comparison.md)** — Feature matrix and when to use which
8. **[Migration Guide](migration-guide.md)** — Coming from Laravel Excel? Concern-by-concern migration walkthrough
9. **[Benchmarks](benchmarks.md)** — Memory usage proof and how to run your own benchmarks

## Quick links

- [GitHub Repository](https://github.com/MrDellimore/laravel-sheet-stream)
- [Packagist](https://packagist.org/packages/mrdellimore/laravel-sheet-stream)
- [OpenSpout](https://github.com/openspout/openspout) (the engine)
- [Laravel Excel](https://github.com/SpartnerNL/Laravel-Excel) (the project that inspired the API)
