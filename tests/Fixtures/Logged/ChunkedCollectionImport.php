<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use MrDellimore\SheetStream\Concerns\WithChunkReading;

/** LoggedCollectionImport read in chunks of chunkSize (constructor argument, default 2). */
class ChunkedCollectionImport extends LoggedCollectionImport implements WithChunkReading {}
