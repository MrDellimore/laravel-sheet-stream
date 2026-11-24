<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\ShouldQueue;
use MrDellimore\SheetStream\Concerns\WithParallelSheets;

/** The event-routing parent, fanned out as one queued job per sheet. */
class EventRoutingParallelImport extends EventRoutingMultiSheetImport implements ShouldQueue, WithParallelSheets {}
