<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * A small QR code encoder for short text (byte mode, error correction level M, versions 1 to 10).
 * Worker cards carry about 35 characters, so that is plenty. It follows ISO/IEC 18004 and is checked
 * against a real decoder in tests/Feature/Workforce/QrCodeTest.php.
 */
final class QrCode
{
    // Error correction codewords per block and number of blocks, level M, versions 1 to 10.
    private const ECC_PER_BLOCK = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26];

    private const BLOCKS = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

    private const MAX_VERSION = 10;

    /** @var list<list<bool>> */
    private array $modules = [];

    /** @var list<list<bool>> */
    private array $isFunction = [];

    private int $size;

    private function __construct(private readonly int $version, array $dataCodewords)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);

        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($dataCodewords));

        $best = 0;
        $lowest = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();

            if ($penalty < $lowest) {
                [$best, $lowest] = [$mask, $penalty];
            }

            $this->applyMask($mask);
        }

        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    /** Dark modules as rows of booleans, without the quiet zone. @return list<list<bool>> */
    public static function matrix(string $text): array
    {
        return self::encode($text)->modules;
    }

    /** An SVG image that scales to any size. The quiet zone is part of the image. */
    public static function svg(string $text, int $quietZone = 4): string
    {
        $modules = self::matrix($text);
        $size = count($modules);
        $total = $size + 2 * $quietZone;
        $path = '';

        foreach ($modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M'.($x + $quietZone).','.($y + $quietZone).'h1v1h-1z';
                }
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$total.'" width="'.$total.'" height="'.$total.'" shape-rendering="crispEdges">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="#ffffff"/><path d="'.$path.'" fill="#000000"/></svg>';
    }

    private static function encode(string $text): self
    {
        $bytes = array_values(unpack('C*', $text) ?: []);

        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            $capacityBits = self::dataCodewords($version) * 8;
            $countBits = $version < 10 ? 8 : 16;

            if (4 + $countBits + 8 * count($bytes) > $capacityBits) {
                continue;
            }

            $bits = [];
            self::append($bits, 0b0100, 4);
            self::append($bits, count($bytes), $countBits);
            foreach ($bytes as $byte) {
                self::append($bits, $byte, 8);
            }
            self::append($bits, 0, min(4, $capacityBits - count($bits)));
            self::append($bits, 0, (8 - count($bits) % 8) % 8);

            $codewords = [];
            foreach (array_chunk($bits, 8) as $chunk) {
                $codewords[] = bindec(implode('', $chunk));
            }
            for ($pad = 0xEC; count($codewords) < self::dataCodewords($version); $pad ^= 0xEC ^ 0x11) {
                $codewords[] = $pad;
            }

            return new self($version, $codewords);
        }

        throw new InvalidArgumentException('That text is too long for a QR code here.');
    }

    private static function append(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    private static function rawModules(int $version): int
    {
        $result = (16 * $version + 128) * $version + 64;

        if ($version >= 2) {
            $align = intdiv($version, 7) + 2;
            $result -= (25 * $align - 10) * $align - 55;

            if ($version >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function dataCodewords(int $version): int
    {
        return intdiv(self::rawModules($version), 8) - self::ECC_PER_BLOCK[$version - 1] * self::BLOCKS[$version - 1];
    }

    /** @return list<int> */
    private function addEccAndInterleave(array $data): array
    {
        $blocks = self::BLOCKS[$this->version - 1];
        $eccLength = self::ECC_PER_BLOCK[$this->version - 1];
        $raw = intdiv(self::rawModules($this->version), 8);
        $shortBlocks = $blocks - $raw % $blocks;
        $shortLength = intdiv($raw, $blocks);
        $divisor = self::reedSolomonDivisor($eccLength);

        $parts = [];
        $offset = 0;

        for ($i = 0; $i < $blocks; $i++) {
            $length = $shortLength - $eccLength + ($i < $shortBlocks ? 0 : 1);
            $chunk = array_slice($data, $offset, $length);
            $offset += $length;
            $ecc = self::reedSolomonRemainder($chunk, $divisor);

            if ($i < $shortBlocks) {
                $chunk[] = 0;
            }

            $parts[] = [...$chunk, ...$ecc];
        }

        $result = [];

        for ($i = 0; $i < count($parts[0]); $i++) {
            foreach ($parts as $j => $block) {
                if ($i !== $shortLength - $eccLength || $j >= $shortBlocks) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /** @return list<int> */
    private static function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = self::multiply($root, 0x02);
        }

        return $result;
    }

    /** @return list<int> */
    private static function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= self::multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    private static function multiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $positions = $this->alignmentPositions();
        $count = count($positions);

        foreach ($positions as $i => $cx) {
            foreach ($positions as $j => $cy) {
                if (! (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0))) {
                    $this->drawAlignment($cx, $cy);
                }
            }
        }

        $this->drawFormatBits(0);
        $this->drawVersion();
    }

    private function drawFinder(int $cx, int $cy): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $x = $cx + $dx;
                $y = $cy + $dy;

                if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
                    $this->setFunction($x, $y, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $cx, int $cy): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }

        $count = intdiv($this->version, 7) + 2;
        $step = (int) (ceil(($this->size - 13) / ($count * 2 - 2)) * 2);
        $result = [6];

        for ($position = $this->size - 7; count($result) < $count; $position -= $step) {
            array_splice($result, 1, 0, [$position]);
        }

        return $result;
    }

    private function drawFormatBits(int $mask): void
    {
        // Error correction level M has format bits 00.
        $data = $mask;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $bits = (($data << 10) | $remainder) ^ 0x5412;
        $bit = fn (int $i): bool => (($bits >> $i) & 1) === 1;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }

        $remainder = $this->version;

        for ($i = 0; $i < 12; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 11) * 0x1F25);
        }

        $bits = ($this->version << 12) | $remainder;

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    private function drawCodewords(array $data): void
    {
        $i = 0;
        $total = count($data) * 8;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vertical : $vertical;

                    if (! $this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };

                if ($invert && ! $this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /** The standard four penalty rules. A lower score reads more reliably. */
    private function penalty(): int
    {
        $size = $this->size;
        $score = 0;
        $dark = 0;
        $lines = [];

        for ($y = 0; $y < $size; $y++) {
            $lines[] = $this->modules[$y];
            $dark += count(array_filter($this->modules[$y]));
        }

        for ($x = 0; $x < $size; $x++) {
            $lines[] = array_column($this->modules, $x);
        }

        foreach ($lines as $line) {
            $run = 1;

            for ($i = 1; $i < $size; $i++) {
                if ($line[$i] === $line[$i - 1]) {
                    $run++;

                    if ($run === 5) {
                        $score += 3;
                    } elseif ($run > 5) {
                        $score++;
                    }
                } else {
                    $run = 1;
                }
            }

            $text = implode('', array_map(fn ($module) => $module ? '1' : '0', $line));
            $score += 40 * (substr_count($text, '10111010000') + substr_count($text, '00001011101'));
        }

        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $colour = $this->modules[$y][$x];

                if ($colour === $this->modules[$y][$x + 1] && $colour === $this->modules[$y + 1][$x] && $colour === $this->modules[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }

        $total = $size * $size;
        $score += ((int) ceil(abs($dark * 20 - $total * 10) / $total) - 1) * 10;

        return $score;
    }
}
