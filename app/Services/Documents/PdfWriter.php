<?php

namespace App\Services\Documents;

/**
 * A small PDF writer for plain text documents (statements, lists): A4 pages, the two standard Helvetica fonts that every PDF reader has
 * built in (so nothing is embedded and nothing needs installing), text, lines and filled rectangles. Text is written in Windows-1252, so
 * Latin letters, digits and the usual signs print as typed; a character outside that set (Urdu, Arabic) is printed as "?".
 */
final class PdfWriter
{
    /** Helvetica widths of the characters 32 to 126, in 1/1000 of the font size. */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /** @var list<string> the content stream of each page */
    private array $pages = [];

    private int $current = -1;

    public function __construct(private readonly float $width = 595.28, private readonly float $height = 841.89) {}

    public function pageWidth(): float
    {
        return $this->width;
    }

    public function pageHeight(): float
    {
        return $this->height;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    /**
     * Text with its left edge at $x and its baseline $y points from the top of the page.
     */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, float $gray = 0.0): void
    {
        if ($text === '') {
            return;
        }

        $this->ensurePage();
        $this->pages[$this->current] .= sprintf(
            "BT /%s %.2F Tf %.3F g %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, $gray, $x, $this->height - $y, $this->escape($text),
        );
    }

    public function textRight(float $xRight, float $y, string $text, float $size = 10, bool $bold = false, float $gray = 0.0): void
    {
        $this->text($xRight - $this->width($text, $size, $bold), $y, $text, $size, $bold, $gray);
    }

    public function textCenter(float $xCenter, float $y, string $text, float $size = 10, bool $bold = false, float $gray = 0.0): void
    {
        $this->text($xCenter - $this->width($text, $size, $bold) / 2, $y, $text, $size, $bold, $gray);
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, float $gray = 0.6): void
    {
        $this->ensurePage();
        $this->pages[$this->current] .= sprintf("%.3F G %.2F w %.2F %.2F m %.2F %.2F l S\n", $gray, $width, $x1, $this->height - $y1, $x2, $this->height - $y2);
    }

    /**
     * A filled rectangle with its top-left corner at ($x, $y) from the top of the page.
     */
    public function fillRect(float $x, float $y, float $w, float $h, float $gray = 0.93): void
    {
        $this->ensurePage();
        $this->pages[$this->current] .= sprintf("%.3F g %.2F %.2F %.2F %.2F re f\n", $gray, $x, $this->height - $y - $h, $w, $h);
    }

    public function width(string $text, float $size = 10, bool $bold = false): float
    {
        $total = 0;

        foreach (str_split($this->encode($text)) as $char) {
            $code = ord($char);
            $total += $code >= 32 && $code <= 126 ? self::WIDTHS[$code - 32] : 556;
        }

        return $total / 1000 * $size * ($bold ? 1.06 : 1.0);
    }

    /**
     * The text cut to fit $maxWidth, with "..." when it was cut.
     */
    public function fit(string $text, float $maxWidth, float $size = 10, bool $bold = false): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($this->width($text, $size, $bold) <= $maxWidth) {
            return $text;
        }

        while ($text !== '' && $this->width($text.'...', $size, $bold) > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'...';
    }

    public function output(): string
    {
        $this->ensurePage();
        $objects = [];
        $count = count($this->pages);
        $kids = [];

        // 1 catalog, 2 pages, 3 and 4 fonts, then a page and its content per page
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        for ($i = 0; $i < $count; $i++) {
            $pageId = 5 + $i * 2;
            $contentId = $pageId + 1;
            $kids[] = "{$pageId} 0 R";
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                $this->width, $this->height, $contentId,
            );
            $objects[$contentId] = '<< /Length '.strlen($this->pages[$i])." >>\nstream\n".$this->pages[$i].'endstream';
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$count.' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 ".($max + 1)."\n0000000000 65535 f \n";

        for ($id = 1; $id <= $max; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }

        return $pdf."trailer\n<< /Size ".($max + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function ensurePage(): void
    {
        if ($this->current < 0) {
            $this->addPage();
        }
    }

    private function encode(string $text): string
    {
        $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return is_string($converted) ? $converted : preg_replace('/[^\x20-\x7E]/', '?', $text);
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $this->encode($text));
    }
}
