<?php

namespace App\Services\Documents;

use InvalidArgumentException;

/**
 * A QR Code encoder (ISO/IEC 18004), byte mode, error correction level M, versions 1 to 9 (up to 180 bytes). The matrix is built the
 * way the standard describes: the data and Reed-Solomon codewords over GF(256) (polynomial 0x11D) interleaved by block, the function
 * patterns, the zig-zag placement, the mask with the lowest penalty and the BCH-protected format information.
 *
 * `matrix()` returns the modules (true is dark) and `svg()` an SVG of it. The encoder carries no dependency; correctness is covered by
 * the published reference codewords and a decoder of the format bits and error correction in the tests.
 */
final class QrCode
{
    /**
     * Version => [data codewords, ecc codewords per block, [block count, data codewords per block] groups]. Level M.
     *
     * @var array<int, array{0: int, 1: int, 2: list<array{0: int, 1: int}>}>
     */
    private const SPEC = [
        1 => [16, 10, [[1, 16]]],
        2 => [28, 16, [[1, 28]]],
        3 => [44, 26, [[1, 44]]],
        4 => [64, 18, [[2, 32]]],
        5 => [86, 24, [[2, 43]]],
        6 => [108, 16, [[4, 27]]],
        7 => [124, 18, [[4, 31]]],
        8 => [154, 22, [[2, 38], [2, 39]]],
        9 => [182, 22, [[3, 36], [2, 37]]],
    ];

