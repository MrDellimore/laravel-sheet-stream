<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Lives on the non-default "secondary" connection and has no updated_at column. */
class BulkSecondary extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'secondary';

    protected $table = 'bulk_secondary';

    protected $guarded = [];
}
