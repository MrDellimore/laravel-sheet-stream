<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

/**
 * Enables automatic XLSX/ODS → CSV pre-conversion for faster staging.
 *
 * When applied to an import that uses the staging pipeline (UsesStagingTable),
 * the producer job will convert the spreadsheet to CSV files before reading,
 * using a fast native converter (ssconvert from Gnumeric by default).
 *
 * This can dramatically speed up the producer phase for large XLSX files
 * by avoiding PHP-based XML parsing. The converted CSVs are read with
 * PHP's native fgetcsv() through OpenSpout's CSV reader.
 *
 * Requirements:
 *   - A supported converter must be installed on the system:
 *     - ssconvert (Gnumeric): `apt install gnumeric` or `brew install gnumeric`
 *     - xlsx2csv (Python):    `pip install xlsx2csv`
 *   - Configure the binary path if needed: `SHEET_STREAM_CSV_CONVERTER=ssconvert`
 *
 * CSV files are automatically cleaned up after the producer phase completes.
 *
 * Note: Only activates for XLSX and ODS files. CSV imports pass through unchanged.
 */
interface WithCsvPreConversion {}
