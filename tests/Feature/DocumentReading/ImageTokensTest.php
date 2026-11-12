<?php

namespace Tests\Feature\DocumentReading;

use App\Services\DocumentReading\ImageTokens;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImageTokensTest extends TestCase
{
    public function test_one_token_for_each_patch_of_28_pixels(): void
    {
        $this->assertSame(1, ImageTokens::count(1, 1));
        $this->assertSame(1, ImageTokens::count(28, 28));
        $this->assertSame(2, ImageTokens::count(29, 28));
        $this->assertSame(4897, ImageTokens::count(1628, 2303));
        $this->assertSame(4624, ImageTokens::count(1900, 1900));
        $this->assertSame(4784, ImageTokens::count(2576, 1456));
    }

    /** Page shapes in points, a phone photo, and the size each is drawn at. */
    public static function pages(): array
    {
        return [
            'A4' => [595, 842, 1622, 2296, 4756],
            'A3' => [842, 1191, 1623, 2296, 4756],
            'Legal' => [612, 1008, 1496, 2464, 4752],
            'Letter' => [612, 792, 1688, 2184, 4758],
            'photo 4:3' => [4000, 3000, 2212, 1659, 4740],
            'A4 on its side' => [842, 595, 2296, 1622, 4756],
        ];
    }

    #[DataProvider('pages')]
    public function test_largest_size_of_a_page_shape(int $w, int $h, int $fitW, int $fitH, int $tokens): void
    {
        $this->assertSame([$fitW, $fitH], ImageTokens::largestFit($w, $h));
        $this->assertSame($tokens, ImageTokens::count($fitW, $fitH));
        $this->assertTrue(ImageTokens::fits($fitW, $fitH));
    }

    public function test_the_size_returned_is_the_last_one_that_fits(): void
    {
        foreach ([[595, 842], [842, 1191], [4000, 3000], [3000, 4000], [1000, 1000], [5000, 37], [1280, 905]] as [$w, $h]) {
            [$fitW, $fitH] = ImageTokens::largestFit($w, $h);
            $this->assertTrue(ImageTokens::fits($fitW, $fitH), "{$w}x{$h} gave {$fitW}x{$fitH}, which does not fit.");

            // One pixel more along the longer side, at the same shape, no longer fits.
            [$long, $short, $fitLong] = $w >= $h ? [$w, $h, $fitW] : [$h, $w, $fitH];
            $next = $fitLong + 1;
            $nextShort = max((int) round($next / ($long / $short), 0, PHP_ROUND_HALF_EVEN), 1);
            $this->assertFalse(ImageTokens::fits($next, $nextShort), "{$w}x{$h}: a larger size of the same shape still fits.");
        }
    }

    public function test_a_shape_so_small_that_eight_times_its_size_fits_comes_back_at_eight_times(): void
    {
        $this->assertSame([8, 24], ImageTokens::largestFit(1, 3));
        $this->assertSame([1600, 800], ImageTokens::largestFit(200, 100));
    }

    /** Sizes the provider's published function gives, exact halves included (they round to the even side). */
    public static function referenceSizes(): array
    {
        return [
            'the documented A4 example at the standard limits' => [1075, 1520, 1568, 1568, 924, 1307],
            'already fits' => [1622, 2296, 2576, 4784, 1622, 2296],
            'too many tokens' => [1628, 2303, 2576, 4784, 1623, 2296],
            'side too long' => [2600, 1000, 2576, 4784, 2576, 991],
            'two to one' => [8000, 4000, 2576, 4784, 2576, 1288],
            'tall strip' => [100, 9000, 2576, 4784, 29, 2576],
            'single row' => [50000, 1, 2576, 4784, 2576, 1],
            'an exact half goes to the even side' => [2000, 1000, 2576, 4, 57, 28],
            'the same, standing' => [1000, 2000, 2576, 4, 28, 57],
        ];
    }

    #[DataProvider('referenceSizes')]
    public function test_matches_the_reference_function(int $w, int $h, int $maxEdge, int $maxTokens, int $fitW, int $fitH): void
    {
        $this->assertSame([$fitW, $fitH], ImageTokens::resized($w, $h, $maxEdge, $maxTokens));
    }

    public function test_fits_checks_both_sides_and_the_token_count(): void
    {
        $this->assertTrue(ImageTokens::fits(2576, 1456));
        $this->assertFalse(ImageTokens::fits(2577, 100));
        $this->assertFalse(ImageTokens::fits(100, 2577));
        $this->assertFalse(ImageTokens::fits(1628, 2303));
        $this->assertFalse(ImageTokens::fits(0, 100));
    }

    public function test_a_size_of_nothing_is_a_mistake_in_the_caller(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImageTokens::largestFit(0, 842);
    }
}
