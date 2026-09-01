<?php

namespace MrDellimore\SheetStream\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents one data row in the sheet_stream_staging table.
 *
 * @property int    $id
 * @property string $import_id
 * @property int    $sheet_index
 * @property string $sheet_name
 * @property int    $chunk_number
 * @property int    $row_number
 * @property string $row_data       JSON-encoded row array
 * @property string|null $processed_at
 * @property string|null $failed_at
 * @property string|null $error
 * @property string $created_at
 */
class StagedRow extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function getTable(): string
    {
        return config('sheet-stream.staging.table', 'sheet_stream_staging');
    }
}
