<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use MrDellimore\SheetStream\Concerns\RegistersEventListeners;
use MrDellimore\SheetStream\Concerns\ToArray;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithHeadingRow;
use MrDellimore\SheetStream\Events\AfterImport;
use MrDellimore\SheetStream\Events\BeforeImport;

class RegistersEventListenersImport implements ToArray, WithEvents, WithHeadingRow
{
    use RegistersEventListeners;

    public array $result = [];

    public array $firedEvents = [];

    public function array(array $array): void
    {
        $this->result = $array;
    }

    public function beforeImport(BeforeImport $event): void
    {
        $this->firedEvents[] = BeforeImport::class;
    }

    public function afterImport(AfterImport $event): void
    {
        $this->firedEvents[] = AfterImport::class;
    }
}
