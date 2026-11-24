<?php

namespace MrDellimore\SheetStream\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MrDellimore\SheetStream\Concerns\OnEachRow;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithChunkReading;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithRequiredHeadings;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Engine\Contracts\KeysRowsBySourceRow;
use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeImport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;
use MrDellimore\SheetStream\Exceptions\MissingHeadingsException;
use MrDellimore\SheetStream\Support\CollectionRows;
use MrDellimore\SheetStream\Support\EventBus;
use MrDellimore\SheetStream\Support\RowHelper;
use MrDellimore\SheetStream\Support\SheetResolver;

class ImportRunner
{
    public function __construct(
        private readonly int $defaultBatchSize = 1000,
        private readonly string $headingFormatter = 'slug',
        private readonly string $collectionRows = CollectionRows::AS_COLLECTION,
        private readonly bool $blankCellsAsNull = true,
    ) {
        CollectionRows::assertValid($collectionRows);
    }

    /**
     * @param  object|null  $parentImport  the WithMultipleSheets import the sheet belongs to: a sheet
     *                                     with no chunkSize() of its own reads in chunks of the parent's
     */
    public function runSheet(object $import, SheetReader $sheetReader, ?EventBus $bus = null, int $sheetIndex = 0, ?object $parentImport = null): void
    {
        $this->validateConcerns($import, $parentImport);
        $this->processSheet($import, $sheetReader, $bus, $sheetIndex, $parentImport);
    }

    public function run(object $import, Reader $reader, ?EventBus $bus = null): void
    {
        $cachedSheets = $import instanceof WithMultipleSheets ? $import->sheets() : null;
        $subImports = $cachedSheets ?? [$import];

        foreach ($subImports as $subImport) {
            $this->validateConcerns($subImport, $import);
        }

        $bus ??= EventBus::for($import);
        $bus?->dispatch(new BeforeImport($import));

        foreach ($reader->sheets() as $sheetIndex => $sheetReader) {
            $subImport = SheetResolver::resolve($import, $sheetIndex, $sheetReader->name(), $cachedSheets);

            if ($subImport !== null) {
                $sheetName = $sheetReader->name();
                // Sheet events go to the sub-import's own listeners where it has them.
                $sheetBus = EventBus::forSheet($bus, $import, $subImport);
                $sheetBus?->dispatch(new BeforeSheet($subImport, $sheetIndex, $sheetName));
                $this->processSheet($subImport, $sheetReader, $sheetBus, $sheetIndex, $import);
                $sheetBus?->dispatch(new AfterSheet($subImport, $sheetIndex, $sheetName));
            }
        }

        $bus?->dispatch(new AfterImport($import));
    }

