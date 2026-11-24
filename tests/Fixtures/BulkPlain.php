<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** No timestamps; `name` is unique so a duplicate makes the INSERT fail. */
class BulkPlain extends Model
{
    public $timestamps = false;

    protected $table = 'bulk_plain';

    protected $guarded = [];
}
