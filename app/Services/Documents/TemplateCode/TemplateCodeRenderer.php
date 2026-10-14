<?php

namespace App\Services\Documents\TemplateCode;

use InvalidArgumentException;

/**
 * Turns a company's template code (HTML + CSS with {{placeholders}}) and one document's data into a safe, self-contained HTML page.
 *
 * Order matters: the template is rendered first (every value is escaped by LogicLessTemplate), the result is then sanitized as a whole,
 * and only after that is `{{qr}}` swapped in (an inline data: image the application itself produced). The page is meant for a sandboxed
 * iframe or a response with a `sandbox` CSP; it contains no script and no external resource.
 */
final class TemplateCodeRenderer
{
    public const QR_TOKEN = 'qr-token-placeholder';

    public function __construct(
        private readonly LogicLessTemplate $engine,
        private readonly HtmlSanitizer $html,
        private readonly CssSanitizer $css,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowedUrls
     */
    public function render(string $html, string $css, array $data, array $allowedUrls = [], ?string $qrDataUri = null): string
    {
        if (strlen($html) + strlen($css) > LogicLessTemplate::MAX_BYTES + CssSanitizer::MAX_BYTES) {
            throw new InvalidArgumentException('The template is too large.');
        }

        // {{qr}} and {{company.logo}} are addresses, not text: they are placed after sanitizing
        $data['qr'] = self::QR_TOKEN;
        $body = $this->engine->render($html, $data);
        $body = $this->html->sanitize($body, [...$allowedUrls, self::QR_TOKEN]);

        if ($qrDataUri !== null && preg_match('#^data:image/(png|svg\+xml);base64,[A-Za-z0-9+/=]+$#', $qrDataUri) === 1) {
            $body = str_replace(self::QR_TOKEN, $qrDataUri, $body);
        } else {
            $body = str_replace(self::QR_TOKEN, '', $body);
        }

        $style = $this->css->sanitize($css, $allowedUrls);

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<style>'.$style.'</style></head><body>'.$body.'</body></html>';
    }

    /**
     * Throws when the template cannot be parsed, so the editor's Validate button can say why.
     *
     * @return list<string> placeholder names the template uses
     */
    public function validate(string $html): array
    {
        $this->engine->render($html, []);

        return $this->engine->placeholders($html);
    }

    /**
     * The headers a page of rendered template code is served with: no script, no network, no framing by anything but this site.
     *
     * @return array<string, string>
     */
    public static function securityHeaders(): array
    {
        return [
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data: 'self'; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
