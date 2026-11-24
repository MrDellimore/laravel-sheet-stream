<?php

declare(strict_types=1);

use MrDellimore\SheetStream\Engine\Contracts\Writer;
use MrDellimore\SheetStream\Engine\EngineFactory;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Engine\Rust\RustReader;
use MrDellimore\SheetStream\Engine\Rust\RustWriter;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Comment\Comment;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Entity\Hyperlink;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\Exception\InvalidSheetNameException;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Validation\CellReference;
use OpenSpout\Writer\XLSX\Validation\ErrorStyle;
use OpenSpout\Writer\XLSX\Validation\Rules\CustomValidationRule;
use OpenSpout\Writer\XLSX\Validation\Rules\DateValidationRule;
use OpenSpout\Writer\XLSX\Validation\Rules\ListValidationRule;
use OpenSpout\Writer\XLSX\Validation\Rules\TimeOfDay;
use OpenSpout\Writer\XLSX\Validation\Rules\TimeValidationRule;
use OpenSpout\Writer\XLSX\Validation\Rules\WholeNumberValidationRule;
use OpenSpout\Writer\XLSX\Validation\ValidationDisplay;
use OpenSpout\Writer\XLSX\Validation\ValidationOperator;

/*
 * The 'rust' writer must write the files the OpenSpout writer writes. Both are driven
 * through the Writer contract exactly as ExportRunner drives them; XLSX parts are compared
 * after normalising the two deliberate differences (no <dimension>, full-precision numbers),
 * CSV byte for byte. Run with the extension loaded.
 */

beforeEach(function () {
    if (! RustReader::isAvailable()) {
        $this->markTestSkipped('The rusty_sheet extension is not loaded.');
    }
});

/** Drive a writer through a scenario; returns the file path. */
function writeWith(Writer $writer, string $ext, callable $scenario): string
{
    $path = sys_get_temp_dir().'/'.uniqid('rust_writer_', true).'.'.$ext;
    $writer->openToFile($path);
    try {
        $scenario($writer);
    } finally {
        $writer->close();
    }

    return $path;
}

/**
 * workbook.xml, styles.xml, sheet XML and the sheets' hyperlink relationships, normalised
 * for the deliberate differences (OpenSpout also relates empty comment parts).
 */
function xlsxPartsOf(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path);
    $parts = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^xl/(comments\d+\.xml|drawings/vmlDrawing\d+\.vml)$#', $name)) {
            // OpenSpout writes empty comment parts for every sheet; the extension only
            // for sheets with comments.
            $xml = $zip->getFromIndex($i);
            if (str_contains($xml, '<comment ') || str_contains($xml, '<v:shape ')) {
                $parts[$name] = $xml;
            }
        } elseif (preg_match('#^xl/worksheets/_rels/sheet\d+\.xml\.rels$#', $name)) {
            $rels = preg_replace('#^  <Relationship Id="rId_comments[^\n]*\n#m', '', $zip->getFromIndex($i));
            if (str_contains($rels, '<Relationship ')) {
                $parts[$name] = $rels;
            }
        } elseif (preg_match('#^xl/(workbook\.xml|styles\.xml|worksheets/sheet\d+\.xml)$#', $name)) {
            $xml = preg_replace('#<dimension ref="[^"]*"/>|<legacyDrawing r:id="[^"]*"/>#', '', $zip->getFromIndex($i));
            $parts[$name] = preg_replace_callback('#<v>([^<]*)</v>#', fn ($m) => is_numeric($m[1]) ? '<v>'.sprintf('%.14G', (float) $m[1]).'</v>' : $m[0], $xml);
        }
    }
    $zip->close();
    ksort($parts);

    return $parts;
}

