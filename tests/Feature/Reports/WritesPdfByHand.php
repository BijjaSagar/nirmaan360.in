<?php

namespace Tests\Feature\Reports;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;

/**
 * One-page PDFs written out byte by byte, so a test controls exactly what the page draws:
 * which text is invisible, where the picture is, and what the font is called. No tool that
 * makes PDFs will write a scanner's text layer or an old Marathi font on request.
 */
trait WritesPdfByHand
{
    /**
     * The options: "font" is the name the PDF gives the font. "fonts" lists more fonts beside it, by the
     * name the content stream selects them with. "picture" is where the image is listed: on the page,
     * inside a form the page paints, or nowhere. "inherited" puts the resources on the page tree instead
     * of the page. "typed" false leaves /Type /XObject off the image, as a PDF may.
     *
     * @param  string|list<string>  $content  The content stream of the page, or of each page. /F1 is the font, /Im0 the picture and /Fm0 a form that paints the picture.
     * @param  array{font?: string, fonts?: array<string, string>, picture?: 'page'|'form'|'none', inherited?: bool, typed?: bool}  $options
     */
    protected function handPdf(string|array $content, array $options = []): string
    {
        $pages = (array) $content;
        $xobjects = match ($options['picture'] ?? 'page') {
            'page' => '/XObject << /Im0 4 0 R >>',
            'form' => '/XObject << /Fm0 5 0 R >>',
            default => '',
        };
        $fonts = '/F1 3 0 R';
        $number = 6 + 2 * count($pages);

        foreach (array_keys($options['fonts'] ?? []) as $name) {
            $fonts .= ' /'.$name.' '.$number++.' 0 R';
        }

        $resources = '/Resources << /Font << '.$fonts.' >> '.$xobjects.' >>';
        $inherited = $options['inherited'] ?? false;
        $form = 'q 595 0 0 842 0 0 cm /Im0 Do Q';
        $kids = [];

        foreach (array_keys($pages) as $i) {
            $kids[] = (6 + $i * 2).' 0 R';
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' '.($inherited ? $resources : '').' >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /'.($options['font'] ?? 'Helvetica').' >>',
            '<< '.(($options['typed'] ?? true) ? '/Type /XObject ' : '').'/Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length 1 >>'."\nstream\nA\nendstream",
            '<< /Type /XObject /Subtype /Form /BBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Length '.strlen($form).' >>'."\nstream\n".$form."\nendstream",
        ];

        foreach (array_values($pages) as $i => $stream) {
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents '.(7 + $i * 2).' 0 R '.($inherited ? '' : $resources).' >>';
            $objects[] = '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream";
        }

        foreach ($options['fonts'] ?? [] as $base) {
            $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /'.$base.' >>';
        }

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Root 1 0 R /Size '.(count($objects) + 1)." >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /** The picture of a page, drawn over all of it, as a scanner app writes it first. */
    protected function pagePicture(string $name = 'Im0'): string
    {
        return 'q 595 0 0 842 0 0 cm /'.$name.' Do Q'."\n";
    }

    /**
     * Lines of text, one below the other. Mode 3 draws nothing, which is how a scanner app lays its reading of the page over the picture.
     *
     * @param  list<string>  $lines
     */
    protected function textLines(array $lines, int $mode = 0, int $top = 800, string $font = 'F1'): string
    {
        $text = 'BT '.$mode.' Tr /'.$font.' 10 Tf 40 '.$top.' Td ';

        foreach ($lines as $line) {
            $text .= '('.$line.') Tj 0 -14 Td ';
        }

        return $text.'ET'."\n";
    }

    /**
     * A table drawn cell by cell, each cell a piece of its own at the left of its column, as a program prints one.
     *
     * @param  list<list<string>>  $rows  The heading first.
     * @param  array<int, string>  $fonts  Row number => the font that row is drawn in. The others are in /F1.
     */
    protected function tableCells(array $rows, int $top = 760, array $fonts = []): string
    {
        $columns = [40, 80, 300, 350, 410, 480];
        $text = 'BT 0 Tr ';

        foreach ($rows as $index => $row) {
            $text .= '/'.($fonts[$index] ?? 'F1').' 9 Tf ';

            foreach ($row as $column => $cell) {
                if ($cell !== '') {
                    $text .= '1 0 0 1 '.$columns[$column].' '.($top - 18 * $index).' Tm ('.$cell.') Tj ';
                }
            }
        }

        return $text.'ET'."\n";
    }

    /** The first page of a PDF, parsed the way BoqPdfReader parses it. */
    protected function parsedPage(string $bytes): Page
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDataTmFontInfoHasToBeIncluded(true);

        return (new Parser([], $config))->parseContent($bytes)->getPages()[0];
    }
}
