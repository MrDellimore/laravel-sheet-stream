<?php

namespace MrDellimore\SheetStream\Staging;

use Illuminate\Support\Collection;

/**
 * Abstraction over the storage mechanism used by the staging-table pipeline.
 *
 * The producer job writes batches of rows via {@see insertBatch()}.
 * The consumer job reads a chunk's rows via {@see readChunk()}, processes
 * them, and marks each row processed or failed.
 *
 * Implementations:
 *  - DatabaseStagingStore  – the original approach; writes to a DB table.
 *  - FileStagingStore      – writes per-chunk NDJSON files for speed.
 */
interface StagingStore
{
    /**
     * Insert a batch of staged rows.
     *
     * Each record is an associative array with keys:
     *   import_id, sheet_index, sheet_name, chunk_number, row_number,
     *   row_data (raw PHP array — the store handles serialisation),
     *   created_at.
     *
     * A single batch may contain rows belonging to different chunks.
     */
    public function insertBatch(array $records): void;

    /**
     * Read all unprocessed rows for a given chunk.
     *
     * Returns a Collection of stdClass objects, each with at minimum:
     *   - id        mixed   (row identifier for markProcessed / markFailed)
     *   - row_number  int
     *   - row_data    array  (already deserialised — never a JSON string)
     */
    public function readChunk(string $importId, int $sheetIndex, int $chunkNumber): Collection;

    /**
     * Mark a single row as successfully processed.
     */
    public function markProcessed(mixed $rowId): void;

    /**
     * Mark a single row as failed, recording the error JSON.
     */
    public function markFailed(mixed $rowId, string $errorJson): void;

    /**
     * Clean up storage for a specific chunk after it has been processed.
     *
     * Called by the consumer job after all rows in the chunk are handled.
     * For the database driver this is a no-op (rows stay for audit);
     * for the file driver this deletes the chunk file.
     */
    public function cleanupChunk(string $importId, int $sheetIndex, int $chunkNumber): void;
}
