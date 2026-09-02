<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

/**
 * Enables formula evaluation during import.
 *
 * The behavior depends on the engine and import path:
 *
 * - **OpenSpout (XLSX/ODS)**: Formula cells return their cached computed
 *   value (stored in the `<v>` element) instead of the formula string.
 *   This is the value Excel/Google Sheets calculated when the file was
 *   last saved.
 *
 * - **PhpSpreadsheet**: Formulas are evaluated live by the calculation
 *   engine rather than reading cached values.
 *
 * - **CSV pre-conversion** (WithCsvPreConversion + ssconvert): No effect —
 *   ssconvert already evaluates formulas during the XLSX-to-CSV conversion.
 *
 * Without this concern, OpenSpout returns the raw formula string
 * (e.g. "=SUM(A1:A10)") for formula cells.
 */
interface WithCalculatedFormulas {}
