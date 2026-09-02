<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class StubModel extends Model
{
    protected $table = 'stubs';

    protected $guarded = [];

    public $timestamps = false;
}
