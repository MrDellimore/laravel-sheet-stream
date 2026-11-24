<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\WithParallelSheets;

/** The WithEvents-less parent, fanned out as one queued job per sheet. */
class EventRoutingSilentParallelImport extends EventRoutingSilentImport implements ShouldQueue, WithParallelSheets {}
