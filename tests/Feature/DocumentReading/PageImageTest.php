<?php

namespace Tests\Feature\DocumentReading;

use App\Services\DocumentReading\ImageRefused;
use App\Services\DocumentReading\ImageTokens;
use App\Services\DocumentReading\JpegSegments;
use App\Services\DocumentReading\PageImage;
use App\Services\DocumentReading\ReaderUnavailable;
use finfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PageImageTest extends TestCase
{
    use BuildsPictures;

    private function refusal(string $bytes, int $minShortSide = 100): ImageRefused
    {
        try {
            PageImage::normalise($bytes, $minShortSide);
        } catch (ImageRefused $refused) {
            return $refused;
        }

        $this->fail('The picture was accepted.');
    }

    /** Nothing but the plain JFIF header and the tables a decoder needs may stand before the picture data. */
    private function assertNoMetadata(string $jpeg): void
    {
        $read = JpegSegments::read($jpeg);
        $this->assertNotNull($read['scan']);

        foreach ($read['segments'] as $segment) {
            $this->assertContains($segment['marker'], [0xE0, 0xDB, 0xC0, 0xC4, 0xDD], sprintf('Segment 0x%02X is in the stored file.', $segment['marker']));

            if ($segment['marker'] === 0xE0) {
                $this->assertStringStartsWith("JFIF\0", JpegSegments::payload($jpeg, $segment));
            }
        }

        foreach (['Exif', 'xmpmeta', 'Photoshop', 'Test camera', 'Invented', 'CREATOR', 'tEXt', 'http://'] as $trace) {
            $this->assertStringNotContainsString($trace, $jpeg);
        }
    }

    public function test_a_phone_photo_held_sideways_is_stored_upright_without_its_metadata(): void
    {
        $photo = $this->jpeg(1200, 800, $this->exif(6).$this->otherMetadata());
        $stored = getimagesizefromstring($photo);
        $this->assertStringContainsString('Exif', $photo);

        $page = PageImage::normalise($photo, 600);

        // Width and height are swapped against the file as the sensor wrote it.
        $this->assertSame([$stored[1], $stored[0]], [$page->width, $page->height]);
        $this->assertSame([800, 1200], array_slice(getimagesizefromstring($page->bytes), 0, 2));
        $this->assertSame('image/jpeg', (new finfo(FILEINFO_MIME_TYPE))->buffer($page->bytes));

        // Orientation 6: the first row of the file is the right-hand side of the scene.
        $this->assertRed($page->bytes, 750, 50, 'top right');
        $this->assertWhite($page->bytes, 50, 50, 'top left');
        $this->assertWhite($page->bytes, 50, 1150, 'bottom left');
        $this->assertWhite($page->bytes, 750, 1150, 'bottom right');

        $this->assertNoMetadata($page->bytes);
        $this->assertSame(ImageTokens::count(800, 1200), $page->tokens);
        $this->assertSame(hash('sha256', $page->bytes), $page->sha256);
    }

    public static function turns(): array
    {
        return [
            'upright' => [1, 1200, 800, [50, 50]],
            'upside down' => [3, 1200, 800, [1150, 750]],
            'quarter turn' => [6, 800, 1200, [750, 50]],
            'quarter turn the other way' => [8, 800, 1200, [50, 1150]],
        ];
    }

    #[DataProvider('turns')]
    public function test_each_orientation_ends_upright(int $orientation, int $width, int $height, array $redAt): void
    {
        $page = PageImage::normalise($this->jpeg(1200, 800, $this->exif($orientation, 'II')), 600);

        $this->assertSame([$width, $height], [$page->width, $page->height]);
        $this->assertRed($page->bytes, $redAt[0], $redAt[1], "place orientation {$orientation} puts the block");

        foreach ([[50, 50], [$width - 50, 50], [50, $height - 50], [$width - 50, $height - 50]] as $corner) {
            if ($corner !== $redAt) {
                $this->assertWhite($page->bytes, $corner[0], $corner[1], 'other corners');
            }
        }

        $this->assertNoMetadata($page->bytes);
    }

    #[DataProvider('mirrored')]
    public function test_a_mirrored_photo_is_refused(int $orientation): void
    {
        $refused = $this->refusal($this->jpeg(1200, 800, $this->exif($orientation)));

        $this->assertSame(ImageRefused::IMAGE_MIRRORED, $refused->reason);
        $this->assertSame(['code' => 'image_mirrored'], $refused->toArray());
    }

    public static function mirrored(): array
    {
        return [[2], [4], [5], [7]];
    }

    public function test_a_png_comes_out_as_a_jpeg_without_its_text_chunk(): void
    {
        $png = $this->png(900, 700, $this->pngChunk('tEXt', "Comment\0Invented note from the scanner"));
        $this->assertStringContainsString('Invented note', $png);
        $this->assertSame('image/png', (new finfo(FILEINFO_MIME_TYPE))->buffer($png));

        $page = PageImage::normalise($png, 600);

        $this->assertSame('image/jpeg', (new finfo(FILEINFO_MIME_TYPE))->buffer($page->bytes));
        $this->assertSame([900, 700], [$page->width, $page->height]);
        $this->assertRed($page->bytes, 50, 50, 'top left');
        $this->assertNoMetadata($page->bytes);
    }

    public function test_a_clear_background_becomes_white(): void
    {
        $image = imagecreatetruecolor(700, 700);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, 699, 699, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 0, 0, 200, 200, imagecolorallocatealpha($image, 255, 0, 0, 0));
        ob_start();
        imagepng($image);
        $page = PageImage::normalise((string) ob_get_clean(), 600);

        $this->assertRed($page->bytes, 50, 50, 'top left');
        $this->assertWhite($page->bytes, 600, 600, 'clear part');

        // The same for a palette picture with one colour set as clear.
        $palette = imagecreate(700, 700);
        $clear = imagecolorallocate($palette, 0, 0, 0);
        imagecolortransparent($palette, $clear);
        imagefilledrectangle($palette, 0, 0, 699, 699, $clear);
        imagefilledrectangle($palette, 0, 0, 200, 200, imagecolorallocate($palette, 255, 0, 0));
        ob_start();
        imagepng($palette);
        $page = PageImage::normalise((string) ob_get_clean(), 600);

        $this->assertRed($page->bytes, 50, 50, 'top left');
        $this->assertWhite($page->bytes, 600, 600, 'clear part');
    }

    public function test_a_webp_comes_out_as_a_jpeg(): void
    {
        if ((imagetypes() & IMG_WEBP) === 0) {
            $this->markTestSkipped('This build of GD cannot write WebP.');
        }

        ob_start();
        imagewebp($this->canvas(900, 700), null, 80);
        $page = PageImage::normalise((string) ob_get_clean(), 600);

        $this->assertSame('image/jpeg', (new finfo(FILEINFO_MIME_TYPE))->buffer($page->bytes));
        $this->assertSame([900, 700], [$page->width, $page->height]);
        $this->assertRed($page->bytes, 50, 50, 'top left');
        $this->assertNoMetadata($page->bytes);
    }

    public function test_an_a4_page_at_its_largest_size_is_taken_as_it_is(): void
    {
        [$width, $height] = ImageTokens::largestFit(595, 842);
        $page = PageImage::normalise($this->jpeg($width, $height), 1000);

        $this->assertSame([1622, 2296, 4756], [$page->width, $page->height, $page->tokens]);
        $this->assertLessThanOrEqual(PageImage::MAX_BYTES, strlen($page->bytes));
    }

    public static function notPictures(): array
    {
        return [
            'plain text' => ['Quantity 12.500 cum'],
            'empty' => [''],
            'a PDF' => ["%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n"],
            'an SVG' => ['<svg xmlns="http://www.w3.org/2000/svg" width="900" height="700"><rect width="900" height="700"/></svg>'],
            'only a JPEG start marker' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF\x00"],
        ];
    }

    #[DataProvider('notPictures')]
    public function test_anything_that_is_not_a_picture_is_refused(string $bytes): void
    {
        $this->assertSame(ImageRefused::NOT_AN_IMAGE, $this->refusal($bytes)->reason);
    }

    public function test_a_gif_is_not_one_of_the_kinds_taken(): void
    {
        ob_start();
        imagegif($this->canvas(900, 700));

        $this->assertSame(ImageRefused::NOT_AN_IMAGE, $this->refusal((string) ob_get_clean())->reason);
    }

    public function test_a_picture_cut_short_in_transit_is_refused(): void
    {
        $jpeg = $this->jpeg(900, 700);
        $png = $this->png(900, 700);

        // GD would fill the missing part of the JPEG with grey and return a picture.
        $this->assertNotFalse(@imagecreatefromstring(substr($jpeg, 0, intdiv(strlen($jpeg) * 6, 10))));

        $this->assertSame(ImageRefused::NOT_AN_IMAGE, $this->refusal(substr($jpeg, 0, intdiv(strlen($jpeg) * 6, 10)))->reason);
        $this->assertSame(ImageRefused::NOT_AN_IMAGE, $this->refusal(substr($jpeg, 0, -2))->reason);
        $this->assertSame(ImageRefused::NOT_AN_IMAGE, $this->refusal(substr($png, 0, intdiv(strlen($png) * 6, 10)))->reason);

        // Bytes after the end of the picture, as some cameras add, do no harm.
        $this->assertSame(900, PageImage::normalise($jpeg.str_repeat("\0", 64).'trailer', 600)->width);
    }

    public function test_a_side_longer_than_the_limit_is_refused_with_the_size_that_fits(): void
    {
        $refused = $this->refusal($this->jpeg(2600, 1000));

        $this->assertSame(ImageRefused::IMAGE_TOO_BIG, $refused->reason);
        $this->assertSame([
            'code' => 'image_too_big',
            'max_tokens' => 4784,
            'max_edge' => 2576,
            'width' => 2600,
            'height' => 1000,
            'fits' => ['width' => 2576, 'height' => 991],
        ], $refused->toArray());
        $this->assertTrue(ImageTokens::fits(2576, 991));
    }

    public function test_too_many_tokens_is_refused_even_when_both_sides_are_short_enough(): void
    {
        // The size the spec names as just over the limit: 4,897 tokens.
        $refused = $this->refusal($this->jpeg(1628, 2303));

        $this->assertSame(ImageRefused::IMAGE_TOO_BIG, $refused->reason);
        $this->assertSame(['width' => 1623, 'height' => 2296], $refused->details['fits']);
    }

    public function test_the_limit_applies_to_the_upright_picture(): void
    {
        // 1000 x 2600 as stored, lying on its side: upright it is 2600 wide.
        $refused = $this->refusal($this->jpeg(1000, 2600, $this->exif(6)));

        $this->assertSame(ImageRefused::IMAGE_TOO_BIG, $refused->reason);
        $this->assertSame([2600, 1000], [$refused->details['width'], $refused->details['height']]);
        $this->assertSame(['width' => 2576, 'height' => 991], $refused->details['fits']);
    }

    public function test_a_huge_picture_is_refused_from_its_header_before_it_is_decoded(): void
    {
        // A header that claims 15 million pixels with no picture behind it. Decoding it would fail
        // with not_an_image, so this answer can only have come from the header.
        $refused = $this->refusal($this->pngHeaderOnly(5000, 3000));

        $this->assertSame(ImageRefused::IMAGE_TOO_BIG, $refused->reason);
        $this->assertSame([5000, 3000], [$refused->details['width'], $refused->details['height']]);

        $this->assertSame(ImageRefused::IMAGE_TOO_BIG, $this->refusal($this->pngHeaderOnly(2600, 1000))->reason);
        $this->assertSame(ImageRefused::NOT_AN_IMAGE, $this->refusal($this->pngHeaderOnly(900, 700))->reason);
    }

    public function test_a_picture_too_small_to_read_is_refused(): void
    {
        $refused = $this->refusal($this->jpeg(900, 500), 600);

        $this->assertSame(['code' => 'image_too_small', 'min_short_side' => 600, 'width' => 900, 'height' => 500], $refused->toArray());

        $this->assertSame(900, PageImage::normalise($this->jpeg(900, 600), 600)->width);
        $this->assertSame(ImageRefused::IMAGE_TOO_SMALL, $this->refusal($this->jpeg(599, 900, $this->exif(8)), 600)->reason);
    }

    public function test_a_result_over_four_megabytes_is_refused(): void
    {
        // Noise does not compress. 2576 x 1456 is exactly at the token limit.
        mt_srand(11);
        $tile = imagecreatetruecolor(256, 256);

        for ($y = 0; $y < 256; $y++) {
            for ($x = 0; $x < 256; $x++) {
                imagesetpixel($tile, $x, $y, mt_rand(0, 0xFFFFFF));
            }
        }

        $noise = imagecreatetruecolor(2576, 1456);

        for ($y = 0; $y < 1456; $y += 256) {
            for ($x = 0; $x < 2576; $x += 256) {
                imagecopy($noise, $tile, $x, $y, 0, 0, 256, 256);
            }
        }

        ob_start();
        imagepng($noise, null, 1);
        $refused = $this->refusal((string) ob_get_clean());

        $this->assertSame(['code' => 'file_too_big', 'max_bytes' => 4194304], $refused->toArray());
    }

    public function test_a_server_without_the_image_functions_stores_nothing(): void
    {
        $server = new class extends PageImage
        {
            protected static function canRedraw(string $type): bool
            {
                return false;
            }
        };

        try {
            $server::normalise($this->jpeg(900, 700), 600);
            $this->fail('The picture was accepted.');
        } catch (ReaderUnavailable $unavailable) {
            $this->assertSame('image_tools_missing', $unavailable->reason);
        }

        // What is not a picture is still told so, whatever the server can do.
        try {
            $server::normalise('Quantity 12.500 cum', 600);
            $this->fail('Text was accepted.');
        } catch (ImageRefused $refused) {
            $this->assertSame(ImageRefused::NOT_AN_IMAGE, $refused->reason);
        }
    }

    public function test_the_same_picture_gives_the_same_bytes(): void
    {
        $photo = $this->jpeg(1200, 800, $this->exif(6));

        $this->assertSame(PageImage::normalise($photo, 600)->sha256, PageImage::normalise($photo, 600)->sha256);
    }
}
