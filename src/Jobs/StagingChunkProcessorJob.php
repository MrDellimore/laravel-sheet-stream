<?php

namespace MrDellimore\SheetStream\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\ImportFailed;
use MrDellimore\SheetStream\Imports\Failure;
use MrDellimore\SheetStream\Staging\StagingStore;
use MrDellimore\SheetStream\Support\ConfiguresFromConcern;
use MrDellimore\SheetStream\Support\EventBus;
use MrDellimore\SheetStream\Support\RowHelper;

class StagingChunkProcessorJob implements ShouldQueue
{
    use ConfiguresFromConcern, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?int $tries = null;

    public ?int $timeout = null;

    public function __construct(
        public readonly object $import,
        public readonly object $sheetImport,
        public readonly string $importId,
        public readonly int $sheetIndex,
        public readonly int $chunkNumber,
    ) {
        $this->applyJobConfig($sheetImport, $import);
    }

    public function handle(): void
    {
        $store = app(StagingStore::class);

        $rows = $store->readChunk($this->importId, $this->sheetIndex, $this->chunkNumber);

        if ($rows->isEmpty()) {
            return;
        }

        $bus = EventBus::for($this->import);
        $bus?->merge($this->sheetImport);

        $isToModel = $this->sheetImport instanceof ToModel;
        $isToArray = $this->sheetImport instanceof ToArray;
        $isToCollection = $this->sheetImport instanceof ToCollection;
        $hasValidation = $this->sheetImport instanceof WithValidation;
        $skipsOnFailure = $this->sheetImport instanceof SkipsOnFailure;
        $batchSize = $this->sheetImport instanceof WithBatchInserts
            ? $this->sheetImport->batchSize()
            : $rows->count();
        $rules = $hasValidation ? $this->sheetImport->rules() : [];

        $remembersRowNumber = method_exists($this->sheetImport, 'setRowNumber');
        $remembersChunkOffset = method_exists($this->sheetImport, 'setChunkOffset');

        $buffer = [];
        $modelBuffer = [];
        $failures = [];
        $processedIds = [];

        try {
            if ($remembersChunkOffset && $rows->isNotEmpty()) {
                $this->sheetImport->setChunkOffset($rows->first()->row_number);
            }

            foreach ($rows as $staged) {
                $row = $staged->row_data;

                if ($hasValidation) {
                    $validator = Validator::make($row, $rules);

                    if ($validator->fails()) {
                        if ($skipsOnFailure) {
                            $failures[] = new Failure($staged->row_number, $validator->errors()->toArray(), $row);
                            $store->markFailed($staged->id, $validator->errors()->toJson());

                            continue;
                        }

                        throw new ValidationException($validator);
                    }
                }

                if ($remembersRowNumber) {
                    $this->sheetImport->setRowNumber($staged->row_number);
                }

                if ($isToModel) {
                    $model = $this->sheetImport->model($row);

                    if ($model !== null) {
                        $modelBuffer[] = $model;

                        if (count($modelBuffer) >= $batchSize) {
                            RowHelper::flushModels($modelBuffer);
                            $modelBuffer = [];
                        }
                    }
                } else {
                    $buffer[] = $row;
                }

                $processedIds[] = $staged->id;
            }

            if ($processedIds !== []) {
                $store->markProcessedBatch($processedIds);
            }

            if ($isToModel && $modelBuffer !== []) {
                RowHelper::flushModels($modelBuffer);
            }

            if ($skipsOnFailure && $failures !== []) {
                $this->sheetImport->onFailure(new Collection($failures));
            }

            if ($isToArray) {
                $this->sheetImport->array($buffer);
            } elseif ($isToCollection) {
                $this->sheetImport->collection(new Collection($buffer));
            }

            $bus?->dispatch(new AfterChunk(
                $this->sheetImport,
                $this->sheetIndex,
                $this->chunkNumber,
                $rows->count(),
            ));
        } catch (\Throwable $e) {
            $bus?->dispatch(new ImportFailed($this->import, $e));

            throw $e;
        }

        $store->cleanupChunk($this->importId, $this->sheetIndex, $this->chunkNumber);
    }
}