    private const ALIGNMENT = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46]];

    private const REMAINDER_BITS = [1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0];

    /**
     * @var list<int>
     */
    private static array $exp = [];

    /**
     * @var list<int>
     */
    private static array $log = [];

    /**
     * @return list<list<bool>>
     */
    public static function matrix(string $text): array
    {
        $bytes = array_values(unpack('C*', $text) ?: []);
        $version = null;

        foreach (self::SPEC as $candidate => $spec) {
            // 4 bits mode + 8 bits count + 8 bits per byte, in data codewords
            if ((int) ceil((12 + 8 * count($bytes)) / 8) <= $spec[0]) {
                $version = $candidate;
                break;
            }
        }

        if ($version === null) {
            throw new InvalidArgumentException('The text is too long for a QR code of this encoder (180 bytes).');
        }

        $codewords = self::interleave(self::dataCodewords($bytes, $version), $version);
        $size = 17 + 4 * $version;
        [$modules, $reserved] = self::functionPatterns($version, $size);
        self::placeData($modules, $reserved, $codewords, $size, $version);

        $best = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::applyMask($modules, $reserved, $mask, $size);
            self::placeFormat($candidate, $mask, $size);
            $penalty = self::penalty($candidate, $size);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $candidate;
            }
        }

        return $best;
    }

    public static function svg(string $text, int $pixels = 120): string
    {
        $matrix = self::matrix($text);
        $size = count($matrix);
        $quiet = 4;
        $total = $size + 2 * $quiet;
        $path = '';

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M'.($x + $quiet).' '.($y + $quiet).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$total.'" width="'.$pixels.'" height="'.$pixels.'" shape-rendering="crispEdges" role="img" aria-label="QR code">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="#fff"/><path d="'.$path.'" fill="#000"/></svg>';
    }

    /**
     * The data codewords of a byte-mode message padded to the version's capacity.
     *
     * @param  list<int>  $bytes
     * @return list<int>
     */
    public static function dataCodewords(array $bytes, int $version): array
    {
        $capacity = self::SPEC[$version][0];
        $bits = '0100'.str_pad(decbin(count($bytes)), 8, '0', STR_PAD_LEFT);

        foreach ($bytes as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $words = array_map('bindec', str_split($bits, 8));

        for ($pad = [236, 17], $i = 0; count($words) < $capacity; $i++) {
            $words[] = $pad[$i % 2];
        }

        return $words;
    }

    /**
     * The Reed-Solomon error correction codewords of a data block.
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    public static function errorCorrection(array $data, int $count): array
    {
        self::tables();
        $generator = [1];

        for ($i = 0; $i < $count; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);

            foreach ($generator as $j => $coefficient) {
                $next[$j] ^= $coefficient;
                $next[$j + 1] ^= self::multiply($coefficient, self::$exp[$i]);
            }

            $generator = $next;
        }

        $remainder = array_merge($data, array_fill(0, $count, 0));

        for ($i = 0; $i < count($data); $i++) {
            $factor = $remainder[$i];

            if ($factor === 0) {
                continue;
            }

            foreach ($generator as $j => $coefficient) {
                $remainder[$i + $j] ^= self::multiply($coefficient, $factor);
            }
        }

        return array_slice($remainder, count($data));
    }

    /**
     * @param  list<int>  $data
     * @return list<int>
     */
    private static function interleave(array $data, int $version): array
    {
        [, $eccPerBlock, $groups] = self::SPEC[$version];
        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        foreach ($groups as [$blocks, $size]) {
            for ($b = 0; $b < $blocks; $b++) {
                $block = array_slice($data, $offset, $size);
                $offset += $size;
                $dataBlocks[] = $block;
                $eccBlocks[] = self::errorCorrection($block, $eccPerBlock);
            }
        }

        $result = [];
        $longest = max(array_map('count', $dataBlocks));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $eccPerBlock; $i++) {
            foreach ($eccBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * @return array{0: list<list<bool>>, 1: list<list<bool>>}
     */
    private static function functionPatterns(int $version, int $size): array
    {
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));
        $set = function (int $x, int $y, bool $dark) use (&$modules, &$reserved, $size): void {
            if ($x >= 0 && $y >= 0 && $x < $size && $y < $size) {
                $modules[$y][$x] = $dark;
                $reserved[$y][$x] = true;
            }
        };

        // finder patterns with their separators
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$ox, $oy]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $inside = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                    $dark = $inside && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6 || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                    $set($ox + $dx, $oy + $dy, $dark);
                }
            }
        }

        // timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $set($i, 6, $i % 2 === 0);
            $set(6, $i, $i % 2 === 0);
        }

        // alignment patterns
        $positions = self::ALIGNMENT[$version];

        foreach ($positions as $py) {
            foreach ($positions as $px) {
                $overlapsFinder = ($px === 6 && $py === 6) || ($px === 6 && $py === $positions[count($positions) - 1]) || ($py === 6 && $px === $positions[count($positions) - 1]);

                if ($overlapsFinder) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        // the dark module and the format areas (filled later)
        $set(8, 4 * $version + 9, true);

        for ($i = 0; $i < 9; $i++) {
            $set(8, $i, false);
            $set($i, 8, false);
        }

        for ($i = 0; $i < 8; $i++) {
            $set($size - 1 - $i, 8, false);
            $set(8, $size - 1 - $i, false);
        }

        $set(8, 4 * $version + 9, true);

        if ($version >= 7) {
            $info = self::versionBits($version);

            for ($i = 0; $i < 18; $i++) {
                $dark = (($info >> $i) & 1) === 1;
                $set($size - 11 + $i % 3, intdiv($i, 3), $dark);
                $set(intdiv($i, 3), $size - 11 + $i % 3, $dark);
            }
        }

        return [$modules, $reserved];
    }

    /**
     * @param  list<list<bool>>  $modules
     * @param  list<list<bool>>  $reserved
     * @param  list<int>  $codewords
     */
    private static function placeData(array &$modules, array $reserved, array $codewords, int $size, int $version): void
    {
        $bits = '';

        foreach ($codewords as $word) {
            $bits .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);
        }

        $bits .= str_repeat('0', self::REMAINDER_BITS[$version]);
        $index = 0;
        $upward = true;

        for ($x = $size - 1; $x >= 1; $x -= 2) {
            if ($x === 6) {
                $x = 5;
            }

            for ($step = 0; $step < $size; $step++) {
                $y = $upward ? $size - 1 - $step : $step;

                foreach ([$x, $x - 1] as $column) {
                    if (! $reserved[$y][$column]) {
                        $modules[$y][$column] = ($bits[$index] ?? '0') === '1';
                        $index++;
                    }
                }
            }

            $upward = ! $upward;
        }
    }

    /**
     * @param  list<list<bool>>  $modules
     * @param  list<list<bool>>  $reserved
     * @return list<list<bool>>
     */
    private static function applyMask(array $modules, array $reserved, int $mask, int $size): array
    {
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$y][$x]) {
                    continue;
                }

                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };

                if ($flip) {
                    $modules[$y][$x] = ! $modules[$y][$x];
                }
            }
        }

        return $modules;
    }

    /**
     * The 15 format bits of level M and a mask: 5 data bits, BCH(15,5), XOR 0x5412.
     */
    public static function formatBits(int $mask): int
    {
        $data = (0b00 << 3) | $mask;
        $remainder = $data << 10;

        for ($i = 14; $i >= 10; $i--) {
            if ((($remainder >> $i) & 1) === 1) {
                $remainder ^= 0x537 << ($i - 10);
            }
        }

        return (($data << 10) | $remainder) ^ 0x5412;
    }

    private static function versionBits(int $version): int
    {
        $remainder = $version << 12;

        for ($i = 17; $i >= 12; $i--) {
            if ((($remainder >> $i) & 1) === 1) {
                $remainder ^= 0x1F25 << ($i - 12);
            }
        }

        return ($version << 12) | $remainder;
    }

    /**
     * @param  list<list<bool>>  $modules
     */
    private static function placeFormat(array &$modules, int $mask, int $size): void
    {
        $bits = self::formatBits($mask);

        for ($i = 0; $i < 15; $i++) {
            $dark = (($bits >> $i) & 1) === 1;

            // first copy around the top-left finder
            if ($i < 6) {
                $modules[$i][8] = $dark;
            } elseif ($i < 8) {
                $modules[$i + 1][8] = $dark;
            } elseif ($i === 8) {
                $modules[8][7] = $dark;
            } else {
                $modules[8][14 - $i] = $dark;
            }

            // second copy: along the bottom-left and top-right finders
            if ($i < 8) {
                $modules[8][$size - 1 - $i] = $dark;
            } else {
                $modules[$size - 15 + $i][8] = $dark;
            }
        }
    }

    /**
     * @param  list<list<bool>>  $m
     */
    private static function penalty(array $m, int $size): int
    {
        $penalty = 0;

        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                $run = 1;

                for ($b = 1; $b < $size; $b++) {
                    $now = $pass === 0 ? $m[$a][$b] : $m[$b][$a];
                    $before = $pass === 0 ? $m[$a][$b - 1] : $m[$b - 1][$a];

                    if ($now === $before) {
                        $run++;

                        if ($run === 5) {
                            $penalty += 3;
                        } elseif ($run > 5) {
                            $penalty++;
                        }
                    } else {
                        $run = 1;
                    }
                }
            }
        }

        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                if ($m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) {
                    $penalty += 3;
                }
            }
        }

        $pattern = [true, false, true, true, true, false, true, false, false, false, false];
        $reverse = array_reverse($pattern);

        for ($pass = 0; $pass < 2; $pass++) {
            for ($a = 0; $a < $size; $a++) {
                for ($b = 0; $b <= $size - 11; $b++) {
                    $matchesForward = true;
                    $matchesReverse = true;

                    for ($k = 0; $k < 11; $k++) {
                        $value = $pass === 0 ? $m[$a][$b + $k] : $m[$b + $k][$a];
                        $matchesForward = $matchesForward && $value === $pattern[$k];
                        $matchesReverse = $matchesReverse && $value === $reverse[$k];
                    }

                    $penalty += ($matchesForward ? 40 : 0) + ($matchesReverse ? 40 : 0);
                }
            }
        }

        $dark = 0;

        foreach ($m as $row) {
            $dark += count(array_filter($row));
        }

        $percent = $dark * 100 / ($size * $size);

        return $penalty + (int) (abs(intdiv((int) round($percent), 5) * 5 - 50) / 5) * 10;
    }

    private static function tables(): void
    {
        if (self::$exp !== []) {
            return;
        }

        $value = 1;

        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $value;
            self::$log[$value] = $i;
            $value <<= 1;

            if ($value & 0x100) {
                $value ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return self::$exp[self::$log[$a] + self::$log[$b]];
    }
}
