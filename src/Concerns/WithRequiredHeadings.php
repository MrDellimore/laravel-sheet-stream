<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithRequiredHeadings
{
    /**
     * Return the heading keys that must be present after normalization.
     * For a single-sheet import: a flat list<string>.
     * The strings must already be in the normalized form produced by
     * RowHelper::normalizeHeadings() with the configured headingFormatter.
     *
     * @return list<string>
     */
    public function requiredHeadings(): array;
}