function writerScenarios(): array
{
    $heading = new Style(fontBold: true, fontSize: 14, backgroundColor: 'DDEEFF');
    $money = (new Style)->withFormat('#,##0.00')->withFontColor(Color::DARK_BLUE);
    $centered = (new Style)->withCellAlignment(CellAlignment::CENTER)->withShouldWrapText(false);
    $dateCol = (new Style)->withFormat('dd/mm/yyyy');

    return [
        'headings, row and column styles' => function (Writer $w) use ($heading, $money, $centered, $dateCol) {
            $w->addSheet('Report');
            $w->addRow(['Name', 'Amount', 'When', 'Note'], $heading);
            foreach (range(1, 50) as $i) {
                $w->addRow(
                    ["Person $i", $i / 3, new DateTimeImmutable("2025-01-$i" > '2025-01-31' ? '2025-02-01' : sprintf('2025-01-%02d 10:%02d:00', min($i, 28), $i)), $i % 4 ? "line\nbreak $i" : null],
                    $centered,
                    [1 => $money, 2 => $dateCol],
                );
            }
        },
        'values, formulas and native cells' => function (Writer $w) use ($money) {
            $w->addSheet(null);
            $w->addRow([1, -2.5, 1e20, true, false, null, '', '=SUM(A1:B1)', 'plain', "tab\tand \"quotes\" <&>"]);
            $w->addRow([new DateTime('2025-06-15'), new DateTimeImmutable('2025-06-15 14:30:00'), new DateInterval('PT6H')]);
            $w->addRow([new Cell\StringCell('=kept as text'), Cell::fromValue(42, $money), new Cell\DateTimeCell(new DateTimeImmutable('2024-02-29'))]);
        },
        'native rows and sheet setup' => function (Writer $w) use ($heading) {
            $w->addSheet('Setup');
            $sheet = $w->currentNativeSheet();
            $sheet->setSheetView((new SheetView)->withFreezeRow(2)->withFreezeColumn('B')->withShowGridLines(false));
            $sheet->setColumnWidth(25, 1);
            $sheet->setColumnWidth(12.5, 2, 3, 5);
            $w->addRow(['a', 'b'], $heading);
            $w->addNativeRow(new Row([Cell::fromValue('tall'), Cell::fromValue('yellow', (new Style)->withBackgroundColor('FFFF00'))], 40));
            $w->addNativeRow(new Row([2 => Cell::fromValue('sparse')]));
            $w->addSheet('Second');
            $w->addRow(['second sheet']);
            $w->addSheet(null);
        },
        'borders, hyperlinks and an autofilter' => function (Writer $w) use ($heading) {
            $thin = new BorderPart(BorderName::BOTTOM, 'BFBFBF', BorderWidth::THIN);
            $grid = (new Style)->withBorder(new Border(
                new BorderPart(BorderName::LEFT, 'BFBFBF', BorderWidth::THIN),
                new BorderPart(BorderName::RIGHT, 'BFBFBF', BorderWidth::THIN),
                new BorderPart(BorderName::TOP, 'BFBFBF', BorderWidth::THIN),
                $thin,
            ));
            // Same parts, other order: a separate border entry in OpenSpout too.
            $reordered = (new Style)->withBorder(new Border($thin, new BorderPart(BorderName::TOP, 'BFBFBF', BorderWidth::THIN)))->withFormat('0.00');
            $fancy = $heading->withBorder(new Border(
                new BorderPart(BorderName::TOP, Color::RED, BorderWidth::THICK, BorderStyle::DOUBLE),
                new BorderPart(BorderName::BOTTOM, width: BorderWidth::MEDIUM, style: BorderStyle::DASHED),
            ));
            $w->addSheet('Summary');
            $w->currentNativeSheet()->setAutoFilter(new AutoFilter(0, 2, 2, 4));
            $w->addRow(['Report'], $fancy);
            $w->addRow(['Name', 'Amount', 'Link'], $grid);
            $w->addNativeRow(new Row([
                Cell::fromValue('Ann', $grid),
                Cell::fromValue(12.5, $reordered),
                new Cell\StringCell('Details', null, null, Hyperlink::toSheet('Detail')),
            ]));
            $w->addNativeRow(new Row([
                Cell::fromValue(null, $grid),
                new Cell\StringCell('Site', $grid, null, Hyperlink::toUrl('https://example.com/?a=1&b=2')->withTooltip('Open "site"')),
                new Cell\StringCell('Cell', null, null, Hyperlink::toCell('B2')),
            ]));
            $w->addSheet('Detail');
            $w->addRow(['detail']);
        },
    ];
}

it('is built by the factory for the rust driver', function () {
    expect(EngineFactory::writer('rust', 'xlsx'))->toBeInstanceOf(RustWriter::class);
});

it('writes the XLSX the OpenSpout writer writes', function (string $name) {
    $scenario = writerScenarios()[$name];
    $expected = writeWith(new OpenSpoutWriter('xlsx'), 'xlsx', $scenario);
    $actual = writeWith(new RustWriter('xlsx'), 'xlsx', $scenario);

    try {
        expect(xlsxPartsOf($actual))->toBe(xlsxPartsOf($expected));
    } finally {
        @unlink($expected);
        @unlink($actual);
    }
})->with(array_keys(writerScenarios()));

