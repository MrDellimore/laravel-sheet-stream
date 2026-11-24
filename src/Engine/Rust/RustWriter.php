<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Engine\Rust;

use MrDellimore\SheetStream\Engine\Contracts\ExposesNativeSheet;
use MrDellimore\SheetStream\Engine\Contracts\Writer;
use MrDellimore\SheetStream\Engine\Contracts\WritesNativeRows;
use MrDellimore\SheetStream\Engine\OpenSpout\OpenSpoutWriter;
use MrDellimore\SheetStream\Exceptions\UnsupportedByEngine;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Comment\Comment;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Helper\StringHelper;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\Common\Entity\Sheet;
use OpenSpout\Writer\Common\Entity\SheetViewInterface;
use OpenSpout\Writer\Common\Manager\SheetManager;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\Exception\InvalidSheetNameException;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\MergeCell;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Options\SheetProtection;
use OpenSpout\Writer\XLSX\Validation\AbstractOperatorValidationRule;
use OpenSpout\Writer\XLSX\Validation\CellReference;
use OpenSpout\Writer\XLSX\Validation\Rules\CustomValidationRule;
use OpenSpout\Writer\XLSX\Validation\Rules\ListValidationRule;
use OpenSpout\Writer\XLSX\Validation\Rules\TimeOfDay;
use OpenSpout\Writer\XLSX\Validation\ValidationRule;
use RustySheet\UnsupportedFormatException;
use RustySheet\Writer as NativeWriter;
use RustySheet\WriterException;

/**
 * Writer driver backed by the rusty_sheet PHP extension. It writes the same XLSX and CSV
 * files as the OpenSpout driver (same styles, cell XML and CSV bytes, as of OpenSpout 5.15),
 * about 9× faster for XLSX and 2–3× for CSV, with flat memory.
 *
 * The public API is unchanged: styles are OpenSpout Style objects, native rows are
 * OpenSpout Rows/Cells, and WithSheetSetup receives a real OpenSpout Sheet, whose column
 * widths, frozen panes and gridlines are copied to the file before the next row.
 *
 * Falls back to the OpenSpout writer, per file, for .ods (and .xls still throws), and for
 * XlsxOptions other than the defaults (merged cells and data validation in XlsxOptions are
 * written by the extension). Cell borders, hyperlinks (with tooltips), comments, merged cells,
 * data validation and a WithSheetSetup autofilter are written like OpenSpout writes them.
 * Throws UnsupportedByEngine for what neither path can honour here: images, rich text, error
 * cells, hidden or outlined rows, and sheet features beyond widths/freeze/gridlines/autofilter
 * in WithSheetSetup.
 *
 * Deliberate differences in XLSX output: no <dimension> element (rows stream straight into
 * the file), and numbers at full precision (OpenSpout keeps 14 significant digits).
 */
final class RustWriter implements ExposesNativeSheet, Writer, WritesNativeRows
{
    private ?OpenSpoutWriter $fallback = null;

    private ?NativeWriter $native = null;

    private readonly string $format;

    /** @var array<string, mixed> */
    private readonly array $createOptions;

    /** @var array<int, array{Style, int}> spl_object_id => [style (kept alive so the id isn't reused), extension style id] */
    private array $styles = [];

    private int $sheetCount = 0;

    private string $sheetName = 'Sheet1';

    /** The OpenSpout Sheet handed to WithSheetSetup, applied before the sheet's first row. */
    private ?Sheet $setupSheet = null;

    /** @var list<MergeCell> merged cells from XlsxOptions, applied per sheet */
    private array $merges = [];

    /** @var list<ValidationRule> data validation from XlsxOptions, applied per sheet */
    private array $validations = [];

    /** The last sheet index whose merges and validation have been applied. */
    private int $appliedSheet = -1;

