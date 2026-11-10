<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use MrDellimore\SheetStream\Concerns\FromCollection;
use MrDellimore\SheetStream\Concerns\FromGenerator;
use MrDellimore\SheetStream\Concerns\WithEvents;
use MrDellimore\SheetStream\Concerns\WithMapping;
use MrDellimore\SheetStream\Concerns\WithMultipleSheets;
use MrDellimore\SheetStream\Concerns\WithSheetSetup;
use MrDellimore\SheetStream\Concerns\WithTitle;
use MrDellimore\SheetStream\Engine\PhpSpreadsheet\PhpSpreadsheetWriter;
use MrDellimore\SheetStream\Events\AfterWriting;
use MrDellimore\SheetStream\Exports\ExportRunner;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

it('writes OpenSpout cells returned from map() as they are, keeping each cell\'s own style', function () {
    $export = new class implements FromCollection, WithMapping
    {
        public function collection(): Collection
        {
            return collect([['name' => '=1+1', 'flag' => true]]);
        }

        public function map(mixed $row): array
        {
            return [
                new StringCell($row['name']),
                StringCell::fromValue('flagged', new Style()->withBackgroundColor('FFFF00')),
            ];
        }
    };

    app('sheet-stream')->store($export, 'exports/cells.xlsx');
    $sheet = IOFactory::load(Storage::path('exports/cells.xlsx'))->getActiveSheet();

    expect((string) $sheet->getCell('A1')->getValue())->toBe('=1+1')
        ->and($sheet->getCell('A1')->getDataType())->not->toBe(DataType::TYPE_FORMULA)
        ->and($sheet->getStyle('B1')->getFill()->getStartColor()->getRGB())->toBe('FFFF00')
        ->and($sheet->getStyle('A1')->getFill()->getStartColor()->getRGB())->not->toBe('FFFF00');

    Storage::delete('exports/cells.xlsx');
});

it('writes OpenSpout rows yielded by a generator as they are, keeping the row height', function () {
    $export = new class implements FromGenerator
    {
        public function generator(): Generator
        {
            yield Row::fromValues(['Title'])->withHeight(42.5);
            yield ['plain', 'array'];
        }
    };

    app('sheet-stream')->store($export, 'exports/rows.xlsx');
    $sheet = IOFactory::load(Storage::path('exports/rows.xlsx'))->getActiveSheet();

    expect((string) $sheet->getCell('A1')->getValue())->toBe('Title')
        ->and($sheet->getRowDimension(1)->getRowHeight())->toEqual(42.5)
        ->and((string) $sheet->getCell('B2')->getValue())->toBe('array');

    Storage::delete('exports/rows.xlsx');
});

it('lets each sheet set up its native OpenSpout sheet before any row is written', function () {
    $sheetWithSetup = fn (string $title, int $freezeRow, float $width) => new readonly class($title, $freezeRow, $width) implements FromCollection, WithSheetSetup, WithTitle
    {
        public function __construct(private string $name, private int $freezeRow, private float $width) {}

        public function collection(): Collection
        {
            return collect([['A', 'B'], ['C', 'D']]);
        }

        public function title(): string
        {
            return $this->name;
        }

        public function setUpSheet(object $sheet): void
        {
            assert($sheet instanceof Sheet);

            $sheet->setSheetView(new SheetView()->withFreezeRow($this->freezeRow)->withShowGridLines(false));
            $sheet->setColumnWidth($this->width, 1);
        }
    };

    $export = new readonly class([$sheetWithSetup('First', 2, 30.0), $sheetWithSetup('Second', 3, 12.5)]) implements WithMultipleSheets
    {
        public function __construct(private array $tabs) {}

        public function sheets(): array
        {
            return $this->tabs;
        }
    };

    app('sheet-stream')->store($export, 'exports/setup.xlsx');
    $workbook = IOFactory::load(Storage::path('exports/setup.xlsx'));

    expect($workbook->getSheetByName('First')->getFreezePane())->toBe('A2')
        ->and($workbook->getSheetByName('First')->getColumnDimension('A')->getWidth())->toEqual(30.0)
        ->and($workbook->getSheetByName('First')->getShowGridlines())->toBeFalse()
        ->and($workbook->getSheetByName('Second')->getFreezePane())->toBe('A3')
        ->and($workbook->getSheetByName('Second')->getColumnDimension('A')->getWidth())->toEqual(12.5);

    Storage::delete('exports/setup.xlsx');
});

it('hands sheet setup the PhpSpreadsheet worksheet on the phpspreadsheet engine', function () {
    $export = new class implements FromCollection, WithSheetSetup
    {
        public ?object $sheet = null;

        public function collection(): Collection
        {
            return collect([['A']]);
        }

        public function setUpSheet(object $sheet): void
        {
            $this->sheet = $sheet;
        }
    };

    $writer = new PhpSpreadsheetWriter('xlsx');
    $writer->openToFile(tempnam(sys_get_temp_dir(), 'ss_'));
    (new ExportRunner)->run($export, $writer);

    expect($export->sheet)->toBeInstanceOf(Worksheet::class);
});

it('fires AfterWriting with the closed file before it is stored, so the file can be post-processed', function () {
    $export = new class implements FromCollection, WithEvents
    {
        public ?string $path = null;

        public bool $wasReadable = false;

        public function collection(): Collection
        {
            return collect([['A']]);
        }

        public function registerEvents(): array
        {
            return [
                AfterWriting::class => function (AfterWriting $event): void {
                    $this->path = $event->path;
                    $this->wasReadable = (string) IOFactory::load($event->path)->getActiveSheet()->getCell('A1')->getValue() === 'A';
                    file_put_contents($event->path, 'patched');
                },
            ];
        }
    };

    app('sheet-stream')->store($export, 'exports/after-writing.xlsx');

    expect($export->wasReadable)->toBeTrue()
        ->and(Storage::get('exports/after-writing.xlsx'))->toBe('patched')
        ->and(file_exists($export->path))->toBeFalse();

    Storage::delete('exports/after-writing.xlsx');
});

it('removes the temporary file when an AfterWriting listener fails', function () {
    $export = new class implements FromCollection, WithEvents
    {
        public ?string $path = null;

        public function collection(): Collection
        {
            return collect([['A']]);
        }

        public function registerEvents(): array
        {
            return [
                AfterWriting::class => function (AfterWriting $event): void {
                    $this->path = $event->path;

                    throw new RuntimeException('patch failed');
                },
            ];
        }
    };

    expect(fn () => app('sheet-stream')->store($export, 'exports/failed.xlsx'))->toThrow(RuntimeException::class, 'patch failed')
        ->and(file_exists($export->path))->toBeFalse()
        ->and(Storage::exists('exports/failed.xlsx'))->toBeFalse();
});