it('writes comments, and merges and validation from XlsxOptions, like the OpenSpout writer', function () {
    $options = new XlsxOptions;
    $options->mergeCells(0, 1, 3, 1);
    $options->mergeCells(1, 2, 2, 3, 1);
    $display = new ValidationDisplay(errorStyle: ErrorStyle::Warning, errorTitle: 'Invalid selection', error: 'Please choose one of the listed options.');
    $options->addValidation(1, 2, 1, 20, new ListValidationRule(['Yes', 'No']), $display);
    $options->addValidation(2, 2, 2, 20, new ListValidationRule(new CellReference(0, 1, 0, 3, sheetName: 'Lists')));
    $options->addValidation(3, 2, 3, 20, new WholeNumberValidationRule(ValidationOperator::Between, 1, 10));
    $options->addValidation(4, 2, 4, 20, new DateValidationRule(ValidationOperator::GreaterThanOrEqual, new DateTimeImmutable('2020-01-01')));
    $options->addValidation(5, 2, 5, 20, new TimeValidationRule(ValidationOperator::LessThan, new TimeOfDay(14, 30, 15)));
    $options->addValidation(6, 2, 6, 20, new CustomValidationRule('LEN(G2)<5'), new ValidationDisplay(allowBlank: false));
    $options->addValidation(0, 1, 0, 5, new ListValidationRule(['a', 'b']), sheetIndex: 1);

    $scenario = function (Writer $w) {
        $note = new Comment(textRuns: [new TextRun('Reviewer: ', bold: true), new TextRun('checked & signed', fontSize: 12, fontColor: 'FF0000', fontName: 'Calibri', italic: true)]);
        $w->addSheet('Form');
        $w->addRow(['Title']);
        $w->addNativeRow(new Row([
            Cell::fromValue('Ann')->withComment(new Comment(textRuns: [new TextRun('plain')])),
            Cell::fromValue(5, comment: $note),
            new Cell\StringCell('Site', null, new Comment(visible: true, textRuns: [new TextRun('linked')]), Hyperlink::toUrl('https://example.com')),
            Cell::fromValue(null)->withComment(new Comment(fillColor: '#E2EFDA', textRuns: [new TextRun('empty cell')])),
        ]));
        $w->addSheet('Lists');
        $w->addNativeRow(new Row([Cell::fromValue('one')->withComment(new Comment(textRuns: [new TextRun('second sheet')]))]));
    };

    expect(new RustWriter('xlsx', $options)->usesFallback())->toBeFalse();
    $expected = writeWith(new OpenSpoutWriter('xlsx', $options), 'xlsx', $scenario);
    $actual = writeWith(new RustWriter('xlsx', $options), 'xlsx', $scenario);
    try {
        $parts = xlsxPartsOf($actual);
        expect($parts)->toHaveKeys(['xl/comments1.xml', 'xl/drawings/vmlDrawing2.vml'])
            ->and($parts['xl/worksheets/sheet1.xml'])->toContain('<dataValidations count="6">')
            ->and($parts)->toBe(xlsxPartsOf($expected));
    } finally {
        @unlink($expected);
        @unlink($actual);
    }
});

it('writes CSV byte for byte like the OpenSpout writer', function (string $ext, ?CsvOptions $options) {
    $scenario = function (Writer $w) {
        $w->addSheet(null);
        $w->addRow(['id', 'name', 'note', 'when', 'ok']);
        foreach (range(1, 100) as $i) {
            $w->addRow([$i, "name $i", $i % 3 ? 'has, comma' : "say \"hi\"\n", new DateTimeImmutable('2025-06-15 10:00:00'), $i / 7]);
        }
    };
    $expected = writeWith(new OpenSpoutWriter($ext, $options), $ext, $scenario);
    $actual = writeWith(new RustWriter($ext, $options), $ext, $scenario);

    try {
        expect(file_get_contents($actual))->toBe(file_get_contents($expected));
    } finally {
        @unlink($expected);
        @unlink($actual);
    }
})->with([
    'csv defaults' => ['csv', null],
    'tsv defaults (comma, as the OpenSpout driver)' => ['tsv', null],
    'semicolon, no BOM' => ['csv', new CsvOptions(FIELD_DELIMITER: ';', SHOULD_ADD_BOM: false)],
]);

it('falls back to OpenSpout for ods and for custom XlsxOptions', function () {
    expect(new RustWriter('ods')->usesFallback())->toBeTrue()
        ->and(new RustWriter('xlsx', new XlsxOptions(SHOULD_USE_INLINE_STRINGS: false))->usesFallback())->toBeTrue()
        ->and(new RustWriter('xlsx', new XlsxOptions)->usesFallback())->toBeFalse()
        ->and(new RustWriter('csv', new CsvOptions(FIELD_DELIMITER: ';'))->usesFallback())->toBeFalse()
        ->and(fn () => new RustWriter('xls'))->toThrow(UnsupportedByEngine::class);
});

it('rejects what it cannot write instead of dropping it', function (callable $scenario, string $message) {
    expect(fn () => writeWith(new RustWriter('xlsx'), 'xlsx', $scenario))->toThrow(UnsupportedByEngine::class, $message);
})->with([
    'hidden rows' => [fn (Writer $w) => $w->addNativeRow(new Row([Cell::fromValue('x')], hidden: true)), 'hidden'],
    'zoom in setup' => [function (Writer $w) {
        $w->addSheet(null);
        $w->currentNativeSheet()->setSheetView((new SheetView)->withZoomScale(150));
        $w->addRow(['x']);
    }, 'sheet view settings'],
]);

it('reports invalid sheet names with OpenSpout\'s exception', function () {
    expect(fn () => writeWith(new RustWriter('xlsx'), 'xlsx', fn (Writer $w) => $w->addSheet('bad/name')))
        ->toThrow(InvalidSheetNameException::class, 'should not contain');
});

it('refuses a second CSV sheet like the OpenSpout writer', function () {
    expect(fn () => writeWith(new RustWriter('csv'), 'csv', function (Writer $w) {
        $w->addSheet(null);
        $w->addSheet('second');
    }))->toThrow(UnsupportedByEngine::class, 'does not support multiple sheets');
});
