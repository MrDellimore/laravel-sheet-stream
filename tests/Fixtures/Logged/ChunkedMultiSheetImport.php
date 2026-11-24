<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures\Logged;

use MrDellimore\SheetStream\Concerns\WithChunkReading;

/** A LoggedMultiSheetImport whose chunkSize() applies to every sheet without its own. */
class ChunkedMultiSheetImport extends LoggedMultiSheetImport implements WithChunkReading {}
