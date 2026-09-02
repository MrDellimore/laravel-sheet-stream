<?php

namespace MrDellimore\SheetStream\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use MrDellimore\SheetStream\Concerns\SkipsEmptyRows;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\ToCollection;
use MrDellimore\SheetStream\Concerns\ToModel;
use MrDellimore\SheetStream\Concerns\WithBatchInserts;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Engine\Contracts\Reader;
use MrDellimore\SheetStream\Engine\Contracts\SheetReader;
use MrDellimore\SheetStream\Events\AfterChunk;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\AfterSheet;
use MrDellimore\SheetStream\Events\BeforeImport;
use MrDellimore\SheetStream\Events\BeforeSheet;
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;
use MrDellimore\SheetStream\Support\EventBus;
use MrDellimore\SheetStream\Support\RowHelper;
use MrDellimore\SheetStream\Support\SheetResolver;

class ImportRunner
{
    public function __construct(
        private readonly int $defaultBatchSize = 1000,
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
        $isToModel = $import instanceof ToModel;
        $isToArray = $import instanceof ToArray;
        $isToCollection = $import instanceof ToCollection;
        $hasHeadingRow = $import instanceof WithHeadingRow;
        $skipsEmpty = $import instanceof SkipsEmptyRows;
        $hasValidation = $import instanceof WithValidation;
        $skipsOnFailure = $import instanceof SkipsOnFailure;
        $remembersRowNumber = method_exists($import, 'setRowNumber');
        $remembersChunkOffset = method_exists($import, 'setChunkOffset');

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
                $headings = RowHelper::normalizeHeadings($rawRow);
                $headingCount = count($headings);

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

        if ($isToModel && $buffer !== []) {
            RowHelper::flushModels($buffer);
            $bus?->dispatch(new AfterChunk($import, $sheetIndex, $chunkNumber, $rowsInChunk));
        } elseif (! $isToModel && $rowsInChunk > 0) {
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
        $outputCount = ($import instanceof ToModel ? 1 : 0)
                     + ($import instanceof ToArray ? 1 : 0)
                     + ($import instanceof ToCollection ? 1 : 0);

        if ($outputCount === 0) {
            throw new InvalidConcernCombination(
                'Import must implement at least one of: ToModel, ToArray, or ToCollection.'
            );
        }

        if ($outputCount > 1) {
            throw new InvalidConcernCombination(
                'Import must implement only one of: ToModel, ToArray, or ToCollection.'
            );
        }

        if ($import instanceof SkipsOnFailure && ! $import instanceof WithValidation) {
            throw new InvalidConcernCombination(
                'SkipsOnFailure requires WithValidation to also be implemented.'
            );
        }
    }
}
