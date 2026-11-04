<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Concerns;

interface WithSheetSetup
{
    /**
     * Configure the engine's native worksheet before any row is written to it:
     * frozen panes, gridlines, column widths, sheet protection and the like.
     *
     * The sheet is an `OpenSpout\Writer\Common\Entity\Sheet` on the openspout
     * driver and a `PhpOffice\PhpSpreadsheet\Worksheet\Worksheet` on the
     * phpspreadsheet driver. OpenSpout only honours column widths set before
     * the first row, which is why this runs before the heading row.
     */
    public function setUpSheet(object $sheet): void;
}
