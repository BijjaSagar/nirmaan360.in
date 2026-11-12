<?php

namespace Tests\Feature\DocumentReading;

use App\Services\DocumentReading\ExifOrientation;
use App\Services\DocumentReading\JpegSegments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExifOrientationTest extends TestCase
{
    use BuildsPictures;

    public static function orientations(): array
    {
        $rows = [];

        foreach (['MM', 'II'] as $order) {
            foreach (range(1, 8) as $orientation) {
                $rows["{$orientation} {$order}"] = [$orientation, $order];
            }
        }

        return $rows;
    }

    #[DataProvider('orientations')]
    public function test_reads_the_tag_in_either_byte_order(int $orientation, string $order): void
    {
        $jpeg = $this->jpeg(120, 80, $this->exif($orientation, $order));

        $this->assertSame($orientation, ExifOrientation::of($jpeg));

        // Where PHP's own reader is installed, it must agree that this is a real Exif block.
        if (function_exists('exif_read_data')) {
            $read = @exif_read_data('data://image/jpeg;base64,'.base64_encode($jpeg));
            $this->assertSame($orientation, (int) ($read['Orientation'] ?? 0));
            $this->assertSame('Test camera', $read['Make'] ?? null);
            $this->assertSame('S', $read['GPSLatitudeRef'] ?? null);
        }
    }

    public function test_upright_when_nothing_says_otherwise(): void
    {
        $this->assertSame(1, ExifOrientation::of($this->jpeg(120, 80)), 'no Exif block');
        $this->assertSame(1, ExifOrientation::of($this->jpeg(120, 80, $this->exif(6, 'MM', 3, withOrientation: false))), 'Exif block without the tag');
        $this->assertSame(1, ExifOrientation::of($this->png(120, 80)), 'not a JPEG');
        $this->assertSame(1, ExifOrientation::of(''), 'empty');
        $this->assertSame(1, ExifOrientation::of("\xFF\xD8"), 'only a start marker');
    }

    public function test_a_value_outside_one_to_eight_is_upright(): void
    {
        $this->assertSame(1, ExifOrientation::of($this->jpeg(120, 80, $this->exif(0))));
        $this->assertSame(1, ExifOrientation::of($this->jpeg(120, 80, $this->exif(9))));
    }

    public function test_reads_the_tag_when_a_writer_stored_it_with_the_wrong_type(): void
    {
        $this->assertSame(6, ExifOrientation::of($this->jpeg(120, 80, $this->exif(6, 'MM', 1))), 'BYTE');
        $this->assertSame(8, ExifOrientation::of($this->jpeg(120, 80, $this->exif(8, 'II', 4))), 'LONG');
    }

    public function test_finds_the_exif_block_behind_other_blocks(): void
    {
        $jpeg = $this->jpeg(120, 80, $this->otherMetadata().$this->exif(6));

        $this->assertSame(6, ExifOrientation::of($jpeg));
    }

    public function test_a_broken_block_is_upright_and_never_an_error(): void
    {
        $block = $this->exif(6);

        // Every way of cutting the block short, with the segment length kept honest.
        for ($keep = 4; $keep < strlen($block); $keep++) {
            $payload = substr($block, 4, $keep - 4);
            $orientation = ExifOrientation::of($this->jpeg(120, 80, $this->jpegSegment(0xE1, $payload)));
            $this->assertContains($orientation, [1, 6]);
        }

        // A segment that claims to be longer than the file.
        $this->assertSame(1, ExifOrientation::of("\xFF\xD8\xFF\xE1\xFF\xFFExif\0\0MM\0*"));

        // A directory offset that points past the end.
        $bad = $this->jpegSegment(0xE1, "Exif\0\0MM\0*".pack('N', 5000));
        $this->assertSame(1, ExifOrientation::of($this->jpeg(120, 80, $bad)));
    }

    public function test_lists_the_segments_before_the_picture_data(): void
    {
        $jpeg = $this->jpeg(120, 80, $this->exif(6).$this->otherMetadata());
        $read = JpegSegments::read($jpeg);
        $markers = array_column($read['segments'], 'marker');

        $this->assertSame([0xE1, 0xE1, 0xED, 0xFE, 0xE0], array_slice($markers, 0, 5));
        $this->assertNotNull($read['scan']);
        $this->assertSame("\xFF\xDA", substr($jpeg, $read['scan'], 2));
        $this->assertStringStartsWith("Exif\0\0", JpegSegments::payload($jpeg, $read['segments'][0]));

        $this->assertSame(['segments' => [], 'scan' => null], JpegSegments::read('not a picture'));
        $this->assertNull(JpegSegments::read(substr($jpeg, 0, 60))['scan']);
    }
}
