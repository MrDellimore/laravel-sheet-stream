<?php

namespace MrDellimore\SheetStream\Concerns;

use OpenSpout\Common\Entity\Style\Style;

interface WithColumnStyles
{
    /**
     * Return per-column styles, keyed by column index (0-based).
     *
     * @return array<int, Style>
     */
    public function columnStyles(): array;
}
