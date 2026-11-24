<?php

declare(strict_types=1);

namespace MrDellimore\SheetStream\Tests\Fixtures;

use ZipArchive;

/**
 * Hand-writes a minimal .xlsx so each kind of "blank" cell is exactly what the
 * test says it is; the OpenSpout writer collapses null and '' into one cell type.
 *
 * Cell specs:
 *   null                     no <c> element at all (a gap in the row)
 *   ['empty']                <c r="B2"/>                       (visited but valueless)
 *   ['inline', 'text']       <c t="inlineStr"><is><t>text</t></is></c>
 *   ['shared', 'text']       <c t="s"><v>n</v></c>
 *   ['number', '0']          <c><v>0</v></c>
 *   ['bool', false]          <c t="b"><v>0</v></c>
 */
class BlankCellsXlsxFixtureBuilder
{
    private readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('sheet_blank_fixture_', true).'.xlsx';
    }

    /** @param array<int, array<int, array{0: string, 1?: mixed}|null>> $rows */
    public function write(array $rows): static
    {
        $shared = [];
        $sheetData = '';

        foreach ($rows as $r => $cells) {
            $rowNumber = $r + 1;
            $sheetData .= '<row r="'.$rowNumber.'">';

            foreach ($cells as $c => $spec) {
                if ($spec === null) {
                    continue;
                }

                $ref = chr(ord('A') + $c).$rowNumber;
                $value = $spec[1] ?? null;

                $sheetData .= match ($spec[0]) {
                    'empty' => '<c r="'.$ref.'"/>',
                    'inline' => '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>',
                    'shared' => '<c r="'.$ref.'" t="s"><v>'.$this->sharedIndex($shared, (string) $value).'</v></c>',
                    'number' => '<c r="'.$ref.'"><v>'.$value.'</v></c>',
                    'bool' => '<c r="'.$ref.'" t="b"><v>'.($value ? '1' : '0').'</v></c>',
                };
            }

            $sheetData .= '</row>';
        }

        $strings = '';
        foreach ($shared as $string) {
            $strings .= '<si><t xml:space="preserve">'.$this->escape($string).'</t></si>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml';

        $zip = new ZipArchive;
        $zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', $xml
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="'.$type.'.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="'.$type.'.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="'.$type.'.sharedStrings+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="'.$type.'.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', $xml
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $xml
            .'<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $xml
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.$rel.'/sharedStrings" Target="sharedStrings.xml"/>'
            .'<Relationship Id="rId3" Type="'.$rel.'/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', $xml
            .'<styleSheet xmlns="'.$main.'">'
            .'<fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="1"><xf/></cellXfs>'
            .'</styleSheet>');
        $zip->addFromString('xl/sharedStrings.xml', $xml
            .'<sst xmlns="'.$main.'" count="'.count($shared).'" uniqueCount="'.count($shared).'">'
            .$strings.'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml
            .'<worksheet xmlns="'.$main.'"><sheetData>'.$sheetData.'</sheetData></worksheet>');
        $zip->close();

        return $this;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function __destruct()
    {
        @unlink($this->path);
    }

    /** @param list<string> $shared */
    private function sharedIndex(array &$shared, string $value): int
    {
        $index = array_search($value, $shared, true);

        if ($index === false) {
            $shared[] = $value;
            $index = count($shared) - 1;
        }

        return $index;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