    public function __construct(
        string $extension = 'xlsx',
        ?object $nativeOptions = null,
        string $dateFormat = 'yyyy-mm-dd',
        string $dateTimeFormat = 'yyyy-mm-dd hh:mm:ss',
    ) {
        $extension = strtolower($extension);
        $this->format = $extension === 'xlsx' ? 'xlsx' : 'csv';

        if (! in_array($extension, ['xlsx', 'csv', 'tsv'], true) || $this->needsOpenSpout($extension, $nativeOptions)) {
            // .ods is written by OpenSpout; .xls throws UnsupportedByEngine there, as before.
            $this->fallback = new OpenSpoutWriter($extension, $nativeOptions, $dateFormat, $dateTimeFormat);
        } elseif ($nativeOptions instanceof XlsxOptions) {
            $this->merges = $nativeOptions->getMergeCells();
            $this->validations = $nativeOptions->getValidationRules();
        }

        $this->createOptions = [
            'format' => $this->format,
            'date_format' => $dateFormat,
            'datetime_format' => $dateTimeFormat,
            // Like the OpenSpout driver, .tsv without CsvOptions is comma-separated.
            'csv' => $nativeOptions instanceof CsvOptions
                ? [
                    'delimiter' => $nativeOptions->FIELD_DELIMITER,
                    'enclosure' => $nativeOptions->FIELD_ENCLOSURE,
                    'bom' => $nativeOptions->SHOULD_ADD_BOM,
                    'eol' => $nativeOptions->EOL,
                ]
                : ['delimiter' => ','],
        ];
    }

    /** Whether this file is written by OpenSpout rather than the extension. */
    public function usesFallback(): bool
    {
        return $this->fallback instanceof OpenSpoutWriter;
    }

    public function openToFile(string $path): void
    {
        if ($this->fallback instanceof OpenSpoutWriter) {
            $this->fallback->openToFile($path);

            return;
        }

        try {
            $this->native = NativeWriter::create($path, $this->createOptions);
        } catch (UnsupportedFormatException $e) {
            throw new UnsupportedByEngine($e->getMessage(), $e->getCode(), previous: $e);
        }
        $this->applyCellRules();
    }

    public function addSheet(?string $name): void
    {
        if ($this->fallback instanceof OpenSpoutWriter) {
            $this->fallback->addSheet($name);

            return;
        }

        $this->applySheetSetup();
        try {
            $this->native()->addSheet($name);
        } catch (UnsupportedFormatException $e) {
            throw new UnsupportedByEngine('The CSV format does not support multiple sheets.', $e->getCode(), previous: $e);
        } catch (WriterException $e) {
            throw str_contains($e->getMessage(), "The sheet's name")
                ? new InvalidSheetNameException($e->getMessage(), previous: $e)
                : $e;
        }
        $this->sheetCount++;
        $this->sheetName = $name ?? 'Sheet'.$this->sheetCount;
        $this->applyCellRules();
    }

    public function addRow(array $cells, ?object $rowStyle = null, array $columnStyles = []): void
    {
        if ($this->fallback instanceof OpenSpoutWriter) {
            $this->fallback->addRow($cells, $rowStyle, $columnStyles);

            return;
        }

        $this->applySheetSetup();
        $values = array_map(fn (mixed $v) => $v instanceof Cell ? $this->nativeCell($v) : $v, array_values($cells));
        $columnIds = [];
        foreach ($columnStyles as $index => $style) {
            if ($style instanceof Style) {
                $columnIds[$index] = $this->styleId($style);
            }
        }
        $this->native()->addRow($values, $rowStyle instanceof Style ? $this->styleId($rowStyle) : null, $columnIds);
    }

    public function acceptsNativeRow(mixed $row): bool
    {
        return $row instanceof Row;
    }

    public function addNativeRow(object $row): void
    {
        if ($this->fallback instanceof OpenSpoutWriter) {
            $this->fallback->addNativeRow($row);

            return;
        }
        if (! $row instanceof Row) {
            throw new UnsupportedByEngine('The rust engine can only write OpenSpout Row objects as native rows, got '.$row::class.'.');
        }
        if ($row->hidden || $row->collapsed || $row->outlineLevel !== Row::DEFAULT_OUTLINE_LEVEL) {
            throw $this->unsupported('hidden, collapsed or outlined rows');
        }

        $this->applySheetSetup();
        // Row cells are keyed by column index; fill any gap with an empty value.
        $values = [];
        if ($row->cells !== []) {
            $max = max(array_keys($row->cells));
            for ($i = 0; $i <= $max; $i++) {
                $values[] = isset($row->cells[$i]) ? $this->nativeCell($row->cells[$i]) : null;
            }
        }
        $this->native()->addRow($values, null, [], $row->height > 0 ? $row->height : null);
    }

