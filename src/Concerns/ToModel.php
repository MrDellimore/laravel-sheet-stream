<?php

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Database\Eloquent\Model;

interface ToModel
{
    public function model(array $row): ?Model;
}
