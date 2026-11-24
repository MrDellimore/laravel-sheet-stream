<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Imports;

use Illuminate\Database\Eloquent\Model;

/**
 * What ImportRunner is holding for the chunk of a sheet it is currently reading.
 *
 * @internal
 */
final class ChunkState
{
    /**
     * Rows waiting for collection() / array().
     *
     * @var array<int, mixed>
     */
    public array $rows = [];

    /**
     * Models waiting to be saved.
     *
     * @var list<Model>
     */
    public array $models = [];

    /** @var list<Failure> */
    public array $failures = [];

    /** 0-based, restarting on each sheet. */
    public int $number = 0;

    /** Rows counted toward this chunk so far. */
    public int $rowCount = 0;

    /** Row number of the chunk's first row (the chunk offset); null until the chunk has one. */
    public ?int $startRow = null;

    /** Move on to the next chunk. Models are flushed by then; rows and failures are the caller's call. */
    public function advance(): void
    {
        $this->number++;
        $this->rowCount = 0;
        $this->startRow = null;
    }

    /** Let go of what has been handed to the import, so a chunked sheet never holds more than a chunk. */
    public function release(): void
    {
        $this->rows = [];
        $this->failures = [];
    }
}
