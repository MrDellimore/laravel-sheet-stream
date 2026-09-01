<?php

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Database\Eloquent\Builder;

interface FromQuery
{
    public function query(): Builder;
}
