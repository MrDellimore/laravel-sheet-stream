<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class StubModel extends Model
{
    protected $table = 'stubs';

    protected $guarded = [];

    public $timestamps = false;
}
