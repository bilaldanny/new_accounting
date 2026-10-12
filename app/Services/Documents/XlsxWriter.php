<?php

namespace App\Services\Documents;

use RuntimeException;
use ZipArchive;

/**
 * A minimal .xlsx writer (one sheet): the workbook is a zip of a few XML parts, text goes in as inline strings and numbers as numbers.
 * It carries no dependency. A text that starts like a formula is stored as text, never as a formula (an xlsx cell only becomes a
 * formula with an <f> element, which this writer never writes).
 */
final class XlsxWriter
{
    /**
     * @param  list<list<scalar|null>>  $rows  the first row is usually the header
     */
    public static function build(array $rows, string $sheetName = 'Sheet1'): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is needed to build an Excel file.');
        }

        $sheetName = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $sheetName) ?? 'Sheet1', 0, 31) ?: 'Sheet1';
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        foreach (array_values($rows) as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';

            foreach (array_values($row) as $c => $value) {
                $ref = self::column($c).($r + 1);

                if ($value === null || $value === '') {
                    continue;
                }

                if (is_int($value) || is_float($value)) {
                    $xml .= '<c r="'.$ref.'"><v>'.(float) $value.'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.self::escape((string) $value).'</t></is></c>';
                }
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not build the Excel file.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.self::escape($sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }

    private static function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private static function escape(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
