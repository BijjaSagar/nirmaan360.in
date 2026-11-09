<?php

namespace App\Services\Assets;

use InvalidArgumentException;

/**
 * A small QR Code encoder (ISO/IEC 18004), so asset tags need no extra package.
 * Byte mode, error correction level M, versions 1 to 10 (up to 213 bytes). That is
 * plenty for a tag link, and level M survives the scuffs a tag takes on a site.
 */
final class QrCode
{
    /** Per version: error correction codewords per block, then [block count, data codewords per block] groups. */
    private const BLOCKS = [
        1 => [10, [[1, 16]]],
        2 => [16, [[1, 28]]],
        3 => [26, [[1, 44]]],
        4 => [18, [[2, 32]]],
        5 => [24, [[2, 43]]],
        6 => [16, [[4, 27]]],
        7 => [18, [[4, 31]]],
        8 => [22, [[2, 38], [2, 39]]],
        9 => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Format bits for error correction level M. */
    private const LEVEL_M = 0;

    private int $version;

    private int $size;

    /** @var array<int, array<int, bool>> */
    private array $modules = [];

    /** @var array<int, array<int, bool>> */
    private array $isFunction = [];

    private function __construct(private string $text)
    {
        $this->build();
    }

    public static function encode(string $text): self
    {
        return new self($text);
    }

    /** Longest text that fits. */
    public static function capacity(): int
    {
        return 213;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function size(): int
    {
        return $this->size;
    }

    /** @return array<int, array<int, bool>> Rows of modules, true for a dark one. No quiet zone. */
    public function matrix(): array
    {
        return $this->modules;
    }

    /** An SVG sized by its container, with a white quiet zone around the code. */
    public function svg(int $quietZone = 4): string
    {
        $total = $this->size + 2 * $quietZone;
        $path = '';

        foreach ($this->modules as $y => $row) {
            $x = 0;

            while ($x < $this->size) {
                if (! $row[$x]) {
                    $x++;

                    continue;
                }

                $start = $x;

                while ($x < $this->size && $row[$x]) {
                    $x++;
                }

                $path .= 'M'.($start + $quietZone).','.($y + $quietZone).'h'.($x - $start).'v1h-'.($x - $start).'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$total.' '.$total.'" shape-rendering="crispEdges" role="img">'
            .'<rect width="'.$total.'" height="'.$total.'" fill="#fff"/><path d="'.$path.'" fill="#000"/></svg>';
    }

    private function build(): void
    {
        $data = $this->dataCodewords();
        $this->size = 17 + 4 * $this->version;

        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->isFunction = $this->modules;

        $this->drawFunctionPatterns();
        $this->placeCodewords($this->interleave($data));

        $best = null;
        $bestPenalty = PHP_INT_MAX;

        foreach (range(0, 7) as $mask) {
            $this->applyMask($mask);
            $this->drawFormatBits($mask);
            $penalty = $this->penalty();

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $mask;
            }

            $this->applyMask($mask); // XOR again to undo
        }

        $this->applyMask($best);
        $this->drawFormatBits($best);
    }

    /** @return list<int> */
    private function dataCodewords(): array
    {
        $bytes = array_values(unpack('C*', $this->text) ?: []);
        $length = count($bytes);

        for ($version = 1; $version <= 10; $version++) {
            $capacityBits = $this->dataCodewordCount($version) * 8;
            $countBits = $version < 10 ? 8 : 16;

            if (4 + $countBits + 8 * $length <= $capacityBits) {
                $this->version = $version;

                break;
            }
        }

        if (! isset($this->version)) {
            throw new InvalidArgumentException('Text is too long for a QR tag.');
        }

        $capacityBits = $this->dataCodewordCount($this->version) * 8;
        $bits = [];
        $append = function (int $value, int $count) use (&$bits): void {
            for ($i = $count - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        $append(0b0100, 4);
        $append($length, $this->version < 10 ? 8 : 16);

        foreach ($bytes as $byte) {
            $append($byte, 8);
        }

        $append(0, min(4, $capacityBits - count($bits)));

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];

        foreach (array_chunk($bits, 8) as $chunk) {
            $codewords[] = bindec(implode('', $chunk));
        }

        for ($pad = 0xEC; count($codewords) < $capacityBits / 8; $pad ^= 0xEC ^ 0x11) {
            $codewords[] = $pad;
        }

        return $codewords;
    }

    private function dataCodewordCount(int $version): int
    {
        $total = 0;

        foreach (self::BLOCKS[$version][1] as [$count, $perBlock]) {
            $total += $count * $perBlock;
        }

        return $total;
    }

    /**
     * Split into blocks, add Reed-Solomon codewords to each and interleave them.
     *
     * @param  list<int>  $data
     * @return list<int>
     */
    private function interleave(array $data): array
    {
        [$eccLength, $groups] = self::BLOCKS[$this->version];
        $divisor = $this->reedSolomonDivisor($eccLength);

        $blocks = [];
        $offset = 0;

        foreach ($groups as [$count, $perBlock]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($data, $offset, $perBlock);
                $offset += $perBlock;
                $blocks[] = ['data' => $chunk, 'ecc' => $this->reedSolomonRemainder($chunk, $divisor)];
            }
        }

        $result = [];
        $longest = max(array_map(fn ($block) => count($block['data']), $blocks));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block['data'])) {
                    $result[] = $block['data'][$i];
                }
            }
        }

        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($blocks as $block) {
                $result[] = $block['ecc'][$i];
            }
        }

        return $result;
    }

    /** @return list<int> Generator polynomial coefficients, highest power first, without the leading 1. */
    private function reedSolomonDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->multiply($result[$j], $root);

                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }

            $root = $this->multiply($root, 0x02);
        }

        return $result;
    }

    /**
     * @param  list<int>  $data
     * @param  list<int>  $divisor
     * @return list<int>
     */
    private function reedSolomonRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($result);
            $result[] = 0;

            foreach ($divisor as $i => $coefficient) {
                $result[$i] ^= $this->multiply($coefficient, $factor);
            }
        }

        return $result;
    }

    /** Multiply in GF(256) with the QR polynomial x^8 + x^4 + x^3 + x^2 + 1. */
    private function multiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        foreach ([[3, 3], [$this->size - 4, 3], [3, $this->size - 4]] as [$cx, $cy]) {
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

        $centres = self::ALIGNMENT[$this->version];
        $last = count($centres) - 1;

        foreach ($centres as $i => $cx) {
            foreach ($centres as $j => $cy) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->setFunction($cx + $dx, $cy + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }

        $this->drawFormatBits(0); // reserves the area; the real mask is written later
        $this->drawVersion();
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::LEVEL_M << 3) | $mask;
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

        $this->setFunction(8, $this->size - 8, true); // the always-dark module
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

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    /** @param  list<int>  $codewords */
    private function placeCodewords(array $codewords): void
    {
        $total = count($codewords) * 8;
        $i = 0;

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
                        $this->modules[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
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
                if ($this->isFunction[$y][$x]) {
                    continue;
                }

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

                if ($invert) {
                    $this->modules[$y][$x] = ! $this->modules[$y][$x];
                }
            }
        }
    }

    /** The four penalty rules of the standard, used to pick the mask that scans best. */
    private function penalty(): int
    {
        $size = $this->size;
        $score = 0;

        $lines = $this->modules;

        for ($x = 0; $x < $size; $x++) {
            $lines[] = array_column($this->modules, $x);
        }

        foreach ($lines as $line) {
            $run = 1;

            for ($i = 1; $i <= $size; $i++) {
                if ($i < $size && $line[$i] === $line[$i - 1]) {
                    $run++;

                    continue;
                }

                if ($run >= 5) {
                    $score += 3 + $run - 5;
                }

                $run = 1;
            }

            $text = implode('', array_map(fn ($dark) => $dark ? '1' : '0', $line));
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

        $dark = 0;

        foreach ($this->modules as $row) {
            $dark += count(array_filter($row));
        }

        $total = $size * $size;
        $score += 10 * intdiv(abs($dark * 20 - $total * 10), $total);

        return $score;
    }
}
