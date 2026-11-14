<?php

namespace App\Services\TenderReading;

use App\Services\Reports\BoqPdfReader;
use App\Services\Reports\Memory;
use App\Services\Reports\PdfTable;
use App\Services\Reports\PdfTextLayer;
use finfo;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Takes the text out of a tender PDF, page by page, as lines in reading order, and says of each page
 * whether its text can be trusted as what the page shows (PdfTextLayer): text a scanner laid over the
 * picture of a page, and text drawn in an old non-Unicode Marathi or Hindi font, cannot.
 *
 * The file is untrusted. It is checked by content, not by its name; size, pages, pieces of text and
 * time are capped, every compressed stream is capped when unpacked, and any failure inside the parser
 * is an ordinary refusal. The parser set-up and the limits are those of the BOQ text import.
 */
class TenderPdfText
{
    public const MACHINE_TEXT = 'machine_text';

    public const OLD_FONT = 'old_font';

    public const NO_TEXT = 'no_text';

    /** Pieces of text over the whole file, so a pathological file cannot run on. */
    private const MAX_PIECES = 200000;

    private const DECODE_LIMIT_BYTES = 64 * 1024 * 1024;

    private const SHOW_TEXT = ['Tj', 'TJ', "'", '"'];

    public function __construct(private PdfTextLayer $layer = new PdfTextLayer) {}

