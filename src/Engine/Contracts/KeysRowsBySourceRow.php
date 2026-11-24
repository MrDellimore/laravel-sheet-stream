<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Contracts;

/**
 * Marker for a SheetReader whose rows() keys are 1-based source row numbers (the row as
 * numbered in the spreadsheet; the line a record starts on for CSV), in ascending order.
 *
 * Imports then report real row numbers even when the reader leaves rows out (blank rows).
 * A reader without the marker has its rows numbered in the order they arrive.
 */
interface KeysRowsBySourceRow {}
