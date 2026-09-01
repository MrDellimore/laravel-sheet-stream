<?php

namespace MrDellimore\SheetStream\Imports;

/**
 * Represents a single row that failed validation during import.
 */
class Failure
{
    /**
     * @param  int  $row  1-based row number in the spreadsheet (includes the heading row).
     * @param  array<string, list<string>>  $errors  Validation errors keyed by attribute.
     * @param  array<string, mixed>  $values  The original row values that failed.
     */
    public function __construct(
        private int $row,
        private array $errors,
        private array $values = [],
    ) {}

    /** The 1-based row number in the spreadsheet. */
    public function row(): int
    {
        return $this->row;
    }

    /** @return array<string, list<string>> Validation errors keyed by attribute name. */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, mixed> The row data that failed validation. */
    public function values(): array
    {
        return $this->values;
    }
}
