<?php

return [
    'default_reader' => 'openspout',   // 'openspout' (streaming) or 'phpspreadsheet' (full-featured)
    'default_writer' => 'openspout',   // 'openspout' (streaming) or 'phpspreadsheet' (.xls support)
    'batch_size' => 1000,              // rows per DB insert batch (ToModel imports)
    'chunk_size' => 1000,              // rows per queued chunk job
    'temp_path' => null,               // null = sys_get_temp_dir()

    // How WithHeadingRow keys each data row.
    //   'slug' — Laravel Excel compatible (Str::slug with '_'): "Plan Type" => plan_type
    //   'none' — lowercase + trim only:                          "Plan Type" => "plan type"
    'heading_formatter' => 'slug',
    // How date-styled cells reach your import class.
    //   'serial'   — Excel serial number (int/float), Laravel Excel compatible. Convert with
    //                PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject() or your own
    //                helper. Implement WithFormatData to receive the display string instead.
    //   'datetime' — DateTimeImmutable objects (converted to dates.timezone when set).
    'dates' => [
        'import_as' => 'serial',
        'timezone' => null,
        'format' => 'yyyy-mm-dd',              // Excel number format for date-only exports
        'datetime_format' => 'yyyy-mm-dd hh:mm:ss', // Excel number format for date+time exports
    ],

    // Staging-table pattern (UsesStagingTable concern)
    'staging' => [
        'driver' => env('SHEET_STREAM_STAGING_DRIVER', 'file'), // 'file' (fast, no migration) or 'database' (audit trail, retry safety)
        'table' => 'sheet_stream_staging',  // table name (database driver)
        'path' => null,                      // base path (file driver); null = temp_path/sheet_stream_staging
        'insert_batch_size' => 500,          // rows per bulk INSERT in the producer job
    ],

    // CSV pre-conversion (WithCsvPreConversion concern)
    'csv_converter' => [
        'binary' => env('SHEET_STREAM_CSV_CONVERTER'),  // null = auto-detect (ssconvert, xlsx2csv)
        'timeout' => 3600,                               // max seconds for the conversion process
    ],
];
