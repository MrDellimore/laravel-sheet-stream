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
use MrDellimore\SheetStream\Concerns\WithChunkOffset;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithRequiredHeadings;
use MrDellimore\SheetStream\Concerns\WithRowNumber;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeImport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;
use MrDellimore\SheetStream\Exceptions\MissingHeadingsException;
use MrDellimore\SheetStream\Support\EventBus;
use MrDellimore\SheetStream\Support\RowHelper;
use MrDellimore\SheetStream\Support\SheetResolver;

class ImportRunner
{
    public function __construct(
        private readonly int $defaultBatchSize = 1000,
        private readonly string $headingFormatter = 'slug',
    ) {}

    public function runSheet(object $import, SheetReader $sheetReader, ?EventBus $bus = null, int $sheetIndex = 0): void
    {
        $this->validateConcerns($import);
        $this->processSheet($import, $sheetReader, $bus, $sheetIndex);
    }

    public function run(object $import, Reader $reader, ?EventBus $bus = null): void
    {
        $cachedSheets = $import instanceof WithMultipleSheets ? $import->sheets() : null;
        $subImports = $cachedSheets ?? [$import];

        foreach ($subImports as $subImport) {
            $this->validateConcerns($subImport);
        }

        $bus ??= EventBus::for($import);
        $bus?->dispatch(new BeforeImport($import));

        foreach ($reader->sheets() as $sheetIndex => $sheetReader) {
            $subImport = SheetResolver::resolve($import, $sheetIndex, $sheetReader->name(), $cachedSheets);

            if ($subImport !== null) {
                $sheetName = $sheetReader->name();
                $bus?->dispatch(new BeforeSheet($subImport, $sheetIndex, $sheetName));
                $this->processSheet($subImport, $sheetReader, $bus, $sheetIndex);
                $bus?->dispatch(new AfterSheet($subImport, $sheetIndex, $sheetName));
            }
        }

        $bus?->dispatch(new AfterImport($import));
    }

    private function processSheet(object $import, SheetReader $sheetReader, ?EventBus $bus = null, int $sheetIndex = 0): void
    {
        $isOnEachRow = $import instanceof OnEachRow;
        $isToModel = $import instanceof ToModel;
        $isToArray = $import instanceof ToArray;
        $isToCollection = $import instanceof ToCollection;
        $hasHeadingRow = $import instanceof WithHeadingRow;
        $skipsEmpty = $import instanceof SkipsEmptyRows;
        $hasValidation = $import instanceof WithValidation;
        $skipsOnFailure = $import instanceof SkipsOnFailure;
        $remembersRowNumber = $import instanceof WithRowNumber;
        $remembersChunkOffset = $import instanceof WithChunkOffset;

        $batchSize = $import instanceof WithBatchInserts
            ? $import->batchSize()
            : $this->defaultBatchSize;

        $rules = $hasValidation ? $import->rules() : [];

        $headings = null;
        $headingCount = 0;
        $buffer = [];
        /** @var Failure[] $failures */
        $failures = [];
        $rowNumber = 0;
        $chunkNumber = 0;
        $rowsInChunk = 0;
        $needsChunkOffset = true;

        foreach ($sheetReader->rows() as $rawRow) {
            $rowNumber++;
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

            $row = $headings !== null
                ? RowHelper::keyRow($rawRow, $headings, $headingCount)
                : $rawRow;

            if ($hasValidation) {
                $validator = Validator::make($row, $rules);

                if ($validator->fails()) {
                    if ($skipsOnFailure) {
                        $failures[] = new Failure($rowNumber, $validator->errors()->toArray(), $row);

                        continue;
                    }

                    throw new ValidationException($validator);
                }
            }

            if ($remembersRowNumber) {
                $import->setRowNumber($rowNumber);
            }

            if ($remembersChunkOffset && $needsChunkOffset) {
                $import->setChunkOffset($rowNumber);
                $needsChunkOffset = false;
            }

            if ($isOnEachRow) {
                $import->onRow($row);
                $rowsInChunk++;

                if ($rowsInChunk >= $batchSize) {
                    $bus?->dispatch(new AfterChunk($import, $sheetIndex, $chunkNumber, $rowsInChunk));
                    $chunkNumber++;
                    $rowsInChunk = 0;
                    $needsChunkOffset = true;
                }

                continue;
            }

            if ($isToModel) {
                $model = $import->model($row);

                if ($model !== null) {
                    $buffer[] = $model;
                    $rowsInChunk++;

                    if (count($buffer) >= $batchSize) {
                        RowHelper::flushModels($buffer);
                        $bus?->dispatch(new AfterChunk($import, $sheetIndex, $chunkNumber, $rowsInChunk));
                        $chunkNumber++;
                        $rowsInChunk = 0;
                        $needsChunkOffset = true;
                        $buffer = [];
                    }
                }

                continue;
            }

            $buffer[] = $row;
            $rowsInChunk++;

            if ($rowsInChunk >= $batchSize) {
                $bus?->dispatch(new AfterChunk($import, $sheetIndex, $chunkNumber, $rowsInChunk));
                $chunkNumber++;
                $rowsInChunk = 0;
                $needsChunkOffset = true;
            }
        }

        if ($isOnEachRow && $rowsInChunk > 0) {
            $bus?->dispatch(new AfterChunk($import, $sheetIndex, $chunkNumber, $rowsInChunk));
        } elseif ($isToModel && $buffer !== []) {
            RowHelper::flushModels($buffer);
            $bus?->dispatch(new AfterChunk($import, $sheetIndex, $chunkNumber, $rowsInChunk));
        } elseif (! $isToModel && ! $isOnEachRow && $rowsInChunk > 0) {
            $bus?->dispatch(new AfterChunk($import, $sheetIndex, $chunkNumber, $rowsInChunk));
        }

        if ($skipsOnFailure && $failures !== []) {
            $import->onFailure(new Collection($failures));
        }

        if ($isToArray) {
            $import->array($buffer);
        } elseif ($isToCollection) {
            $import->collection(new Collection($buffer));
        }
    }

    private function validateConcerns(object $import): void
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

        if ($import instanceof SkipsOnFailure && ! $import instanceof WithValidation) {
            throw new InvalidConcernCombination(
                'SkipsOnFailure requires WithValidation to also be implemented.'
            );
        }

        if ($import instanceof WithRequiredHeadings && ! $import instanceof WithHeadingRow) {
            throw new InvalidConcernCombination(
                'WithRequiredHeadings requires WithHeadingRow to also be implemented.'
            );
        }
    }
}
