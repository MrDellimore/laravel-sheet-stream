<?php

namespace MrDellimore\SheetStream\Concerns;

interface WithMapping
{
    public function map(mixed $row): array;
}
