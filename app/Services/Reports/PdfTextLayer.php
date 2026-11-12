<?php

namespace App\Services\Reports;

use Smalot\PdfParser\Element;
use Smalot\PdfParser\Header;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\PDFObject;

/**
 * Tells text a person typed into a document from text a machine laid over a page.
 *
 * A PDF can carry text that is not what the page shows. Two kinds are looked for:
 *
 * - A scanner app or a text-recognition tool keeps the picture of the page and writes its own
 *   guess of the words on top, in the text mode that draws nothing. The figures in that layer are
 *   guesses, however clean they look when copied out.
 * - Older Marathi and Hindi fonts are not Unicode. The file holds Latin letters and the font draws
 *   them as Devanagari, so what comes out is not what is printed.
 *
 * Neither can be read as typed text. Both checks are rules of thumb. They can miss a file, for
 * example text placed under the picture in the ordinary mode, or a font family that is not in
 * the list. Passing them says only that neither sign was found.
 */
final class PdfTextLayer
{
    /** A page is taken as a picture with machine text when at least this share of its text is drawn invisibly, out of 100. */
    public const INVISIBLE_SHARE = 80;

    /** Text rendering mode 3: neither filled nor stroked, so nothing is drawn. */
    private const INVISIBLE_MODE = 3;

    private const SHOW_TEXT = ['Tj', 'TJ', "'", '"'];

    /** How far forms inside forms are followed when looking for the picture. */
    private const FORM_DEPTH = 3;

    /** How far up the page tree the resources of a page are looked for. */
    private const PARENT_DEPTH = 32;

    /**
     * @param  list<string>|null  $tokens  The page's content stream as BoqPdfReader::tokens() gives it, when the caller has it already.
     * @return array{invisible: bool, legacy_font: bool}
     */
    public function inspect(Page $page, ?array $tokens = null): array
    {
        return [
            'invisible' => $this->invisible($page, $tokens ?? BoqPdfReader::tokens($page)),
            'legacy_font' => $this->legacyFont($page),
        ];
    }

    /**
     * Whether a font name belongs to one of the listed families. The subset prefix a PDF adds
     * ("ABCDEF+") is ignored, and so are letter case and the spaces or hyphens inside the name.
     * A match has to start the name or a word of it, so that "dv-tt" finds DV-TTSurekh and not AdvTT.
     *
     * @param  list<string>|null  $patterns  config('modules.reports.legacy_font_patterns') when null.
     */
    public static function isLegacyFont(string $name, ?array $patterns = null): bool
    {
        $name = (string) preg_replace('/^[A-Z]{6}\+/', '', ltrim(trim($name), '/'));

        foreach ($patterns ?? (array) config('modules.reports.legacy_font_patterns', []) as $pattern) {
            $words = preg_split('/[^a-z0-9]+/', strtolower((string) $pattern), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            if ($words === [] || ! preg_match_all('/'.implode('[^A-Za-z0-9]*', $words).'/i', $name, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [, $at]) {
                $before = $at > 0 ? $name[$at - 1] : '';

                // The start of the name, of a word, or of a capitalised word run together with the one before it.
                if ($before === '' || ! ctype_alpha($before) || (ctype_lower($before) && ctype_upper($name[$at]))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The page paints a picture and nearly all of its text is drawn in the invisible mode.
     * The mode is part of the graphics state, so it is saved and restored with q and Q.
     *
     * @param  list<string>  $tokens
     */
    private function invisible(Page $page, array $tokens): bool
    {
        $mode = 0;
        $saved = [];
        $shown = 0;
        $hidden = 0;
        $number = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $first = $token[0];

            if (($first >= '0' && $first <= '9') || $first === '-' || $first === '+' || $first === '.') {
                $number = $token;

                continue;
            }

            if ($first === '(' || $first === '<' || $first === '/' || $first === '[' || $first === ']' || $first === '{' || $first === '}' || $first === '%' || $first === '>') {
                continue;
            }

            if ($token === 'q') {
                $saved[] = $mode;

                if (count($saved) > 64) {
                    array_shift($saved);
                }
            } elseif ($token === 'Q') {
                $mode = array_pop($saved) ?? $mode;
            } elseif ($token === 'Tr') {
                $mode = $number === null ? $mode : (int) (float) $number;
            } elseif ($token === 'ID') {
                // Inline image data runs until EI and can contain anything.
                while (++$i < $count && $tokens[$i] !== 'EI') {
                }
            } elseif (in_array($token, self::SHOW_TEXT, true)) {
                $shown++;
                $hidden += $mode === self::INVISIBLE_MODE ? 1 : 0;
            }

            $number = null;
        }

        return $shown > 0
            && $hidden * 100 >= $shown * self::INVISIBLE_SHARE
            && $this->paintsImage($tokens, $this->resources($page), self::FORM_DEPTH);
    }

    /**
     * Whether these operators put a picture on the page: an image named in the resources, an image
     * written into the stream itself, or a form that holds one.
     *
     * @param  list<string>  $tokens
     */
    private function paintsImage(array $tokens, ?Header $resources, int $depth): bool
    {
        $xobjects = $resources === null ? null : $this->dictionary($resources->get('XObject'));
        $name = null;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token[0] === '/') {
                $name = substr($token, 1);

                continue;
            }

            if ($token === 'BI') {
                return true;
            }

            if ($token === 'ID') {
                while (++$i < $count && $tokens[$i] !== 'EI') {
                }
            } elseif ($token === 'Do' && $name !== null && $xobjects !== null) {
                $object = $this->named($xobjects, $name);
                $subtype = $object instanceof PDFObject ? $this->name($object->get('Subtype')) : '';

                if ($subtype === 'Image') {
                    return true;
                }

                if ($subtype === 'Form' && $depth > 0 && $this->paintsImage(BoqPdfReader::tokenise((string) $object->getContent()), $this->resources($object) ?? $resources, $depth - 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Any font the page lists is one of the non-Unicode families. */
    private function legacyFont(Page $page): bool
    {
        $resources = $this->resources($page);
        $fonts = $resources === null ? null : $this->dictionary($resources->get('Font'));

        foreach ($fonts?->getElements() ?? [] as $font) {
            if ($font instanceof PDFObject && $font->has('BaseFont') && self::isLegacyFont($this->name($font->get('BaseFont')))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The resources a page or form draws with. A page without its own takes those of the nearest
     * node above it in the page tree, which pdfparser does not follow for images.
     */
    private function resources(PDFObject $object): ?Header
    {
        for ($depth = 0; $depth < self::PARENT_DEPTH; $depth++) {
            $resources = $this->dictionary($object->get('Resources'));

            if ($resources !== null) {
                return $resources;
            }

            $object = $object->get('Parent');

            if (! $object instanceof PDFObject) {
                return null;
            }
        }

        return null;
    }

    /** A dictionary written in place, or one kept as an object of its own. */
    private function dictionary(mixed $value): ?Header
    {
        if ($value instanceof PDFObject) {
            $value = $value->getHeader();
        }

        return $value instanceof Header ? $value : null;
    }

    /** An entry of a dictionary by the name a content stream uses, which may spell a character as #xx. */
    private function named(Header $dictionary, string $name): mixed
    {
        foreach (array_unique([$name, (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $match) => chr((int) hexdec($match[1])), $name)]) as $key) {
            if ($dictionary->has($key)) {
                return $dictionary->get($key);
            }
        }

        return null;
    }

    private function name(mixed $value): string
    {
        return $value instanceof Element ? trim((string) $value) : '';
    }
}
