<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use MrDellimore\SheetStream\Concerns\WithChunkReading;

/** LoggedArrayImport read in chunks of chunkSize (constructor argument, default 2). */
class ChunkedArrayImport extends LoggedArrayImport implements WithChunkReading {}
