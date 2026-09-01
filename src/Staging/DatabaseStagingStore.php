<?php

namespace MrDellimore\SheetStream\Staging;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DatabaseStagingStore implements StagingStore
{
    public function __construct(
        private readonly string $table = 'sheet_stream_staging',
    ) {}

    public function insertBatch(array $records): void
    {
        // JSON-encode each row_data array for storage in the longText column.
        $rows = array_map(function (array $record) {
            $record['row_data'] = json_encode($record['row_data']);

            return $record;
        }, $records);

        // Wrap in a transaction so databases that auto-commit per statement
        // (e.g. SQLite) don't pay a sync penalty per row.
        DB::transaction(fn () => DB::table($this->table)->insert($rows));
    }

    public function readChunk(string $importId, int $sheetIndex, int $chunkNumber): Collection
    {
        $rows = DB::table($this->table)
            ->where('import_id', $importId)
            ->where('sheet_index', $sheetIndex)
            ->where('chunk_number', $chunkNumber)
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->orderBy('row_number')
            ->get();

        // Decode row_data from JSON string to array so callers always see arrays.
        return $rows->map(function ($row) {
            $row->row_data = json_decode($row->row_data, true);

            return $row;
        });
    }

    public function markProcessed(mixed $rowId): void
    {
        DB::table($this->table)->where('id', $rowId)->update(['processed_at' => now()]);
    }

    public function markProcessedBatch(array $rowIds): void
    {
        if ($rowIds === []) {
            return;
        }

        DB::table($this->table)->whereIn('id', $rowIds)->update(['processed_at' => now()]);
    }

    public function markFailed(mixed $rowId, string $errorJson): void
    {
        DB::table($this->table)->where('id', $rowId)->update([
            'failed_at' => now(),
            'error' => $errorJson,
        ]);
    }

    public function cleanupChunk(string $importId, int $sheetIndex, int $chunkNumber): void
    {
        // No-op — database rows are retained for auditing / recovery.
    }
}
