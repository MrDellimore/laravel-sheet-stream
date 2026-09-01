<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('sheet-stream.staging.table', 'sheet_stream_staging');

        Schema::create($table, function (Blueprint $table) {
            $table->id();

            // Groups all rows from a single import run.
            $table->char('import_id', 36);

            // Which sheet this row came from.
            $table->unsignedSmallInteger('sheet_index')->default(0);
            $table->string('sheet_name')->default('');

            // Pre-computed chunk number (floor((row_number - 1) / chunk_size)).
            // Each QueuedStagingChunkJob owns all rows with the same chunk_number.
            $table->unsignedInteger('chunk_number');

            // 1-based data row number within the sheet (heading row excluded).
            $table->unsignedInteger('row_number');

            // JSON-encoded row, with heading-row keys already applied if applicable.
            // LONGTEXT for maximum compatibility (MySQL 5.7, SQLite, PostgreSQL, SQL Server).
            $table->longText('row_data');

            // Processing state.
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['import_id', 'sheet_index', 'chunk_number'], 'sss_import_sheet_chunk');
        });
    }

    public function down(): void
    {
        $table = config('sheet-stream.staging.table', 'sheet_stream_staging');
        Schema::dropIfExists($table);
    }
};
