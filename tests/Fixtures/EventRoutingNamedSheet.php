<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\RegistersEventListeners;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Events\BeforeSheet;

/** Sheet 2 of the reference workbook: mapped by name, listens for BeforeSheet through the trait. */
class EventRoutingNamedSheet implements ToArray, WithEvents, WithHeadingRow
{
    use RegistersEventListeners;

    public int $rowCount = 0;

    public function array(array $rows): void
    {
        $this->rowCount += count($rows);
    }

    public function beforeSheet(BeforeSheet $event): void
    {
        EventRoutingLog::record('sheet2', $event);
    }
}
