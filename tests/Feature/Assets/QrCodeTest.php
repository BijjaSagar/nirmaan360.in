<?php

namespace Tests\Feature\Assets;

use App\Services\Assets\QrCode;
use InvalidArgumentException;
use Tests\TestCase;

class QrCodeTest extends TestCase
{
    /**
     * Fingerprints of codes that were decoded back to their exact text with the CoreImage QR detector
     * (macOS), along with 13 other payloads from versions 1 to 10. If the encoder changes, decode a
     * printed sample before updating these.
     */
    public function test_known_codes_do_not_change(): void
    {
        foreach ([
            'HELLO WORLD' => ['v' => 1, 'md5' => '76d5b8bc80a0951fa59813fe6593ef82'],
            'https://nirmaan360.in/a/k3x9q2m7ab' => ['v' => 3, 'md5' => '4220f0145c5efc2209d31bc6c925a094'],
            str_repeat('xyz', 71) => ['v' => 10, 'md5' => '9bfe13cd793f3b74c9e5ee5c113e31c0'],
        ] as $text => $expected) {
            $qr = QrCode::encode($text);

            $this->assertSame($expected['v'], $qr->version());
            $this->assertSame($expected['md5'], md5(implode("\n", array_map(fn ($row) => implode('', array_map(fn ($dark) => $dark ? '1' : '0', $row)), $qr->matrix()))));
        }
    }

    public function test_the_smallest_version_that_fits_is_used(): void
    {
        $this->assertSame(1, QrCode::encode(str_repeat('a', 14))->version());
        $this->assertSame(2, QrCode::encode(str_repeat('a', 15))->version());
        $this->assertSame(9, QrCode::encode(str_repeat('a', 180))->version());
        $this->assertSame(10, QrCode::encode(str_repeat('a', 181))->version());
        $this->assertSame(10, QrCode::encode(str_repeat('a', QrCode::capacity()))->version());
    }

    public function test_text_longer_than_the_capacity_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrCode::encode(str_repeat('a', QrCode::capacity() + 1));
    }

    public function test_function_patterns_are_in_place(): void
    {
        $qr = QrCode::encode('https://nirmaan360.in/a/k3x9q2m7ab');
        $m = $qr->matrix();
        $n = $qr->size();

        $this->assertSame(17 + 4 * $qr->version(), $n);

        // Three finder patterns: a dark ring, a light ring, a dark 3 x 3 centre.
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$row, $col]) {
            $this->assertTrue($m[$row][$col] && $m[$row][$col + 6] && $m[$row + 6][$col] && $m[$row + 6][$col + 6]);
            $this->assertFalse($m[$row + 1][$col + 1] || $m[$row + 1][$col + 5] || $m[$row + 5][$col + 1] || $m[$row + 5][$col + 5]);
            $this->assertTrue($m[$row + 3][$col + 3] && $m[$row + 2][$col + 2] && $m[$row + 4][$col + 4]);
        }

        // Timing patterns alternate between the finders, and the dark module is fixed.
        for ($i = 8; $i < $n - 8; $i++) {
            $this->assertSame($i % 2 === 0, $m[6][$i]);
            $this->assertSame($i % 2 === 0, $m[$i][6]);
        }

        $this->assertTrue($m[$n - 8][8]);
    }

    public function test_format_information_is_a_valid_error_corrected_word_for_level_m(): void
    {
        $qr = QrCode::encode('Plant register tag');
        $m = $qr->matrix();
        $n = $qr->size();

        $first = 0;
        $positions = [];

        for ($i = 0; $i <= 5; $i++) {
            $positions[$i] = [8, $i];
        }

        $positions[6] = [8, 7];
        $positions[7] = [8, 8];
        $positions[8] = [7, 8];

        for ($i = 9; $i < 15; $i++) {
            $positions[$i] = [14 - $i, 8];
        }

        $second = [];

        for ($i = 0; $i < 8; $i++) {
            $second[$i] = [$n - 1 - $i, 8];
        }

        for ($i = 8; $i < 15; $i++) {
            $second[$i] = [8, $n - 15 + $i];
        }

        $secondBits = 0;

        foreach ($positions as $i => [$x, $y]) {
            $first |= ($m[$y][$x] ? 1 : 0) << $i;
        }

        foreach ($second as $i => [$x, $y]) {
            $secondBits |= ($m[$y][$x] ? 1 : 0) << $i;
        }

        $this->assertSame($first, $secondBits, 'Both copies of the format word match.');

        $word = $first ^ 0x5412;
        $data = $word >> 10;
        $remainder = $data;

        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ (($remainder >> 9) * 0x537);
        }

        $this->assertSame($word, ($data << 10) | $remainder, 'The BCH check bits are right.');
        $this->assertSame(0, $data >> 3, 'Error correction level M.');
    }

    public function test_svg_is_self_contained_and_sized_by_its_container(): void
    {
        $svg = QrCode::encode('x')->svg();

        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 29 29"', $svg);
        $this->assertStringNotContainsString('width="29cm"', $svg);
        $this->assertStringContainsString('<path d="M', $svg);
        $this->assertStringContainsString('viewBox="0 0 25 25"', QrCode::encode('x')->svg(2));
    }
}
