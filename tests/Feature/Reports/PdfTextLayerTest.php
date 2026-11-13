<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\PdfTextLayer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Arr;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Text a machine laid over a page, and text in an old non-Unicode font, are told from typed text.
 * The pages are written by hand, so each one draws exactly what its test says.
 */
class PdfTextLayerTest extends ReportsTestCase
{
    use WritesPdfByHand;

    private const LINES = ['Sr. No.', 'Description of Items', 'Excavation for foundation', 'Cement concrete'];

    /** The two findings about a page, without the font of each piece of text (shownIn()). @return array{invisible: bool, legacy_fonts: list<string>} */
    private function inspect(string $content, array $options = []): array
    {
        return Arr::only(app(PdfTextLayer::class)->inspect($this->parsedPage($this->handPdf($content, $options))), ['invisible', 'legacy_fonts']);
    }

    /** @return list<string|null> */
    private function shownIn(string $content): array
    {
        return app(PdfTextLayer::class)->inspect($this->parsedPage($this->handPdf($content, ['fonts' => ['F2' => 'SHREE-DEV-0714'], 'picture' => 'none'])))['shown_in'];
    }

    public function test_invisible_text_over_the_picture_of_the_page_is_a_machine_layer(): void
    {
        $scan = $this->pagePicture().$this->textLines(self::LINES, 3);

        $this->assertSame(['invisible' => true, 'legacy_fonts' => []], $this->inspect($scan));

        // The same when the resources sit on the page tree, when the image does not name its type, and when a form paints it.
        $this->assertTrue($this->inspect($scan, ['inherited' => true])['invisible']);
        $this->assertTrue($this->inspect($scan, ['typed' => false])['invisible']);
        $this->assertTrue($this->inspect($this->pagePicture('Fm0').$this->textLines(self::LINES, 3), ['picture' => 'form'])['invisible']);

        // A picture written into the page itself, not listed beside it.
        $inline = 'q 595 0 0 842 0 0 cm BI /W 1 /H 1 /CS /G /BPC 8 ID A EI Q'."\n".$this->textLines(self::LINES, 3);
        $this->assertTrue($this->inspect($inline, ['picture' => 'none'])['invisible']);
    }

    public function test_typed_text_is_not_flagged_with_or_without_a_picture(): void
    {
        $none = ['invisible' => false, 'legacy_fonts' => []];

        // Ordinary text, and ordinary text on a page with a logo or a letterhead.
        $this->assertSame($none, $this->inspect($this->textLines(self::LINES), ['picture' => 'none']));
        $this->assertSame($none, $this->inspect($this->pagePicture().$this->textLines(self::LINES)));

        // Invisible text with no picture under it is not a scan.
        $this->assertSame($none, $this->inspect($this->textLines(self::LINES, 3), ['picture' => 'none']));

        // A name that is not a picture of this page paints nothing.
        $this->assertSame($none, $this->inspect($this->pagePicture('Im9').$this->textLines(self::LINES, 3)));

        // A page that draws no text at all.
        $this->assertSame($none, $this->inspect($this->pagePicture()));
    }

    public function test_the_invisible_mode_is_followed_through_saved_and_restored_states(): void
    {
        // The mode is set inside q ... Q, so it is gone again after the Q: one hidden piece, four that show.
        $restored = $this->pagePicture().'q '.$this->textLines(['hidden'], 3).'Q '.'BT /F1 10 Tf 40 700 Td (one) Tj (two) Tj (three) Tj (four) Tj ET';
        $this->assertFalse($this->inspect($restored)['invisible']);

        // The mode outlives the text object it was set in: every later piece is hidden as well.
        $carried = $this->pagePicture().'BT 3 Tr /F1 10 Tf 40 800 Td (first) Tj ET BT /F1 10 Tf 40 700 Td (second) Tj [(third)] TJ ET';
        $this->assertTrue($this->inspect($carried)['invisible']);

        // Words that look like operators inside a string are text, not operators.
        $inString = $this->pagePicture().'BT /F1 10 Tf 40 800 Td (3 Tr and Do \\(here\\)) Tj (plain) Tj ET';
        $this->assertFalse($this->inspect($inString)['invisible']);
    }

