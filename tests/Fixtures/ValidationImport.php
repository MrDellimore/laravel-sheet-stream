<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Support\Collection;
use MrDellimore\SheetStream\Concerns\SkipsOnFailure;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Concerns\WithValidation;
use MrDellimore\SheetStream\Imports\Failure;

class ValidationImport implements SkipsOnFailure, ToArray, WithHeadingRow, WithValidation
{
    public array $result = [];

    /** @var Collection<int, Failure> */
    public Collection $failures;

    public function __construct()
    {
        $this->failures = new Collection;
    }

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

    /** @param Collection<int, Failure> $failures */
    public function onFailure(Collection $failures): void
    {
        $this->failures = $failures;
    }
}
