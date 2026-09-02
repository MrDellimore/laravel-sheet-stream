<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

interface FromQuery
{
    public function query(): EloquentBuilder|QueryBuilder|Relation;
}
