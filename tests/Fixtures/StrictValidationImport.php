<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithValidation;

class StrictValidationImport implements ToArray, WithHeadingRow, WithValidation
{
    public array $result = [];

    public function array(array $array): void
    {
        $this->result = $array;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
        ];
    }
}
