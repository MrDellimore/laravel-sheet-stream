<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

use OpenSpout\Common\Entity\Style\Style;

interface WithHeadingStyle
{
    public function headingStyle(): Style;
}
