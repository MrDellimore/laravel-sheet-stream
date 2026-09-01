<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

/**
 * Enables sheet-level parallelism for queued imports.
 *
 * When an import implements both WithMultipleSheets and WithParallelSheets,
 * the queue system dispatches one job per sheet rather than processing
 * sheets sequentially in a single long-running job. Each sheet job opens
 * the file independently and processes only its assigned sheet.
 *
 * Combine with ShouldQueue. Has no effect on synchronous imports.
 */
interface WithParallelSheets {}
