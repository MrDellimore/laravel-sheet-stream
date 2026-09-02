<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

/**
 * Compatibility shim for Laravel Excel migrations.
 *
 * laravel-sheet-stream already uses strict value handling by default —
 * 0, false, and '' are never coerced to null. This marker interface
 * is accepted so existing exporters don't break, but has no behavioral effect.
 */
interface WithStrictNullComparison {}
