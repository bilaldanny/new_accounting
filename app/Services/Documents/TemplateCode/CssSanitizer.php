<?php

namespace App\Services\Documents\TemplateCode;

/**
 * Cleans the CSS of an invoice template. It is an allowlist of what a printed document needs, not a blacklist of known tricks:
 *
 *  - only plain rules, `@page` and `@media print|screen` blocks survive; `@import`, `@font-face`, `@charset`, `@namespace`, `@keyframes` and
 *    every other at-rule is dropped with its body;
 *  - a declaration is kept only when its property is on the list (typography, box model, tables, colours, flex / grid layout, borders)
 *    and its value contains no `expression(`, `behavior`, `binding`, `javascript:` or backslash escape, and every `url()` in it is either a
 *    `data:image/` address or one of the addresses the caller allows (the company's logo);
 *  - `position: fixed` and `position: sticky` are refused (a template cannot cover the application), as is `z-index` above 10;
 *  - comments, `<`, `>` and `{`/`}` inside values are stripped, so a value cannot close the style element or open a rule.
 */
final class CssSanitizer
{
    public const MAX_BYTES = 60 * 1024;

    private const PROPERTIES = [
        'color', 'background', 'background-color', 'background-image', 'background-size', 'background-position', 'background-repeat',
        'font', 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'line-height', 'letter-spacing', 'word-spacing',
        'text-align', 'text-transform', 'text-decoration', 'text-indent', 'white-space', 'vertical-align', 'direction', 'unicode-bidi',
        'width', 'min-width', 'max-width', 'height', 'min-height', 'max-height', 'margin', 'margin-top', 'margin-right', 'margin-bottom',
        'margin-left', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'display', 'float', 'clear',
        'position', 'top', 'right', 'bottom', 'left', 'z-index', 'overflow', 'overflow-x', 'overflow-y', 'box-sizing', 'opacity',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-style', 'border-width',
        'border-radius', 'border-collapse', 'border-spacing', 'table-layout', 'caption-side', 'empty-cells',
        'flex', 'flex-direction', 'flex-wrap', 'flex-grow', 'flex-shrink', 'flex-basis', 'justify-content', 'align-items', 'align-self',
        'gap', 'row-gap', 'column-gap', 'grid-template-columns', 'grid-template-rows', 'grid-column', 'grid-row',
        'page-break-before', 'page-break-after', 'page-break-inside', 'break-before', 'break-after', 'break-inside',
        'size', 'orphans', 'widows', 'list-style', 'list-style-type', 'content', 'quotes', 'counter-reset', 'counter-increment',
    ];

    /**
     * @param  list<string>  $allowedUrls  addresses a `url()` may point to besides data:image/ (the company logo)
     */
    public function sanitize(string $css, array $allowedUrls = []): string
    {
        $css = substr($css, 0, self::MAX_BYTES);
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        // CSS escapes (\6a avascript) are how filters get bypassed: a template does not need them
        $css = str_replace(['\\', '<', '>', "\0"], '', $css);

        // statement at-rules (@import, @charset, @namespace) end with a semicolon and have no block
        $css = preg_replace('/@[A-Za-z-]+[^{};]*;/', '', $css) ?? '';

        return trim($this->rules($css, $allowedUrls, 0));
    }

    /**
     * @param  list<string>  $allowedUrls
     */
    private function rules(string $css, array $allowedUrls, int $depth): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($css);

        while ($offset < $length) {
            $open = strpos($css, '{', $offset);

            if ($open === false) {
                break;
            }

            $selector = trim(substr($css, $offset, $open - $offset));
            $close = $this->matchingBrace($css, $open);

            if ($close === null) {
                break;
            }

            $body = substr($css, $open + 1, $close - $open - 1);
            $offset = $close + 1;

            if (str_starts_with($selector, '@')) {
                if (preg_match('/^@page\b/i', $selector) === 1) {
                    $out .= $selector.'{'.$this->declarations($body, $allowedUrls)."}\n";
                } elseif ($depth === 0 && preg_match('/^@media\s+(print|screen)\s*$/i', $selector) === 1) {
                    $out .= $selector.'{'.$this->rules($body, $allowedUrls, $depth + 1)."}\n";
                }

                continue;
            }

            if ($selector === '' || preg_match('/^[A-Za-z0-9_\-\s.,#>+~*:\[\]="\'()]+$/', $selector) !== 1 || preg_match('/(expression|javascript|@)/i', $selector) === 1) {
                continue;
            }

            $declarations = $this->declarations($body, $allowedUrls);

            if ($declarations !== '') {
                $out .= $selector.'{'.$declarations."}\n";
            }
        }

        return $out;
    }

    private function matchingBrace(string $css, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * A style attribute or the body of a rule.
     *
     * @param  list<string>  $allowedUrls
     */
    public function declarations(string $body, array $allowedUrls = []): string
    {
        $kept = [];

        foreach (explode(';', $body) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);

            if (! in_array($property, self::PROPERTIES, true) || $value === '' || $this->dangerous($value)) {
                continue;
            }

            if ($property === 'position' && preg_match('/^(static|relative|absolute)$/i', $value) !== 1) {
                continue;
            }

            if ($property === 'z-index' && (preg_match('/^-?\d+$/', $value) !== 1 || abs((int) $value) > 10)) {
                continue;
            }

            if (! $this->urlsAllowed($value, $allowedUrls)) {
                continue;
            }

            $kept[] = $property.':'.preg_replace('/\s+/', ' ', $value);
        }

        return implode(';', $kept);
    }

    private function dangerous(string $value): bool
    {
        return preg_match('/expression\s*\(|behavior|binding|javascript\s*:|vbscript\s*:|@import|[{}]|\bimage-set\b|\bsrc\s*\(/i', $value) === 1;
    }

    /**
     * @param  list<string>  $allowedUrls
     */
    private function urlsAllowed(string $value, array $allowedUrls): bool
    {
        if (stripos($value, 'url(') === false) {
            return true;
        }

        if (preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $value, $matches) === 0) {
            return false;
        }

        foreach ($matches[2] as $url) {
            $url = trim($url);

            if (preg_match('#^data:image/(png|jpe?g|gif|webp|svg\+xml);base64,[A-Za-z0-9+/=]+$#i', $url) === 1) {
                continue;
            }

            if (! in_array($url, $allowedUrls, true)) {
                return false;
            }
        }

        return true;
    }
}
