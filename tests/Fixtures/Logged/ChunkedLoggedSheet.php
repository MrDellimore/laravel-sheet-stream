<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use MrDellimore\SheetStream\Concerns\WithChunkReading;

/** A sheet with its own chunk size, which wins over the parent import's. */
class ChunkedLoggedSheet extends LoggedSheet implements WithChunkReading {}
