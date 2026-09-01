<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use OpenSpout\Common\Entity\Style\Style;

interface WithDefaultRowStyle
{
    public function defaultRowStyle(): Style;
}
