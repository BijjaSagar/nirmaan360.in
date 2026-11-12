<?php

namespace Tests\Feature\DocumentReading;

use GdImage;

/**
 * Page pictures made in code, so no photo of a real document is ever kept in the repository.
 * Every picture is white with one red corner block at the top left of the file as stored, which is
 * how a test tells which way up the result is.
 */
trait BuildsPictures
{
    protected function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, 0xFFFFFF);
        imagefilledrectangle($image, 0, 0, intdiv($width, 4), intdiv($height, 4), 0xFF0000);

        return $image;
    }

    protected function jpeg(int $width, int $height, string $extraSegments = ''): string
    {
        ob_start();
        imagejpeg($this->canvas($width, $height), null, 85);
        $jpeg = (string) ob_get_clean();

        // Metadata blocks sit between the start marker and the rest of the file.
        return "\xFF\xD8".$extraSegments.substr($jpeg, 2);
    }

    protected function png(int $width, int $height, string $extraChunks = ''): string
    {
        ob_start();
        imagepng($this->canvas($width, $height));
        $png = (string) ob_get_clean();

        // Signature (8 bytes) and the IHDR chunk (25 bytes), then any extra chunks, then the rest.
        return substr($png, 0, 33).$extraChunks.substr($png, 33);
    }

    protected function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    /** A PNG that is nothing but a header claiming a size. It can be measured and never decoded. */
    protected function pngHeaderOnly(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n"
            .$this->pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$this->pngChunk('IEND', '');
    }

    protected function jpegSegment(int $marker, string $payload): string
    {
        return "\xFF".chr($marker).pack('n', strlen($payload) + 2).$payload;
    }

    /**
     * An Exif block as a phone writes it: maker, orientation and a GPS position. The position is an
     * invented one in open sea.
     *
     * @param  string  $order  MM (big-endian) or II (little-endian)
     * @param  int  $type  How the orientation is stored: 3 SHORT as the standard says, 1 BYTE or 4 LONG
     */
    protected function exif(int $orientation, string $order = 'MM', int $type = 3, bool $withOrientation = true): string
    {
        [$short, $long] = $order === 'MM' ? ['n', 'N'] : ['v', 'V'];
        $entry = fn (int $tag, int $kind, int $count, string $value) => pack($short.$short.$long, $tag, $kind, $count).str_pad($value, 4, "\0");
        $rational = fn (int ...$parts) => implode('', array_map(fn (int $part) => pack($long.$long, $part, 1), $parts));

        $maker = "Test camera\0";
        $orientationValue = match ($type) {
            1 => chr($orientation),
            4 => pack($long, $orientation),
            default => pack($short, $orientation),
        };

        $entries = [$entry(0x010F, 2, strlen($maker), pack($long, 0))];

        if ($withOrientation) {
            $entries[] = $entry(0x0112, $type, 1, $orientationValue);
        }

        $entries[] = $entry(0x8825, 4, 1, pack($long, 0));

        // Offsets count from the start of the TIFF header: 8 bytes of header, then this directory.
        $makerAt = 8 + 2 + 12 * count($entries) + 4;
        $gpsAt = $makerAt + strlen($maker);
        $latitudeAt = $gpsAt + 2 + 12 * 4 + 4;

        $entries[0] = $entry(0x010F, 2, strlen($maker), pack($long, $makerAt));
        $entries[count($entries) - 1] = $entry(0x8825, 4, 1, pack($long, $gpsAt));

        $tiff = ($order === 'MM' ? "MM\0*" : "II*\0").pack($long, 8)
            .pack($short, count($entries)).implode('', $entries).pack($long, 0)
            .$maker
            .pack($short, 4)
            .$entry(0x0001, 2, 2, "S\0")
            .$entry(0x0002, 5, 3, pack($long, $latitudeAt))
            .$entry(0x0003, 2, 2, "W\0")
            .$entry(0x0004, 5, 3, pack($long, $latitudeAt + 24))
            .pack($long, 0)
            .$rational(40, 30, 15)
            .$rational(120, 45, 10);

        return $this->jpegSegment(0xE1, "Exif\0\0".$tiff);
    }

    /** XMP, an IPTC block and a comment, as an editing app would leave them. */
    protected function otherMetadata(): string
    {
        return $this->jpegSegment(0xE1, "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><dc:creator>Invented Person</dc:creator></x:xmpmeta>")
            .$this->jpegSegment(0xED, "Photoshop 3.0\08BIM\x04\x04\0\0\0\0\0\x10Invented caption")
            .$this->jpegSegment(0xFE, 'Scanned at the invented site office');
    }

    /** @return array{0:int,1:int,2:int} Red, green, blue of one pixel of a JPEG. */
    protected function pixel(string $jpeg, int $x, int $y): array
    {
        $colour = imagecolorat(imagecreatefromstring($jpeg), $x, $y);

        return [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF];
    }

    protected function assertRed(string $jpeg, int $x, int $y, string $where): void
    {
        [$red, $green, $blue] = $this->pixel($jpeg, $x, $y);
        $this->assertTrue($red > 200 && $green < 60 && $blue < 60, "Expected the red block at the {$where}, found rgb({$red}, {$green}, {$blue}).");
    }

    protected function assertWhite(string $jpeg, int $x, int $y, string $where): void
    {
        [$red, $green, $blue] = $this->pixel($jpeg, $x, $y);
        $this->assertTrue($red > 235 && $green > 235 && $blue > 235, "Expected white at the {$where}, found rgb({$red}, {$green}, {$blue}).");
    }
}
