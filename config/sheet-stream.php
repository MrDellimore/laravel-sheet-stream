<?php

return [
    // 'openspout' (streaming), 'phpspreadsheet' (full-featured), or 'rust' (streaming, needs the
    // rusty_sheet PHP extension; XLSX 25-175x faster, other formats fall back to OpenSpout)
    'default_reader' => 'openspout',
    // 'openspout' (streaming), 'phpspreadsheet' (.xls support), or 'rust' (streaming, needs the
    // rusty_sheet PHP extension; same files as OpenSpout, ~9x faster for XLSX)
    'default_writer' => 'openspout',
    'batch_size' => 1000,              // rows per DB insert batch (ToModel imports)
    'chunk_size' => 1000,              // rows per queued chunk job
    'temp_path' => null,               // null = sys_get_temp_dir()

    // How WithHeadingRow keys each data row.
    //   'slug' — Laravel Excel compatible (Str::slug with '_'): "Plan Type" => plan_type
    //   'none' — lowercase + trim only:                          "Plan Type" => "plan type"
    'heading_formatter' => 'slug',
    // What each row of the Collection passed to ToCollection::collection() is.
    //   'collection' — an Illuminate\Support\Collection, Laravel Excel compatible ($row->toArray(), $row->get('x'))
    //   'array'      — a plain array (the behaviour of 1.2 and earlier); for code using is_array($row) or array_*($row)
    // ToArray rows are always plain arrays.
    'collection_rows' => 'collection',
    // What a blank cell is when it reaches your import class.
    //   null — Laravel Excel compatible: every blank cell is null, wherever it sits in the row.
    //   ''   — leave what the reader yields: '' for a blank inside the row, null only for the
    //          trailing cells a short row is padded with (the behaviour before this option).
    // Only an exactly empty cell is blank; 0, '0', false and whitespace-only text are kept.
    'blank_cells' => null,
    // What a WithCalculatedFormulas import receives for a formula cell whose file holds no cached
    // result (files written by generators that never calculate). Rust reader only, and it needs
    // rusty_sheet 0.1.3 or newer for anything but 'null'; the other readers always give null.
    //   'null'    — null, the same as an empty cell (default)
    //   'throw'   — fail the import with an exception naming the cell
    //   'formula' — the formula text, e.g. "=B8*3"
    'formulas_without_cached_value' => 'null',
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
