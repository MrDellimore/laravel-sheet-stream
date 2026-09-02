<?php

namespace MrDellimore\SheetStream\Tests\Fixtures;

use Illuminate\Contracts\View\View;
use MrDellimore\SheetStream\Concerns\FromView;
use MrDellimore\SheetStream\Concerns\WithTitle;

class ViewExport implements FromView, WithTitle
{
    public function __construct(
        private array $rows = [],
    ) {}

    public function view(): View
    {
        return view('sheet-stream-tests::export-table', ['rows' => $this->rows]);
    }

    public function title(): string
    {
        return 'Summary';
    }
}