    /**
     * A real OpenSpout Sheet for WithSheetSetup. Its column widths, frozen panes and
     * gridlines are applied before the sheet's first row.
     */
    public function currentNativeSheet(): Sheet
    {
        if ($this->fallback instanceof OpenSpoutWriter) {
            return $this->fallback->currentNativeSheet();
        }
        if ($this->format === 'csv') {
            throw new UnsupportedByEngine('The CSV format has no sheet to configure.');
        }

        if (! $this->setupSheet instanceof Sheet) {
            // A private SheetManager per sheet: its name checks only see this one sheet.
            $this->setupSheet = new Sheet(0, uniqid('rust_', true), new SheetManager(StringHelper::factory()));
            $this->setupSheet->setName($this->sheetName);
        }

        return $this->setupSheet;
    }

    public function loadHtml(string $html): void
    {
        throw new UnsupportedByEngine(
            'FromView requires the phpspreadsheet engine driver. '
            .'Install phpoffice/phpspreadsheet and set the driver to "phpspreadsheet", '
            .'or let SheetStreamManager auto-select it.'
        );
    }

    public function close(): void
    {
        if ($this->fallback instanceof OpenSpoutWriter) {
            $this->fallback->close();

            return;
        }
        if (! $this->native instanceof NativeWriter) {
            return;
        }

        try {
            $this->applySheetSetup();
        } finally {
            $this->native->close();
        }
    }

    private function native(): NativeWriter
    {
        return $this->native ?? throw new \LogicException('Call openToFile() first.');
    }

    /**
     * Non-default XlsxOptions (fallback style, column widths, page setup, shared strings, …)
     * are written by OpenSpout. Merged cells and data validation in XlsxOptions are not a
     * reason to: the extension writes them. CsvOptions are fully supported.
     */
    private function needsOpenSpout(string $extension, ?object $nativeOptions): bool
    {
        return $extension === 'xlsx'
            && $nativeOptions instanceof XlsxOptions
            && self::withoutCellRules($nativeOptions) != self::withoutCellRules(new XlsxOptions);
    }

