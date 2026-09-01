<?php

namespace MrDellimore\SheetStream\Concerns;

/**
 * Enables the staging-table pattern for queued imports.
 *
 * Instead of one long-running job that reads the entire file, the package
 * uses a two-phase approach:
 *
 *   1. A producer job streams the file and bulk-inserts all rows into
 *      the sheet_stream_staging table, tagged by sheet and chunk number.
 *   2. One chunk-processor job per chunk is dispatched in parallel.
 *      Each job reads its pre-assigned rows and runs the import logic.
 *
 * Benefits:
 *   - No single job can time out on a large file.
 *   - All chunks run in parallel on Horizon workers.
 *   - Works across multiple sheets (rows are tagged by sheet_index).
 *   - Compatible with all Laravel DB drivers (MySQL, PostgreSQL, SQLite, SQL Server).
 *
 * Requires the sheet-stream migrations to be published and run:
 *   php artisan vendor:publish --tag=sheet-stream-migrations
 *   php artisan migrate
 */
interface UsesStagingTable {}