    /**
     * Whether these bytes can be read as a text PDF at all: the content says PDF, it starts as a PDF
     * does, and it is not too large. A file named .pdf that is something else is refused here.
     *
     * @throws TenderTextRefused not_a_pdf or too_large
     */
    public static function assertPdf(string $bytes): void
    {
        if ((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'application/pdf' || ! str_starts_with($bytes, '%PDF-')) {
            throw new TenderTextRefused(TenderTextRefused::NOT_A_PDF);
        }

        if (strlen($bytes) > TenderFields::setting('max_bytes', 10 * 1024 * 1024)) {
            throw new TenderTextRefused(TenderTextRefused::TOO_LARGE, ['mb' => intdiv(TenderFields::setting('max_bytes', 10 * 1024 * 1024), 1024 * 1024)]);
        }
    }

    /**
     * @return list<array{text: string, chars: int, flags: list<string>, letters: int, devanagari: int}> By page, from page 1.
     *
     * @throws TenderTextRefused
     */
    public function read(string $path): array
    {
        $bytes = is_file($path) ? (string) file_get_contents($path) : '';
        self::assertPdf($bytes);
        unset($bytes);

        Memory::ensure();
        $deadline = hrtime(true) + TenderFields::setting('prepare_seconds', 60) * 1_000_000_000;

        try {
            $config = new Config;
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(self::DECODE_LIMIT_BYTES);
            $config->setMaxNestingDepth(256);
            $config->setDataTmFontInfoHasToBeIncluded(true);

            $pages = (new Parser([], $config))->parseFile($path)->getPages();
        } catch (Throwable) {
            throw new TenderTextRefused(TenderTextRefused::UNREADABLE);
        }

        if ($pages === []) {
            throw new TenderTextRefused(TenderTextRefused::UNREADABLE);
        }

        $most = TenderFields::setting('max_pages', 50);

        if (count($pages) > $most) {
            throw new TenderTextRefused(TenderTextRefused::TOO_MANY_PAGES, ['pages' => count($pages), 'max' => $most]);
        }

        $read = [];
        $pieces = 0;

        foreach (array_values($pages) as $page) {
            if (hrtime(true) > $deadline) {
                throw new TenderTextRefused(TenderTextRefused::TOO_SLOW);
            }

            try {
                $tokens = BoqPdfReader::tokens($page);
                $layer = $this->layer->inspect($page, $tokens);
                $text = $this->lines($page, $layer['legacy_fonts'], $layer['shown_in']);
            } catch (Throwable) {
                throw new TenderTextRefused(TenderTextRefused::UNREADABLE);
            }

            unset($tokens);
            $pieces += $text['pieces'];

            if ($pieces > self::MAX_PIECES) {
                throw new TenderTextRefused(TenderTextRefused::TOO_SLOW);
            }

            $joined = implode("\n", $text['lines']);
            $chars = preg_match_all('/[\p{L}\p{N}]/u', $joined);
            $flags = [];

            if ($layer['invisible']) {
                $flags[] = self::MACHINE_TEXT;
            }

            if ($text['all_chars'] > 0 && $text['old_chars'] * 100 >= $text['all_chars'] * TenderFields::setting('legacy_share', 20)) {
                $flags[] = self::OLD_FONT;
            }

            if ($chars < TenderFields::setting('min_page_chars', 20)) {
                $flags[] = self::NO_TEXT;
            }

            $read[] = [
                'text' => $joined,
                'chars' => mb_strlen($joined),
                'flags' => $flags,
                'letters' => (int) preg_match_all('/\p{L}/u', $joined),
                'devanagari' => (int) preg_match_all('/(?=\p{L})\p{Devanagari}/u', $joined),
            ];
        }

        return $read;
    }

    /**
     * The page's text as lines, top to bottom and left to right, and how much of it an old font drew.
     *
     * pdfparser gives one piece for each operator that shows text, in the order of the stream, so the
     * n-th piece is drawn in the font PdfTextLayer found in force for the n-th such operator. When the
     * two do not count the same operators, which piece is which cannot be told, and on a page where an
     * old font draws text every piece is taken as drawn in one, as the BOQ import does.
     *
     * @param  list<string>  $oldFonts
     * @param  list<string|null>  $shownIn
     * @return array{lines: list<string>, pieces: int, old_chars: int, all_chars: int}
     */
    private function lines(Page $page, array $oldFonts, array $shownIn): array
    {
        $commands = $page->getDataCommands();
        $raw = array_values($page->getDataTm($commands));
        $shows = 0;

        foreach ($commands as $command) {
            $shows += in_array($command['o'] ?? null, self::SHOW_TEXT, true) ? 1 : 0;
        }

        $paired = count($shownIn) === $shows && count($raw) === $shows;
        $wholePage = ! $paired && $oldFonts !== [];
        $oldFonts = array_fill_keys($oldFonts, true);
        $pieces = [];
        $rotated = 0;
        $oldChars = 0;
        $allChars = 0;
        $lastSize = 10.0;

        foreach ($raw as $n => $item) {
            $tm = $item[0] ?? null;
            $text = trim((string) preg_replace('/\s+/u', ' ', (string) ($item[1] ?? '')));

            if (! is_array($tm) || count($tm) < 6 || $text === '') {
                continue;
            }

            [$a, $b, , , $x, $y] = array_map('floatval', array_slice($tm, 0, 6));

            if (! is_finite($x) || ! is_finite($y) || abs($x) > 50000 || abs($y) > 50000) {
                continue;
            }

            $scale = sqrt($a * $a + $b * $b) ?: 1.0;
            $size = isset($item[3]) ? abs((float) $item[3]) * $scale : $lastSize;
            $size = $size >= 1 && $size <= 200 && is_finite($size) ? $size : $lastSize;
            $lastSize = $size;

            if (abs($b) > abs($a)) {
                $rotated += $b > 0 ? 1 : -1;
            }

            $length = mb_strlen($text);
            $font = $paired ? ($shownIn[$n] ?? null) : null;
            $old = $wholePage || ($font !== null && isset($oldFonts[$font]));
            $oldChars += $old ? $length : 0;
            $allChars += $length;
            $pieces[] = ['x' => $x, 'y' => $y, 'size' => $size, 'text' => $text];
        }

        // A landscape page drawn sideways reads along x. Turn it so lines run left to right again.
        $rotation = abs($rotated) * 2 > count($pieces) ? ($rotated > 0 ? 1 : -1) : 0;

        foreach ($pieces as &$piece) {
            [$piece['x'], $piece['y']] = match ($rotation) {
                1 => [$piece['y'], -$piece['x']],
                -1 => [-$piece['y'], $piece['x']],
                default => [$piece['x'], $piece['y']],
            };
        }
        unset($piece);

        usort($pieces, fn (array $one, array $two) => [$two['y'], $one['x']] <=> [$one['y'], $two['x']]);

        $lines = [];
        $current = [];
        $lineY = null;
        $lineSize = 10.0;

        foreach ($pieces as $piece) {
            if ($lineY !== null && abs($piece['y'] - $lineY) > max(2.0, 0.45 * min($lineSize, $piece['size']))) {
                $lines[] = $this->join($current);
                $current = [];
            }

            if ($current === []) {
                $lineY = $piece['y'];
                $lineSize = $piece['size'];
            }

            $current[] = $piece;
        }

        if ($current !== []) {
            $lines[] = $this->join($current);
        }

        return ['lines' => array_values(array_filter($lines, fn (string $line) => $line !== '')), 'pieces' => count($pieces), 'old_chars' => $oldChars, 'all_chars' => $allChars];
    }

    /** @param  list<array{x: float, y: float, size: float, text: string}>  $pieces */
    private function join(array $pieces): string
    {
        usort($pieces, fn (array $one, array $two) => $one['x'] <=> $two['x']);

        $line = '';
        $end = null;

        foreach ($pieces as $piece) {
            // Pieces that touch belong to one word; a gap of a fifth of the letter size or more is a space.
            $line .= $end !== null && 0.2 * $piece['size'] > $piece['x'] - $end ? $piece['text'] : ($line === '' ? '' : ' ').$piece['text'];
            $end = $piece['x'] + PdfTable::width($piece['text'], $piece['size']);
        }

        return trim($line);
    }
}
