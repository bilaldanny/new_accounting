<?php

namespace App\Support;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class Base64Upload
{
    /**
     * Image types the legacy public-directory uploads (product, company logo, purchase attachment, bilty) accept.
     *
     * @var array<string, string>
     */
    private const PUBLIC_IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

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

    /**
     * Extension for a multipart upload that is saved into the public directory, taken from the detected content and never
     * from the client file name. Throws a validation error for anything that is not an image (or a PDF when allowed).
     *
     * @throws ValidationException
     */
    public static function publicExtensionFor(UploadedFile $file, bool $allowPdf = false): string
    {
        $mime = (string) $file->getMimeType();
        $extension = self::PUBLIC_IMAGE_EXTENSIONS[$mime] ?? ($allowPdf && $mime === 'application/pdf' ? 'pdf' : null);

        if ($extension === null) {
            throw ValidationException::withMessages([
                'file' => $allowPdf ? 'Only JPG, PNG, GIF, WebP or PDF files are allowed.' : 'Only JPG, PNG, GIF or WebP images are allowed.',
            ]);
        }

        return $extension;
    }

    /**
     * A name that is safe to put inside a stored file name: letters, digits, dash and underscore only, so no path
     * separators or dots can reach the file system.
     */
    public static function safeName(?string $name, string $fallback = 'file'): string
    {
        $clean = substr((string) preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $name), 0, 40);

        return $clean === '' ? $fallback : $clean;
    }
}