    private function processSheet(object $import, SheetReader $sheetReader, ?EventBus $bus = null, int $sheetIndex = 0, ?object $parentImport = null): void
    {
        $isOnEachRow = $import instanceof OnEachRow;
        $isToModel = $import instanceof ToModel;
        $hasHeadingRow = $import instanceof WithHeadingRow;
        $skipsEmpty = $import instanceof SkipsEmptyRows;
        $hasValidation = $import instanceof WithValidation;
        $skipsOnFailure = $import instanceof SkipsOnFailure;
        // Laravel Excel parity: the RemembersRowNumber / RemembersChunkOffset traits work on their
        // own, so the setters are detected by name; WithRowNumber / WithChunkOffset only document them.
        $remembersRowNumber = method_exists($import, 'setRowNumber');

        $batchSize = $import instanceof WithBatchInserts
            ? $import->batchSize()
            : $this->defaultBatchSize;

        // With WithChunkReading a chunk is chunkSize() rows, counted before validation, and the
        // import is handed each chunk as it fills. Without it nothing is handed over until the
        // sheet ends, and a "chunk" is only AfterChunk's cadence: batchSize delivered rows.
        $chunkSize = $this->chunkSize($import, $parentImport);
        $chunked = $chunkSize !== null;
        $rowsPerChunk = max(1, $chunkSize ?? $batchSize);

        $rules = $hasValidation ? $import->rules() : [];
        $keyedBySourceRow = $sheetReader instanceof KeysRowsBySourceRow;

        $headings = null;
        $headingCount = 0;
        $rowNumber = 0;
        $chunk = new ChunkState;

        foreach ($sheetReader->rows() as $key => $rawRow) {
            // A reader that knows the source row says so; otherwise rows are numbered as they
            // arrive. max() keeps the numbers ascending whatever a malformed file claims.
            $rowNumber = $keyedBySourceRow ? max((int) $key, $rowNumber + 1) : $rowNumber + 1;
            $rawRow = array_values($rawRow);

            if ($headings === null && $hasHeadingRow) {
                $headings = RowHelper::normalizeHeadings($rawRow, $this->headingFormatter);
                $headingCount = count($headings);

                if ($import instanceof WithRequiredHeadings) {
                    $missing = array_diff($import->requiredHeadings(), $headings);

                    if ($missing !== []) {
                        throw new MissingHeadingsException(array_values($missing), $sheetReader->name());
                    }
                }

                continue;
            }

            if ($skipsEmpty && RowHelper::isEmptyRow($rawRow)) {
                continue;
            }

            if ($this->blankCellsAsNull) {
                $rawRow = RowHelper::blanksToNull($rawRow);
            }

            $row = $headings !== null
                ? RowHelper::keyRow($rawRow, $headings, $headingCount)
                : $rawRow;

            if ($chunked) {
                // Counted before validation: a failed row still takes its slot, so chunk
                // boundaries depend on the file alone.
                if ($chunk->startRow === null) {
                    $this->startChunk($chunk, $import, $rowNumber);
                }

                $chunk->rowCount++;
            }

            $failure = $hasValidation ? $this->validate($row, $rules, $rowNumber, $skipsOnFailure) : null;

            if ($failure instanceof Failure) {
                $chunk->failures[] = $failure;
            } else {
                // Without chunk reading a chunk starts at its first valid row.
                if ($chunk->startRow === null) {
                    $this->startChunk($chunk, $import, $rowNumber);
                }

                if ($remembersRowNumber) {
                    $import->setRowNumber($rowNumber);
                }

                $delivered = true;

                if ($isOnEachRow) {
                    $import->onRow($row);
                } elseif ($isToModel) {
                    $model = $import->model($row);
                    $delivered = $model !== null;

                    if ($delivered) {
                        $chunk->models[] = $model;
                    }

                    // The chunk boundary decides AfterChunk; batchSize still caps each save.
                    if ($chunked && count($chunk->models) >= $batchSize) {
                        $this->saveModels($chunk, $import);
                    }
                } elseif ($chunked) {
                    // Keyed so that getChunkOffset() + key is the row's number; rows that
                    // failed or were skipped leave gaps.
                    $chunk->rows[$rowNumber - $chunk->startRow] = $row;
                } else {
                    $chunk->rows[] = $row;
                }

                if (! $chunked && $delivered) {
                    $chunk->rowCount++;
                }
            }

            if ($chunk->rowCount >= $rowsPerChunk) {
                $this->closeChunk($chunk, $import, $bus, $sheetIndex, $chunked);
            }
        }

        if ($chunk->rowCount > 0) {
            $this->closeChunk($chunk, $import, $bus, $sheetIndex, $chunked);
        }

        if (! $chunked) {
            $this->deliverFailures($chunk, $import);
            $this->deliverRows($chunk, $import);
        }
    }

    /** Give the chunk its first row, which is also the import's chunk offset. */
    private function startChunk(ChunkState $chunk, object $import, int $rowNumber): void
    {
        $chunk->startRow = $rowNumber;

        if (method_exists($import, 'setChunkOffset')) {
            $import->setChunkOffset($rowNumber);
        }
    }

