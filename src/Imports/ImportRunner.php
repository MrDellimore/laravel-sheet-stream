<?php

namespace MrDellimore\SheetStream\Imports;

use Illuminate\Database\Eloquent\Model;
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
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;

class ImportRunner
{
    public function __construct(
        private int $defaultBatchSize = 1000,
    ) {}

    /**
     * Process a single already-resolved sheet reader against a sub-import.
     * Used by QueuedSheetImportJob when sheet-level parallelism is active.
     */
    public function runSheet(object $import, SheetReader $sheetReader): void
    {
        $this->validateConcerns($import);
        $this->processSheet($import, $sheetReader);
    }

    public function run(object $import, Reader $reader): void
    {
        $subImports = $import instanceof WithMultipleSheets ? $import->sheets() : [$import];

        foreach ($subImports as $subImport) {
            $this->validateConcerns($subImport);
        }

        foreach ($reader->sheets() as $sheetIndex => $sheetReader) {
            $subImport = $this->resolveSheetImport($import, $sheetIndex, $sheetReader->name());

            if ($subImport !== null) {
                $this->processSheet($subImport, $sheetReader);
            }
        }
    }

    private function resolveSheetImport(object $import, int $sheetIndex, string $sheetName): ?object
    {
        if ($import instanceof WithMultipleSheets) {
            $sheets = $import->sheets();

            return $sheets[$sheetIndex] ?? $sheets[$sheetName] ?? null;
        }

        return $sheetIndex === 0 ? $import : null;
    }

    private function processSheet(object $import, SheetReader $sheetReader): void
    {
        $isToModel = $import instanceof ToModel;
        $isToArray = $import instanceof ToArray;
        $isToCollection = $import instanceof ToCollection;
        $hasHeadingRow = $import instanceof WithHeadingRow;
        $skipsEmpty = $import instanceof SkipsEmptyRows;
        $hasValidation = $import instanceof WithValidation;
        $skipsOnFailure = $import instanceof SkipsOnFailure;

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

        foreach ($sheetReader->rows() as $rawRow) {
            $rowNumber++;
            $rawRow = array_values($rawRow);

            if ($headings === null && $hasHeadingRow) {
                $headings = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rawRow);
                $headingCount = count($headings);

                continue;
            }

            if ($skipsEmpty && $this->isEmptyRow($rawRow)) {
                continue;
            }

            if ($headings !== null) {
                $padded = array_pad($rawRow, $headingCount, null);
                $row = array_combine($headings, array_slice($padded, 0, $headingCount));
            } else {
                $row = $rawRow;
            }

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

            if ($isToModel) {
                $model = $import->model($row);

                if ($model !== null) {
                    $buffer[] = $model;

                    if (count($buffer) >= $batchSize) {
                        $this->flushModels($buffer);
                        $buffer = [];
                    }
                }

                continue;
            }

            $buffer[] = $row;
        }

        if ($isToModel && $buffer !== []) {
            $this->flushModels($buffer);
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

    /** @param Model[] $models */
    private function flushModels(array $models): void
    {
        foreach ($models as $model) {
            $model->save();
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

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }

        return true;
    }
}