    /**
     * The options' properties without the merge and validation containers.
     *
     * @return array<string, mixed>
     */
    private static function withoutCellRules(XlsxOptions $options): array
    {
        return array_filter(
            (array) $options,
            static fn (string $key): bool => ! str_ends_with($key, "\0MERGE_CELLS") && ! str_ends_with($key, "\0VALIDATION_CELLS"),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Merged cells and data validation from XlsxOptions for the sheet that just became
     * current. The first addSheet() names the first sheet; later ones start a new one.
     */
    private function applyCellRules(): void
    {
        $index = max(0, $this->sheetCount - 1);
        if ($this->format !== 'xlsx' || $index <= $this->appliedSheet) {
            return;
        }
        $this->appliedSheet = $index;
        $native = $this->native();
        foreach ($this->merges as $merge) {
            if ($merge->sheetIndex === $index) {
                $native->mergeCells($this->rangeOf($merge->topLeftColumn, $merge->topLeftRow, $merge->bottomRightColumn, $merge->bottomRightRow));
            }
        }
        foreach ($this->validations as $validation) {
            if ($validation->sheetIndex === $index) {
                $native->addValidation(
                    $this->rangeOf($validation->topLeftColumn, $validation->topLeftRow, $validation->bottomRightColumn, $validation->bottomRightRow),
                    $this->validationSpec($validation),
                );
            }
        }
    }

    private function rangeOf(int $fromColumn, int $fromRow, int $toColumn, int $toRow): string
    {
        return $this->columnLetters($fromColumn).$fromRow.':'.$this->columnLetters($toColumn).$toRow;
    }

    /**
     * The extension's rule array for an OpenSpout ValidationRule.
     *
     * @return array<string, mixed>
     */
    private function validationSpec(ValidationRule $validation): array
    {
        $rule = $validation->rule;
        $display = $validation->validation_display;
        $value = static fn (mixed $v): mixed => match (true) {
            $v instanceof CellReference => $v->serialize(),
            $v instanceof TimeOfDay => $v->toDayFraction(),
            default => $v,
        };
        $spec = ['type' => $rule->getValidationRuleType()->value];
        if ($rule instanceof ListValidationRule) {
            $spec += $rule->value instanceof CellReference ? ['source' => $rule->value->serialize()] : ['values' => $rule->value];
        } elseif ($rule instanceof CustomValidationRule) {
            $spec['formula'] = $rule->formula;
        } elseif ($rule instanceof AbstractOperatorValidationRule) {
            $spec['operator'] = $rule->operator->value;
            $spec['value1'] = $value($rule->value1);
            if ($rule->value2 !== null) {
                $spec['value2'] = $value($rule->value2);
            }
        }

        return $spec + array_filter([
            'allowBlank' => $display->allowBlank,
            'showInputMessage' => $display->showInputMessage,
            'showErrorMessage' => $display->showErrorMessage,
            'errorStyle' => $display->errorStyle->value,
            'promptTitle' => $display->promptTitle,
            'prompt' => $display->prompt,
            'errorTitle' => $display->errorTitle,
            'error' => $display->error,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * The extension's comment spec for an OpenSpout Comment.
     *
     * @return array<string, mixed>
     */
    private function commentSpec(Comment $comment): array
    {
        return [
            'runs' => array_map(static fn (TextRun $run): array => [
                'text' => $run->text,
                'bold' => $run->bold,
                'italic' => $run->italic,
                'size' => $run->fontSize,
                'color' => $run->fontColor,
                'font' => $run->fontName,
            ], $comment->textRuns),
            'visible' => $comment->visible,
            'width' => $comment->width,
            'height' => $comment->height,
            'fill' => $comment->fillColor,
            'marginLeft' => $comment->marginLeft,
            'marginTop' => $comment->marginTop,
        ];
    }

    private function nativeCell(Cell $cell): mixed
    {
        $style = $cell->style instanceof Style ? $this->styleId($cell->style) : null;
        $comment = $cell->comment instanceof Comment ? $this->commentSpec($cell->comment) : null;

        // Only a StringCell carries a hyperlink in OpenSpout.
        if ($cell instanceof Cell\StringCell && $cell->hyperlinkUrl !== null) {
            return NativeWriter::link($cell->getValue(), $cell->hyperlinkUrl, $style, $cell->hyperlink?->tooltip, $comment);
        }

        return match (true) {
            // A StringCell is never a formula, even if it starts with "=".
            $cell instanceof Cell\StringCell => NativeWriter::text($cell->getValue(), $style, $comment),
            $cell instanceof Cell\FormulaCell,
            $cell instanceof Cell\NumericCell,
            $cell instanceof Cell\BooleanCell,
            $cell instanceof Cell\EmptyCell,
            $cell instanceof Cell\DateTimeCell,
            $cell instanceof Cell\DateIntervalCell => NativeWriter::cell($cell->getValue(), $style, $comment),
            default => throw $this->unsupported($cell::class.' cells'),
        };
    }

    /** One extension style per OpenSpout Style object, as OpenSpout keys styles by identity. */
    private function styleId(Style $style): int
    {
        $key = spl_object_id($style);
        if (isset($this->styles[$key])) {
            return $this->styles[$key][1];
        }

        $spec = array_filter([
            'bold' => $style->fontBold ?: null,
            'italic' => $style->fontItalic ?: null,
            'underline' => $style->fontUnderline ?: null,
            'strikethrough' => $style->fontStrikethrough ?: null,
            'size' => $style->fontSize !== Style::DEFAULT_FONT_SIZE ? $style->fontSize : null,
            'color' => $style->fontColor !== Style::DEFAULT_FONT_COLOR ? $style->fontColor : null,
            'font' => $style->fontName !== Style::DEFAULT_FONT_NAME ? $style->fontName : null,
            'background' => $style->backgroundColor,
            'format' => $style->format,
            'align' => $style->cellAlignment?->value,
            'valign' => $style->cellVerticalAlignment?->value,
            'wrap' => $style->shouldWrapText,
            'rotation' => $style->textRotation !== 0 ? $style->textRotation : null,
            'shrink' => $style->shouldShrinkToFit,
            'border' => $style->border instanceof Border ? $this->borderSpec($style->border) : null,
        ], static fn (mixed $v): bool => $v !== null);

        $id = $this->native()->addStyle($spec);
        $this->styles[$key] = [$style, $id];

        return $id;
    }

    /**
     * The extension's border spec, parts in OpenSpout's order: two borders with the
     * same parts in a different order are separate entries in styles.xml, in both.
     *
     * @return array<string, array{style: string, width: string, color: string}>
     */
    private function borderSpec(Border $border): array
    {
        $spec = [];
        foreach ($border->getParts() as $part) {
            $spec[$part->name->value] = [
                'style' => $part->style->value,
                'width' => $part->width->value,
                'color' => $part->color,
            ];
        }

        return $spec;
    }

    /** Copy what WithSheetSetup configured on the OpenSpout Sheet to the extension. */
    private function applySheetSetup(): void
    {
        $sheet = $this->setupSheet;
        if (! $sheet instanceof Sheet) {
            return;
        }
        $this->setupSheet = null;

        $unsupported = array_keys(array_filter([
            'renaming the sheet (use WithTitle)' => $sheet->getName() !== $this->sheetName,
            'hidden sheets' => ! $sheet->isVisible(),
            'hidden columns' => $sheet->getColumnHiddens() !== [],
            'collapsed columns' => $sheet->getColumnCollapseds() !== [],
            'column outline levels' => $sheet->getColumnOutlineLevels() !== [],
            'print title rows' => $sheet->getPrintTitleRows() !== null,
            'sheet protection' => $sheet->getSheetProtection() instanceof SheetProtection,
        ]));
        $view = $sheet->getSheetView();
        if ($view instanceof SheetViewInterface) {
            $supported = $view instanceof SheetView
                && $view == new SheetView(showGridLines: $view->showGridLines, freezeRow: $view->freezeRow, freezeColumn: $view->freezeColumn);
            if (! $supported) {
                $unsupported[] = 'sheet view settings other than gridlines and frozen panes';
            }
        }
        if ($unsupported !== []) {
            throw $this->unsupported(implode(', ', $unsupported).' in WithSheetSetup');
        }

        $native = $this->native();
        $filter = $sheet->getAutoFilter();
        if ($filter instanceof AutoFilter) {
            $native->setAutoFilter(sprintf(
                '%s%d:%s%d',
                $this->columnLetters($filter->fromColumnIndex),
                $filter->fromRow,
                $this->columnLetters($filter->toColumnIndex),
                $filter->toRow,
            ));
        }
        foreach ($sheet->getColumnWidths() as $width) {
            $native->setColumnWidth($width->width, range($width->start, $width->end));
        }
        if ($view instanceof SheetView) {
            $native->showGridLines($view->showGridLines);
            // OpenSpout's freezeRow N freezes the rows above N; freezeColumn 'C' freezes A–B.
            if ($view->freezeRow >= 2 || $view->freezeColumn !== 'A') {
                $native->freezePanes(max(0, $view->freezeRow - 1), $this->columnIndex($view->freezeColumn));
            }
        }
    }

    private function columnIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /** 0 → A, 26 → AA. */
    private function columnLetters(int $index): string
    {
        $letters = '';
        for ($n = $index; $n >= 0; $n = intdiv($n, 26) - 1) {
            $letters = chr(65 + $n % 26).$letters;
        }

        return $letters;
    }

    private function unsupported(string $feature): UnsupportedByEngine
    {
        return new UnsupportedByEngine(
            "The rust writer does not support {$feature}. Set sheet-stream.default_writer to 'openspout' for this export."
        );
    }
}
