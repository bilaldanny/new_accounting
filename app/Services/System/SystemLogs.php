<?php

namespace App\Services\System;

/**
 * Reads the application log (storage/logs/laravel.log, or the newest daily file) for the superadmin: the last `MAX_BYTES` of the
 * file are split into entries by their `[date] environment.LEVEL:` header, newest first, filtered by level and text. Stack traces
 * are cut to a few lines and a few well-known secrets (passwords, bearer tokens, API keys) are masked in what is shown. Read only.
 */
class SystemLogs
{
    public const MAX_BYTES = 400000;

    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * @return array{file: string|null, size: int, entries: list<array{time: string, env: string, level: string, message: string, trace: string}>}
     */
    public function read(?string $level = null, ?string $search = null, int $limit = 100, ?string $directory = null): array
    {
        $file = $this->newest($directory ?? storage_path('logs'));

        if ($file === null) {
            return ['file' => null, 'size' => 0, 'entries' => []];
        }

        $size = (int) filesize($file);
        $handle = fopen($file, 'rb');
        fseek($handle, max(0, $size - self::MAX_BYTES));
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[T ][\d:.+\-Z]+)\] (\w+)\.(\w+): (.*?)(?=^\[\d{4}-\d{2}-\d{2}[T ]|\z)/ms', $text, $matches, PREG_SET_ORDER);

        $entries = [];

        foreach (array_reverse($matches) as $match) {
            $entryLevel = strtolower($match[3]);
            $body = $this->mask(rtrim($match[4]));
            [$message, $trace] = array_pad(explode("\n", $body, 2), 2, '');

            if ($level !== null && $level !== '' && $entryLevel !== strtolower($level)) {
                continue;
            }

            if ($search !== null && $search !== '' && stripos($body, $search) === false) {
                continue;
            }

            $entries[] = [
                'time' => $match[1], 'env' => $match[2], 'level' => $entryLevel, 'message' => mb_substr($message, 0, 600),
                'trace' => implode("\n", array_slice(explode("\n", $trace), 0, 6)),
            ];

            if (count($entries) >= $limit) {
                break;
            }
        }

        return ['file' => basename($file), 'size' => $size, 'entries' => $entries];
    }

    private function newest(string $directory): ?string
    {
        $files = glob(rtrim($directory, '/\\').'/*.log') ?: [];

        if ($files === []) {
            return null;
        }

        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    private function mask(string $text): string
    {
        return (string) preg_replace(
            ['/("?(?:password|passwd|secret|token|api[_-]?key|authorization)"?\s*[:=]\s*"?(?:Bearer\s+)?)[^",\s}]+/i', '/(Bearer\s+)[A-Za-z0-9._\-]+/i'],
            ['$1***', '$1***'],
            $text,
        );
    }
}
