<?php

namespace App\Support;

use finfo;

class Base64Upload
{
    /**
     * Server-trusted MIME type => file extension. The client-declared MIME
     * prefix on a data URL is never trusted for this mapping.
     *
     * @var array<string, string>
     */
    private const ALLOWED_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    /**
     * Decode a base64 data URL and verify its actual binary content against
     * a MIME whitelist, returning a server-determined extension. Returns
     * null if the payload is malformed or its real content type isn't
     * whitelisted, regardless of what extension/MIME the data URL claims.
     *
     * @return array{binary: string, extension: string, mime: string}|null
     */
    public static function decode(string $dataUrl): ?array
    {
        if (! str_contains($dataUrl, ',')) {
            return null;
        }

        $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);

        if ($binary === false || $binary === '') {
            return null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($binary);

        if (! is_string($mime) || ! isset(self::ALLOWED_MIME_EXTENSIONS[$mime])) {
            return null;
        }

        return [
            'binary' => $binary,
            'extension' => self::ALLOWED_MIME_EXTENSIONS[$mime],
            'mime' => $mime,
        ];
    }

    /**
     * Map a server-verified MIME type (e.g. from `UploadedFile::getMimeType()`,
     * which inspects the real file content rather than the client-supplied
     * filename or Content-Type header) to a safe extension. Returns null for
     * anything outside the whitelist.
     */
    public static function extensionForMime(string $mime): ?string
    {
        return self::ALLOWED_MIME_EXTENSIONS[$mime] ?? null;
    }
}
