<?php

namespace MrDellimore\SheetStream\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Imports\Failure;

/**
 * Phase 2 of the staging-table pattern.
 *
 * Reads all pre-assigned rows for one (importId, sheetIndex, chunkNumber) from
 * the staging table, runs the import logic (validation, model save / array collect),
 * and marks each row processed or failed.
 *
 * Many of these jobs run in parallel across Horizon workers.
 *
 * Note for ToArray / ToCollection imports: your array() / collection() method will
 * be called once per chunk, not once for the entire sheet. Design your handler to
 * accumulate or process incrementally (e.g. upsert to a results table).
 */
class StagingChunkProcessorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $tries = null;

    public ?int $timeout = null;

    public function __construct(
        public readonly object $import,
        public readonly object $sheetImport,
        public readonly string $importId,
        public readonly int $sheetIndex,
        public readonly int $chunkNumber,
    ) {
        $this->tries = $sheetImport->tries ?? $import->tries ?? null;
        $this->timeout = $sheetImport->timeout ?? $import->timeout ?? null;
        $this->onQueue($sheetImport->queue ?? $import->queue ?? null);
        $this->onConnection($sheetImport->connection ?? $import->connection ?? null);
    }

    public function handle(): void
    {
        $stagingTable = config('sheet-stream.staging.table', 'sheet_stream_staging');

        $rows = DB::table($stagingTable)
            ->where('import_id', $this->importId)
            ->where('sheet_index', $this->sheetIndex)
            ->where('chunk_number', $this->chunkNumber)
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->orderBy('row_number')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $isToModel = $this->sheetImport instanceof ToModel;
        $isToArray = $this->sheetImport instanceof ToArray;
        $isToCollection = $this->sheetImport instanceof ToCollection;
        $hasValidation = $this->sheetImport instanceof WithValidation;
        $skipsOnFailure = $this->sheetImport instanceof SkipsOnFailure;
        $batchSize = $this->sheetImport instanceof WithBatchInserts
            ? $this->sheetImport->batchSize()
            : $rows->count();
        $rules = $hasValidation ? $this->sheetImport->rules() : [];

        $buffer = [];
        $modelBuffer = [];
        $failures = [];

        foreach ($rows as $staged) {
            $row = json_decode($staged->row_data, true);

            if ($hasValidation) {
                $validator = Validator::make($row, $rules);

                if ($validator->fails()) {
                    if ($skipsOnFailure) {
                        $failures[] = new Failure($staged->row_number, $validator->errors()->toArray(), $row);
                        DB::table($stagingTable)->where('id', $staged->id)->update([
                            'failed_at' => now(),
                            'error' => $validator->errors()->toJson(),
                        ]);

                        continue;
                    }

                    throw new ValidationException($validator);
                }
            }

            if ($isToModel) {
                $model = $this->sheetImport->model($row);

                if ($model !== null) {
                    $modelBuffer[] = $model;

                    if (count($modelBuffer) >= $batchSize) {
                        $this->flushModels($modelBuffer);
                        $modelBuffer = [];
                    }
                }
            } else {
                $buffer[] = $row;
            }

            DB::table($stagingTable)->where('id', $staged->id)->update(['processed_at' => now()]);
        }

        if ($isToModel && $modelBuffer !== []) {
            $this->flushModels($modelBuffer);
        }

        if ($skipsOnFailure && $failures !== []) {
            $this->sheetImport->onFailure(new Collection($failures));
        }

        if ($isToArray) {
            $this->sheetImport->array($buffer);
        } elseif ($isToCollection) {
            $this->sheetImport->collection(new Collection($buffer));
        }
    }

    /** @param \Illuminate\Database\Eloquent\Model[] $models */
    private function flushModels(array $models): void
    {
        foreach ($models as $model) {
            $model->save();
        }
    }
}
