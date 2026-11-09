<?php

namespace Tests\Unit;

use App\Support\QrCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QrCodeTest extends TestCase
{
    public function test_matrix_size_follows_the_version(): void
    {
        $this->assertCount(21, QrCode::matrix('A'));
        $this->assertCount(29, QrCode::matrix('N360W-1-1-0123456789abcdef0123'));
        $this->assertCount(57, QrCode::matrix(str_repeat('Z9', 100)));
    }

    public function test_finder_patterns_sit_in_three_corners(): void
    {
        $matrix = QrCode::matrix('N360W-1-1-0123456789abcdef0123');
        $size = count($matrix);

        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$x, $y]) {
            for ($i = 0; $i < 7; $i++) {
                $this->assertTrue($matrix[$y][$x + $i] && $matrix[$y + 6][$x + $i] && $matrix[$y + $i][$x] && $matrix[$y + $i][$x + 6]);
            }

            $this->assertFalse($matrix[$y + 1][$x + 1]);
            $this->assertTrue($matrix[$y + 3][$x + 3]);
        }
    }

    public function test_the_same_text_always_gives_the_same_code(): void
    {
        $this->assertSame(QrCode::matrix('hello'), QrCode::matrix('hello'));
        $this->assertNotSame(QrCode::matrix('hello'), QrCode::matrix('hellp'));
    }

    /**
     * These three hashes were taken from codes that Apple's Vision QR decoder read back as exactly the
     * input text (versions 3, 8 and 10, so error correction, interleaving and version bits are all covered).
     * If the encoder changes, decode the new output with a real reader before updating them.
     */
    public function test_output_matches_codes_a_real_decoder_read(): void
    {
        $this->assertSame('f9d3d0d0a331361224fc48a380c46a4ef522a15f', sha1(json_encode(QrCode::matrix('N360W-1-1-0123456789abcdef0123'))));
        $this->assertSame('75a0f100414b0bf0e585becdb91b3948a69152c8', sha1(json_encode(QrCode::matrix(str_repeat('x', 150)))));
        $this->assertSame('45bb6e0f47d52f56de93a3d9f9d0533f5d80f892', sha1(json_encode(QrCode::matrix(str_repeat('Z9', 100)))));
    }

    public function test_svg_is_a_scalable_image_with_a_quiet_zone(): void
    {
        $svg = QrCode::svg('N360W-1-1-0123456789abcdef0123');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('viewBox="0 0 37 37"', $svg);
        $this->assertStringContainsString('<path', $svg);
    }

    public function test_text_that_is_too_long_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        QrCode::matrix(str_repeat('x', 400));
    }
}
