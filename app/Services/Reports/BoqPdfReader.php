<?php

namespace App\Services\Reports;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads the BOQ or estimate table out of a PDF that has a text layer.
 *
 * A PDF holds no cells, only pieces of text at positions and the lines ruled around them.
 * This class pulls both out of each page with smalot/pdfparser, pure PHP, and PdfTable puts
 * the table back together. The result is the same grid an Excel sheet gives, so the same
 * checks run on it. A scanned PDF is an image and has no text to read, so it is refused.
 *
 * The file is untrusted: it is capped in size, pages and text, every stream is capped when
 * unpacked, the time spent is bounded, and any failure inside the parser is an ordinary error.
 */
final class BoqPdfReader
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_PAGES = 50;

    /** Pieces of text over the whole file. A fifty-page estimate has a few thousand. */
    private const MAX_PIECES = 80000;

    /** Ruled line segments kept per page. */
    private const MAX_SEGMENTS = 40000;

    /** Content streams longer than this are read for text only; their rules are skipped. */
    private const MAX_STREAM_BYTES = 6 * 1024 * 1024;

    private const TIME_BUDGET_SECONDS = 30;

    /** What a single compressed stream may unpack to. */
    private const DECODE_LIMIT_BYTES = 64 * 1024 * 1024;

    /** Tokens of a content stream: strings, hex strings, names, delimiters, comments, numbers and operators. */
    private const TOKEN = '/\((?:[^()\\\\]|\\\\.|\((?:[^()\\\\]|\\\\.)*\))*\)|<[0-9A-Fa-f\s]*>|<<|>>|[\[\]{}]|\/[^\s\/\[\]()<>{}%]*|%[^\r\n]*|[+-]?(?:\d+\.?\d*|\.\d+)|[A-Za-z\'"*][A-Za-z0-9\'"*]*/';

    private const PAINT = ['S', 's', 'f', 'F', 'f*', 'B', 'B*', 'b', 'b*'];

    public function read(string $path): BoqPdfRead
    {
        $size = is_file($path) ? filesize($path) : false;

        if ($size === false || $size === 0) {
            return BoqPdfRead::failed($this->unreadable());
        }

        if ($size > self::MAX_BYTES) {
            return BoqPdfRead::failed(__('The PDF is larger than :max MB. Export fewer pages or upload the Excel file instead.', ['max' => self::MAX_BYTES / 1024 / 1024]));
        }

        if (! str_contains((string) file_get_contents($path, false, null, 0, 1024), '%PDF')) {
            return BoqPdfRead::failed($this->unreadable());
        }

        Memory::ensure();
        @set_time_limit(120);
        $deadline = hrtime(true) + self::TIME_BUDGET_SECONDS * 1_000_000_000;

        try {
            $config = new Config;
            $config->setRetainImageContent(false);
            $config->setDecodeMemoryLimit(self::DECODE_LIMIT_BYTES);
            $config->setMaxNestingDepth(256);
            $config->setDataTmFontInfoHasToBeIncluded(true);

            $document = (new Parser([], $config))->parseFile($path);
            $pages = $document->getPages();
        } catch (Throwable) {
            return BoqPdfRead::failed($this->unreadable());
        }

        if ($pages === []) {
            return BoqPdfRead::failed($this->unreadable());
        }

        if (count($pages) > self::MAX_PAGES) {
            return BoqPdfRead::failed(__('The PDF has :count pages. Up to :max pages can be read; split it or upload the Excel file instead.', ['count' => count($pages), 'max' => self::MAX_PAGES]));
        }

        $read = [];
        $pieces = 0;

        foreach ($pages as $page) {
            if (hrtime(true) > $deadline) {
                return BoqPdfRead::failed($this->slow());
            }

            try {
                $text = $this->pieces($page);
                $rules = $this->rules($page, $text['rotation']);
            } catch (Throwable) {
                return BoqPdfRead::failed($this->unreadable());
            }

            $pieces += count($text['pieces']);

            if ($pieces > self::MAX_PIECES) {
                return BoqPdfRead::failed($this->slow());
            }

            $read[] = ['pieces' => $text['pieces'], 'h' => $rules['h'], 'v' => $rules['v']];
        }

        // A scan carries no text at all, or only a page number or a stamp on each page.
        if ($pieces <= 2 * count($read)) {
            return BoqPdfRead::failed(__('This PDF has no text in it, so it is a scanned copy or a picture. Its figures cannot be read. Please upload the Excel file of the estimate instead.'), scanned: true);
        }

        unset($document, $pages);
        gc_collect_cycles();

        try {
            $grid = (new PdfTable($read))->grid();
        } catch (Throwable) {
            return BoqPdfRead::failed($this->unreadable());
        }

        if ($grid === []) {
            return BoqPdfRead::failed(__('Could not find the heading row in the PDF. The table needs columns such as Sr. No., Description, Qty, Rate, Unit and Amount, or the columns of the Excel template.'));
        }

        return new BoqPdfRead($grid, count($read));
    }

    /**
     * Every piece of text on the page with where it sits, its size and an estimate of its width.
     *
     * @return array{pieces: list<array{x: float, x1: float, y: float, size: float, text: string}>, rotation: int}
     */
    private function pieces(Page $page): array
    {
        $raw = $page->getDataTm();
        $pieces = [];
        $rotated = 0;
        $lastSize = 10.0;

        foreach ($raw as $item) {
            $tm = $item[0] ?? null;
            $text = trim((string) ($item[1] ?? ''));

            if (! is_array($tm) || count($tm) < 6 || $text === '') {
                continue;
            }

            [$a, $b, $c, $d, $x, $y] = array_map('floatval', array_slice($tm, 0, 6));

            if (! is_finite($x) || ! is_finite($y) || abs($x) > 50000 || abs($y) > 50000) {
                continue;
            }

            $scale = sqrt($a * $a + $b * $b) ?: 1.0;
            $size = isset($item[3]) ? abs((float) $item[3]) * $scale : $lastSize;

            if ($size < 1 || $size > 200 || ! is_finite($size)) {
                $size = $lastSize;
            }

            $lastSize = $size;

            if (abs($b) > abs($a)) {
                $rotated += $b > 0 ? 1 : -1;
            }

            $pieces[] = ['x' => $x, 'y' => $y, 'size' => $size, 'text' => preg_replace('/\s+/u', ' ', $text) ?? $text];
        }

        // A landscape page drawn sideways reads top to bottom along x. Turn it so that y runs down the table again.
        $rotation = abs($rotated) * 2 > count($pieces) ? ($rotated > 0 ? 1 : -1) : 0;

        foreach ($pieces as &$piece) {
            [$piece['x'], $piece['y']] = $this->turn($piece['x'], $piece['y'], $rotation);
            $piece['x1'] = $piece['x'] + PdfTable::width($piece['text'], $piece['size']);
        }

        return ['pieces' => $pieces, 'rotation' => $rotation];
    }

    /** @return array{0: float, 1: float} */
    private function turn(float $x, float $y, int $rotation): array
    {
        return match ($rotation) {
            1 => [$y, -$x],
            -1 => [-$y, $x],
            default => [$x, $y],
        };
    }

    /**
     * The straight horizontal and vertical lines drawn on the page: the rules of a table.
     *
     * pdfparser keeps only text operators, so the content stream is walked here for path
     * operators, with the transformation matrix applied the way a viewer would. A path that is
     * only used to clip (W n) draws nothing and is dropped.
     *
     * @return array{h: list<array{0: float, 1: float, 2: float}>, v: list<array{0: float, 1: float, 2: float}>} h: y, from x, to x. v: x, from y, to y.
     */
    private function rules(Page $page, int $rotation): array
    {
        $stream = $this->stream($page);
        $rules = ['h' => [], 'v' => []];

        if ($stream === '' || strlen($stream) > self::MAX_STREAM_BYTES || ! preg_match_all(self::TOKEN, $stream, $matches)) {
            return $rules;
        }

        $identity = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
        $ctm = $identity;
        $saved = [];
        $numbers = [];
        $current = $start = null;
        $pending = [];
        $kept = 0;
        $tokens = $matches[0];
        $count = count($tokens);

        for ($i = 0; $i < $count && $kept < self::MAX_SEGMENTS; $i++) {
            $token = $tokens[$i];
            $first = $token[0];

            if (($first >= '0' && $first <= '9') || $first === '-' || $first === '+' || $first === '.') {
                $numbers[] = (float) $token;

                if (count($numbers) > 6) {
                    array_shift($numbers);
                }

                continue;
            }

            if ($first === '(' || $first === '<' || $first === '/' || $first === '[' || $first === ']' || $first === '{' || $first === '}' || $first === '%' || $first === '>') {
                continue;
            }

            switch ($token) {
                case 'q':
                    $saved[] = $ctm;

                    if (count($saved) > 64) {
                        array_shift($saved);
                    }
                    break;
                case 'Q':
                    $ctm = array_pop($saved) ?? $identity;
                    break;
                case 'cm':
                    if (count($numbers) === 6) {
                        $ctm = $this->multiply($numbers, $ctm);
                    }
                    break;
                case 'm':
                    if (count($numbers) >= 2) {
                        $current = $start = array_slice($numbers, -2);
                    }
                    break;
                case 'l':
                    if (count($numbers) >= 2 && $current !== null) {
                        $point = array_slice($numbers, -2);
                        $pending[] = [$current, $point];
                        $current = $point;
                    }
                    break;
                case 're':
                    if (count($numbers) >= 4) {
                        [$x, $y, $w, $h] = array_slice($numbers, -4);
                        $pending[] = [[$x, $y], [$x + $w, $y]];
                        $pending[] = [[$x, $y + $h], [$x + $w, $y + $h]];
                        $pending[] = [[$x, $y], [$x, $y + $h]];
                        $pending[] = [[$x + $w, $y], [$x + $w, $y + $h]];
                        $current = $start = [$x, $y];
                    }
                    break;
                case 'h':
                    if ($current !== null && $start !== null) {
                        $pending[] = [$current, $start];
                        $current = $start;
                    }
                    break;
                case 'n':
                    $pending = [];
                    $current = $start = null;
                    break;
                case 'ID':
                    // Inline image data runs until EI and can contain anything.
                    while (++$i < $count && $tokens[$i] !== 'EI') {
                    }
                    break;
                default:
                    if (in_array($token, self::PAINT, true)) {
                        foreach ($pending as [$from, $to]) {
                            [$fx, $fy] = $this->apply($ctm, $from[0], $from[1]);
                            [$tx, $ty] = $this->apply($ctm, $to[0], $to[1]);
                            [$x0, $y0] = $this->turn($fx, $fy, $rotation);
                            [$x1, $y1] = $this->turn($tx, $ty, $rotation);

                            if (abs($y1 - $y0) <= 0.75 && abs($x1 - $x0) >= 3) {
                                $rules['h'][] = [($y0 + $y1) / 2, min($x0, $x1), max($x0, $x1)];
                                $kept++;
                            } elseif (abs($x1 - $x0) <= 0.75 && abs($y1 - $y0) >= 3) {
                                $rules['v'][] = [($x0 + $x1) / 2, min($y0, $y1), max($y0, $y1)];
                                $kept++;
                            }
                        }

                        $pending = [];
                        $current = $start = null;
                    }
            }

            if (count($pending) > self::MAX_SEGMENTS) {
                $pending = [];
            }

            $numbers = [];
        }

        return $rules;
    }

    private function stream(Page $page): string
    {
        try {
            $contents = $page->get('Contents');
            $value = $contents?->getContent();
        } catch (Throwable) {
            return '';
        }

        if (is_array($value)) {
            $text = '';

            foreach ($value as $section) {
                $text .= is_object($section) && method_exists($section, 'getContent') ? (string) $section->getContent()."\n" : '';
            }

            return $text;
        }

        return (string) $value;
    }

    /** @return array{0: float, 1: float} */
    private function apply(array $ctm, float $x, float $y): array
    {
        return [$ctm[0] * $x + $ctm[2] * $y + $ctm[4], $ctm[1] * $x + $ctm[3] * $y + $ctm[5]];
    }

    /** The new matrix times the current one, as the cm operator does. */
    private function multiply(array $new, array $ctm): array
    {
        return [
            $new[0] * $ctm[0] + $new[1] * $ctm[2],
            $new[0] * $ctm[1] + $new[1] * $ctm[3],
            $new[2] * $ctm[0] + $new[3] * $ctm[2],
            $new[2] * $ctm[1] + $new[3] * $ctm[3],
            $new[4] * $ctm[0] + $new[5] * $ctm[2] + $ctm[4],
            $new[4] * $ctm[1] + $new[5] * $ctm[3] + $ctm[5],
        ];
    }

    private function unreadable(): string
    {
        return __('This file could not be read as a PDF. It may be damaged or password-protected. Open it in a PDF viewer, save a fresh copy, or upload the Excel file instead.');
    }

    private function slow(): string
    {
        return __('This PDF is too complex to read in the time allowed. Export only the estimate pages, or upload the Excel file instead.');
    }
}
