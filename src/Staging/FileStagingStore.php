<?php

namespace MrDellimore\SheetStream\Staging;

use Illuminate\Support\Collection;

/**
 * File-based staging store — writes one NDJSON file per chunk.
 *
 * Compared to the database store this eliminates:
 *  - All INSERT queries in the producer phase
 *  - The SELECT query per chunk in the consumer phase
 *  - All per-row UPDATE queries for processed_at / failed_at
 *
 * Trade-offs:
 *  - No per-row audit trail (processed_at / failed_at / error columns)
 *  - If a consumer job fails midway, the entire chunk is reprocessed on retry
 *    (acceptable when import logic is idempotent, e.g. upserts)
 *  - Requires local or shared filesystem accessible by all queue workers
 *
 * File layout:
 *   {basePath}/{importId}/s{sheetIndex}_c{chunkNumber}.ndjson
 *
 * Each line is a JSON object: {"row_number": N, "row_data": {...}}
 */
class FileStagingStore implements StagingStore
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function insertBatch(array $records): void
    {
        // Group records by chunk file so a batch that spans chunk boundaries
        // writes to the correct files.
        $groups = [];

        foreach ($records as $record) {
            $key = $record['import_id'].'/s'.$record['sheet_index'].'_c'.$record['chunk_number'];
            $groups[$key][] = $record;
        }

        foreach ($groups as $relativePath => $rows) {
            $filePath = $this->basePath.'/'.$relativePath.'.ndjson';

            $dir = dirname($filePath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $lines = '';
            foreach ($rows as $row) {
                $lines .= json_encode([
                    'row_number' => $row['row_number'],
                    'row_data' => $row['row_data'],
                ], JSON_UNESCAPED_UNICODE)."\n";
            }

            file_put_contents($filePath, $lines, FILE_APPEND | LOCK_EX);
        }
    }

    public function readChunk(string $importId, int $sheetIndex, int $chunkNumber): Collection
    {
        $filePath = $this->chunkPath($importId, $sheetIndex, $chunkNumber);

        if (! file_exists($filePath)) {
            return new Collection;
        }

        $content = file_get_contents($filePath);
        $lines = array_filter(explode("\n", $content), fn ($line) => $line !== '');

        $items = [];
        foreach ($lines as $index => $line) {
            $data = json_decode($line, true);
            $items[] = (object) [
                'id' => $index,
                'row_number' => $data['row_number'],
                'row_data' => $data['row_data'],
            ];
        }

        return new Collection($items);
    }

    public function markProcessed(mixed $rowId): void
    {
        // No-op — the chunk file is cleaned up after the consumer finishes.
    }

    public function markFailed(mixed $rowId, string $errorJson): void
    {
        // No-op — failures are reported via SkipsOnFailure::onFailure().
    }

    public function cleanupChunk(string $importId, int $sheetIndex, int $chunkNumber): void
    {
        $filePath = $this->chunkPath($importId, $sheetIndex, $chunkNumber);

        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        // Remove the import directory if it's now empty.
        $importDir = $this->basePath.'/'.$importId;
        if (is_dir($importDir) && count(scandir($importDir)) === 2) {
            @rmdir($importDir);
        }
    }

    private function chunkPath(string $importId, int $sheetIndex, int $chunkNumber): string
    {
        return $this->basePath.'/'.$importId.'/s'.$sheetIndex.'_c'.$chunkNumber.'.ndjson';
    }
}
