<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithRequiredHeadings
{
    /**
     * Keys must already be in the normalized form produced by
     * RowHelper::normalizeHeadings() with the configured headingFormatter.
     *
     * @return list<string>
     */
    public function requiredHeadings(): array;
}