    public function test_a_page_counts_as_a_machine_layer_from_four_hidden_pieces_in_five(): void
    {
        $this->assertSame(80, PdfTextLayer::INVISIBLE_SHARE);

        $page = fn (int $hidden, int $shown) => $this->pagePicture()
            .$this->textLines(array_fill(0, $hidden, 'read by the scanner'), 3)
            .$this->textLines(array_fill(0, $shown, 'Page stamp'), 0, 300);

        $this->assertTrue($this->inspect($page(4, 1))['invisible']);
        $this->assertTrue($this->inspect($page(40, 2))['invisible']);
        $this->assertFalse($this->inspect($page(3, 1))['invisible']);
        $this->assertFalse($this->inspect($page(1, 4))['invisible']);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function fontNames(): array
    {
        return [
            'Kruti Dev with spaces and a subset prefix' => ['ABCDEF+Kruti Dev 010', true],
            'Kruti Dev run together' => ['KrutiDev010', true],
            'Kruti Dev with hyphens, in capitals' => ['KRUTI-DEV-680', true],
            'Shree Dev' => ['SHREE-DEV-0714', true],
            'Shree Lipi' => ['XYZABC+ShreeLipi', true],
            'ISM DV-TT' => ['DV-TTSurekh', true],
            'ISM DVB-TT' => ['DVB-TTSurekhEN', true],
            'ISM DVBW-TT' => ['DVBW-TTYogeshEN', true],
            'Akruti' => ['AkrutiDevPriyaNormal', true],
            'APS' => ['APS-DV-Priyanka', true],
            'Chanakya after another word' => ['Walkman-Chanakya-901', true],
            'Chanakya run together with another word' => ['WalkmanChanakya905', true],
            'Shivaji' => ['Shivaji01', true],
            'DevLys' => ['DevLys010', true],
            'the font dompdf embeds' => ['SUBAAB+DejaVuSans', false],
            'Helvetica' => ['Helvetica', false],
            'Arial' => ['ArialMT', false],
            'Times' => ['TimesNewRomanPSMT', false],
            'a Unicode Devanagari font' => ['NotoSansDevanagari-Regular', false],
            'Mangal, which is Unicode' => ['Mangal', false],
            'Nirmala UI, which is Unicode' => ['BCDEFG+NirmalaUI', false],
            'letters that only contain a listed word' => ['AdvTT5235d5a9', false],
            'a subset prefix that happens to spell a listed word' => ['AKRUTI+Helvetica', false],
            'no name' => ['', false],
        ];
    }

    #[DataProvider('fontNames')]
    public function test_old_marathi_and_hindi_font_families_are_known_by_name(string $name, bool $legacy): void
    {
        $this->assertSame($legacy, PdfTextLayer::isLegacyFont($name));
    }

    public function test_a_page_whose_text_is_drawn_in_an_old_font_names_that_font_even_when_its_text_shows(): void
    {
        // A name in a PDF spells a space as #20. The font is named as the page's content selects it.
        $this->assertSame(['invisible' => false, 'legacy_fonts' => ['F1']], $this->inspect($this->textLines(self::LINES), ['font' => 'ABCDEF+Kruti#20Dev#20010', 'picture' => 'none']));
        $this->assertSame(['F1'], $this->inspect($this->textLines(self::LINES), ['font' => 'SHREE-DEV-0714', 'inherited' => true])['legacy_fonts']);
        $this->assertSame([], $this->inspect($this->textLines(self::LINES), ['font' => 'Helvetica'])['legacy_fonts']);
    }

    public function test_an_old_font_counts_only_where_tf_selects_it_for_text(): void
    {
        $listed = ['fonts' => ['F2' => 'SHREE-DEV-0714'], 'picture' => 'none'];

        // Listed beside the page, or on the page tree above it, and never selected: it draws nothing.
        $this->assertSame([], $this->inspect($this->textLines(self::LINES), $listed)['legacy_fonts']);
        $this->assertSame([], $this->inspect($this->textLines(self::LINES), [...$listed, 'inherited' => true])['legacy_fonts']);

        // One heading line drawn in it, and the rest of the page in another font: that font, and only it.
        $heading = $this->textLines(['Marathi heading'], 0, 820, 'F2').$this->textLines(self::LINES);
        $this->assertSame(['F2'], $this->inspect($heading, $listed)['legacy_fonts']);
        $this->assertSame(['F2'], $this->inspect($heading, [...$listed, 'inherited' => true])['legacy_fonts']);

        // Selected with nothing shown in it, or inside q ... Q, which gives the font before it back.
        $this->assertSame([], $this->inspect('BT /F2 10 Tf ET '.$this->textLines(self::LINES), $listed)['legacy_fonts']);
        $this->assertSame([], $this->inspect('BT /F1 10 Tf ET q BT /F2 10 Tf ET Q BT 40 700 Td (after the Q) Tj ET', $listed)['legacy_fonts']);

        // The name pdfparser gives a piece of text and the name in the stream are spelled alike.
        $this->assertSame('F2', PdfTextLayer::fontKey('/F2'));
        $this->assertSame('Old Font', PdfTextLayer::fontKey('Old#20Font'));
    }

    public function test_each_operator_that_shows_text_is_given_the_font_in_force_for_it(): void
    {
        // Q gives back the font in force at its q, and BT ... ET does not change it.
        $this->assertSame(['F2', 'F1', 'F2'], $this->shownIn('BT /F2 9 Tf (title) Tj ET q BT /F1 9 Tf (heading) Tj ET Q BT (row) Tj ET'));
        $this->assertSame(['F2', 'F1'], $this->shownIn('BT /F1 9 Tf ET q BT /F2 9 Tf (title) Tj ET Q BT (row) Tj ET'));

        // ' and " show text in the font selected last, as TJ does.
        $this->assertSame(['F1', 'F2', 'F2', 'F2'], $this->shownIn("BT /F1 9 Tf (heading) Tj /F2 9 Tf 18 TL (row) ' 0 0 (row) \" [(row)] TJ ET"));

        // Text shown before any font is selected has none.
        $this->assertSame([null, 'F1'], $this->shownIn('BT (no font) Tj /F1 9 Tf (row) Tj ET'));
    }

    public function test_a_page_whose_stream_cannot_be_walked_counts_every_old_font_listed_for_it(): void
    {
        // A stream over the size that is walked comes as no tokens. Which font draws what is then not known.
        $page = $this->parsedPage($this->handPdf($this->textLines(self::LINES), ['fonts' => ['F2' => 'SHREE-DEV-0714'], 'picture' => 'none']));
        $layer = app(PdfTextLayer::class)->inspect($page, []);

        $this->assertSame(['F2'], $layer['legacy_fonts']);
        $this->assertSame([], $layer['shown_in']);
        $this->assertSame([], app(PdfTextLayer::class)->inspect($page)['legacy_fonts']);
    }

    public function test_the_font_list_is_read_from_the_module_settings(): void
    {
        $this->assertContains('kruti dev', config('modules.reports.legacy_font_patterns'));
        $this->assertSame([], $this->inspect($this->textLines(self::LINES), ['font' => 'Helvetica'])['legacy_fonts']);

        config(['modules.reports.legacy_font_patterns' => ['helvetica']]);

        $this->assertSame(['F1'], $this->inspect($this->textLines(self::LINES), ['font' => 'Helvetica'])['legacy_fonts']);
        $this->assertFalse(PdfTextLayer::isLegacyFont('KrutiDev010'));

        // An empty entry matches nothing.
        config(['modules.reports.legacy_font_patterns' => ['', ' - ']]);
        $this->assertFalse(PdfTextLayer::isLegacyFont('Helvetica'));
    }

    public function test_a_pdf_printed_from_a_program_is_not_flagged(): void
    {
        // dompdf writes real text with an embedded Unicode font, as a browser or Excel does. A picture on the page changes nothing.
        $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        foreach (['', '<img src="'.$pixel.'" width="80" height="40">'] as $logo) {
            $html = '<html><body style="font-family:DejaVu Sans">'.$logo.'<p>Estimate for a sample bungalow</p><table><tr><th>Sr. No.</th><th>Description</th></tr><tr><td>1</td><td>Excavation for foundation</td></tr></table></body></html>';
            $page = $this->parsedPage(Pdf::loadHTML($html)->setPaper('a4')->output());

            $this->assertSame(['invisible' => false, 'legacy_fonts' => []], Arr::only(app(PdfTextLayer::class)->inspect($page), ['invisible', 'legacy_fonts']));
        }
    }
}
