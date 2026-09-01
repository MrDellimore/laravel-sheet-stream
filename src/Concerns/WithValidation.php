<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithValidation
{
    public function rules(): array;
}
