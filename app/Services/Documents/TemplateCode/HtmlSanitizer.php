<?php

namespace App\Services\Documents\TemplateCode;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * An allowlist HTML sanitizer for invoice templates. The markup is parsed, walked and rebuilt from what is allowed:
 *
 *  - an element that is not on the list is removed with everything inside it (script, iframe, object, embed, form, input, button, base,
 *    link, meta, svg, math, template ...); `style` elements are kept and cleaned by CssSanitizer;
 *  - an attribute that is not on the list is dropped, so every `on*` handler goes; `href` / `src` must be http(s), mailto, tel, a
 *    site-relative path, or (images only) data:image/; any other scheme, javascript: included, removes the attribute;
 *  - a link gets rel="noopener noreferrer nofollow" and opens nothing by itself;
 *  - `style` attributes go through CssSanitizer;
 *  - `src` of an image must be the company's logo, a data:image/ address or a site path: an external address is refused, so a printed
 *    document can never call another server.
 */
final class HtmlSanitizer
{
    private const TAGS = [
        'div', 'span', 'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'colgroup', 'col',
        'caption', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup', 'ul', 'ol', 'li', 'img', 'a', 'section', 'header', 'footer', 'article',
        'main', 'figure', 'figcaption', 'blockquote', 'pre', 'code', 'dl', 'dt', 'dd', 'address', 'style',
    ];

    private const ATTRIBUTES = ['class', 'id', 'style', 'colspan', 'rowspan', 'width', 'height', 'align', 'valign', 'dir', 'lang', 'title', 'alt', 'src', 'href', 'scope', 'span'];

    public function __construct(private readonly CssSanitizer $css) {}

    /**
     * @param  list<string>  $allowedUrls  addresses an image or a CSS url() may use (the logo)
     */
    public function sanitize(string $html, array $allowedUrls = []): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="utf-8" ?><!DOCTYPE html><html><body><div id="__root">'.$html.'</div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('__root');

        if ($root === null) {
            return '';
        }

        $this->clean($root, $allowedUrls);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    /**
     * @param  list<string>  $allowedUrls
     */
    private function clean(DOMNode $node, array $allowedUrls): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                if (! in_array($tag, self::TAGS, true)) {
                    $node->removeChild($child);

                    continue;
                }

                if ($tag === 'style') {
                    $clean = $this->css->sanitize((string) $child->textContent, $allowedUrls);

                    while ($child->firstChild !== null) {
                        $child->removeChild($child->firstChild);
                    }

                    $child->appendChild($child->ownerDocument->createTextNode($clean));

                    continue;
                }

                $this->attributes($child, $tag, $allowedUrls);
                $this->clean($child, $allowedUrls);

                continue;
            }

            // comments, processing instructions and CDATA carry nothing a document needs
            if ($child->nodeType !== XML_TEXT_NODE) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * @param  list<string>  $allowedUrls
     */
    private function attributes(DOMElement $element, string $tag, array $allowedUrls): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if (! in_array($name, self::ATTRIBUTES, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if ($name === 'style') {
                $clean = $this->css->declarations($value, $allowedUrls);
                $clean === '' ? $element->removeAttribute('style') : $element->setAttribute('style', $clean);

                continue;
            }

            if ($name === 'href' && ! ($tag === 'a' && $this->safeLink($value))) {
                $element->removeAttribute('href');

                continue;
            }

            if ($name === 'src' && ! ($tag === 'img' && $this->safeImage($value, $allowedUrls))) {
                $element->removeAttribute('src');

                continue;
            }

            if (in_array($name, ['id', 'class'], true) && preg_match('/^[A-Za-z0-9_\- ]*$/', $value) !== 1) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'a') {
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
            $element->setAttribute('target', '_blank');
        }
    }

    private function safeLink(string $value): bool
    {
        return preg_match('#^(https?://[^\s"\'<>]+|mailto:[^\s"\'<>]+|tel:[+0-9\-\s()]+|/[^\s"\'<>]*|\#[A-Za-z0-9_\-]+)$#i', $value) === 1;
    }

    /**
     * @param  list<string>  $allowedUrls
     */
    private function safeImage(string $value, array $allowedUrls): bool
    {
        if (in_array($value, $allowedUrls, true)) {
            return true;
        }

        return preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $value) === 1;
    }
}