    /**
     * With WithChunkReading: onFailure() → collection() / array() / save the remaining models
     * → AfterChunk, then let go of the chunk. Without it the rows and failures stay buffered
     * for the end of the sheet, so this only saves the batch and fires AfterChunk.
     */
    private function closeChunk(ChunkState $chunk, object $import, ?EventBus $bus, int $sheetIndex, bool $chunked): void
    {
        if ($chunked) {
            $this->deliverFailures($chunk, $import);

            // A chunk whose rows all failed has nothing to hand over, but AfterChunk still
            // fires so progress keeps moving.
            if ($chunk->rows !== []) {
                $this->deliverRows($chunk, $import);
            }

            $chunk->release();
        }

        $this->saveModels($chunk, $import);

        $bus?->dispatch(new AfterChunk($import, $sheetIndex, $chunk->number, $chunk->rowCount, $chunk->startRow));

        $chunk->advance();
    }

    private function saveModels(ChunkState $chunk, object $import): void
    {
        if ($chunk->models !== []) {
            RowHelper::flushModels($chunk->models, $import);
            $chunk->models = [];
        }
    }

    private function deliverFailures(ChunkState $chunk, object $import): void
    {
        if ($import instanceof SkipsOnFailure && $chunk->failures !== []) {
            $import->onFailure(new Collection($chunk->failures));
        }
    }

    private function deliverRows(ChunkState $chunk, object $import): void
    {
        if ($import instanceof ToArray) {
            $import->array($chunk->rows);
        } elseif ($import instanceof ToCollection) {
            $import->collection(CollectionRows::wrap($chunk->rows, $this->collectionRows));
        }
    }

    /**
     * Null when the row is valid. An invalid row throws, or comes back as a Failure when the
     * import skips failures.
     *
     * @param  array<int|string, mixed>  $row
     * @param  array<string, mixed>  $rules
     */
    private function validate(array $row, array $rules, int $rowNumber, bool $skipsOnFailure): ?Failure
    {
        $validator = Validator::make($row, $rules);

        if (! $validator->fails()) {
            return null;
        }

        if (! $skipsOnFailure) {
            throw new ValidationException($validator);
        }

        return new Failure($rowNumber, $validator->errors()->toArray(), $row);
    }

    /**
     * The sheet import's own chunkSize() wins, then the parent WithMultipleSheets import's
     * (where Laravel Excel reads it). Null when neither reads in chunks.
     */
    private function chunkSize(object $import, ?object $parentImport): ?int
    {
        foreach ([$import, $parentImport] as $candidate) {
            if ($candidate instanceof WithChunkReading) {
                return $candidate->chunkSize();
            }
        }

        return null;
    }

    private function validateConcerns(object $import, ?object $parentImport = null): void
    {
        $outputCount = ($import instanceof OnEachRow ? 1 : 0)
                     + ($import instanceof ToModel ? 1 : 0)
                     + ($import instanceof ToArray ? 1 : 0)
                     + ($import instanceof ToCollection ? 1 : 0);

        if ($outputCount === 0) {
            throw new InvalidConcernCombination(
                'Import must implement at least one of: OnEachRow, ToModel, ToArray, or ToCollection.'
            );
        }

        if ($outputCount > 1) {
            throw new InvalidConcernCombination(
                'Import must implement only one of: OnEachRow, ToModel, ToArray, or ToCollection.'
            );
        }

        if ($import instanceof WithRequiredHeadings && ! $import instanceof WithHeadingRow) {
            throw new InvalidConcernCombination(
                'WithRequiredHeadings requires WithHeadingRow to also be implemented.'
            );
        }

        $chunkSize = $this->chunkSize($import, $parentImport);

        if ($chunkSize !== null && $chunkSize < 1) {
            throw new InvalidConcernCombination(
                "WithChunkReading::chunkSize() must be at least 1, got {$chunkSize}."
            );
        }
    }
}
