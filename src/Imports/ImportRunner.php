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
use MrDellimore\SheetStream\Exceptions\InvalidConcernCombination;
use MrDellimore\SheetStream\Support\RowHelper;
use MrDellimore\SheetStream\Support\SheetResolver;

class ImportRunner
{
    public function __construct(
        private readonly int $defaultBatchSize = 1000,
    ) {}

    public function runSheet(object $import, SheetReader $sheetReader): void
    {
        $this->validateConcerns($import);
        $this->processSheet($import, $sheetReader);
    }

    public function run(object $import, Reader $reader): void
    {
        $cachedSheets = $import instanceof WithMultipleSheets ? $import->sheets() : null;
        $subImports = $cachedSheets ?? [$import];

        foreach ($subImports as $subImport) {
            $this->validateConcerns($subImport);
        }

        foreach ($reader->sheets() as $sheetIndex => $sheetReader) {
            $subImport = SheetResolver::resolve($import, $sheetIndex, $sheetReader->name(), $cachedSheets);

            if ($subImport !== null) {
                $this->processSheet($subImport, $sheetReader);
            }
        }
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

            if ($isToModel) {
                $model = $import->model($row);

                if ($model !== null) {
                    $buffer[] = $model;

                    if (count($buffer) >= $batchSize) {
                        RowHelper::flushModels($buffer);
                        $buffer = [];
                    }
                }

                continue;
            }

            $buffer[] = $row;
        }

        if ($isToModel && $buffer !== []) {
            RowHelper::flushModels($buffer);
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
