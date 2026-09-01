<?php

namespace MrDellimore\SheetStream\Concerns;

use OpenSpout\Common\Entity\Style\Style;

interface WithHeadingStyle
{
    public function headingStyle(): Style;
}
