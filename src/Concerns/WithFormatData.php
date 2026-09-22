<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

/**
 * Receive cell values as the strings a spreadsheet application would display,
 * rendered through each cell's number format — the Laravel Excel
 * `WithFormatData` behaviour.
 *
 * Without this concern a date-styled cell arrives as its Excel serial number
 * (or a DateTimeImmutable when `dates.import_as` is `datetime`). With it, the
 * same cell arrives as e.g. "09/16/2026", and a currency-formatted number as
 * e.g. "$1,500.00".
 *
 * - **OpenSpout (XLSX/ODS)**: enables `SHOULD_FORMAT_DATES` on the native
 *   reader options, merging with any options supplied via `WithReaderOptions`.
 *   Only date/time-formatted cells are affected; plain numbers stay numeric.
 *
 * - **PhpSpreadsheet**: every numeric cell is rendered through its number
 *   format, exactly as Laravel Excel does. The workbook is read with styles
 *   loaded, which costs extra memory.
 *
 * - **CSV**: no effect — CSV cells are already strings.
 */
interface WithFormatData {}
