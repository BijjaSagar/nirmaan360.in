<?php

namespace Tests\Feature\OwnerPack;

use App\Services\OwnerPack\TranslationCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a translation of a line's description must keep to be shown to a person at all. Code cannot
 * tell "excluding" from "including", so the person still accepts each line; these are the checks
 * code can make. The descriptions are invented.
 */
class TranslationCheckTest extends TestCase
{
    private const SOURCE = 'PCC M15 below footings, 100 mm thick, mix 1:2:4, rate for 1,250.50 sqm';

    /** @return array<string, array{0: string, 1: string, 2: ?string}> */
    public static function translations(): array
    {
        return [
            'kept, Marathi' => ['mr', 'फुटिंगखाली M15 PCC, 100 mm जाड, मिश्रण 1:2:4, 1,250.50 sqm साठी दर', null],
            'kept, Hindi' => ['hi', 'फुटिंग के नीचे M15 PCC, 100 mm मोटा, मिश्रण 1:2:4, 1,250.50 sqm के लिए दर', null],
            'a grade with a Marathi ending' => ['mr', 'फुटिंगखाली M15चे PCC, 100 mm जाड, मिश्रण 1:2:4, 1,250.50 sqm साठी दर', null],
            'a number left out' => ['mr', 'फुटिंगखाली M15 PCC, जाड, मिश्रण 1:2:4, 1,250.50 sqm साठी दर', TranslationCheck::NUMBERS],
            'numbers in another order' => ['mr', '100 mm जाड, फुटिंगखाली M15 PCC, मिश्रण 1:2:4, 1,250.50 sqm साठी दर', TranslationCheck::NUMBERS],
            'a number added' => ['mr', 'फुटिंगखाली M15 PCC, 100 mm जाड, मिश्रण 1:2:4, 1,250.50 sqm साठी दर, 2 थरांत', TranslationCheck::NUMBERS],
            'Devanagari digits' => ['mr', 'फुटिंगखाली M१५ PCC, १०० mm जाड, मिश्रण १:२:४, १,२५०.५० sqm साठी दर', TranslationCheck::NUMBERS],
            'a separator changed' => ['mr', 'फुटिंगखाली M15 PCC, 100 mm जाड, मिश्रण 1:2:4, 1250.50 sqm साठी दर', TranslationCheck::NUMBERS],
            'a grade written apart' => ['mr', 'फुटिंगखाली M 15 PCC, 100 mm जाड, मिश्रण 1:2:4, 1,250.50 sqm साठी दर', TranslationCheck::GRADES],
            'a ratio written as words' => ['mr', 'फुटिंगखाली M15 PCC, 100 mm जाड, मिश्रण 1 ला 2 ला 4, 1,250.50 sqm साठी दर', TranslationCheck::GRADES],
            'a grade in Devanagari letters' => ['mr', 'फुटिंगखाली एम15 PCC, 100 mm जाड, मिश्रण 1:2:4, 1,250.50 sqm साठी दर', TranslationCheck::GRADES],
            'not translated' => ['mr', '  PCC M15 below footings,  100 mm thick, mix 1:2:4, rate for 1,250.50 sqm ', TranslationCheck::SAME],
            'English words only' => ['mr', 'PCC of M15 under footings, 100 mm thick, mix 1:2:4, rate per 1,250.50 sqm', TranslationCheck::SCRIPT],
            'a token' => ['mr', 'फुटिंगखाली M15 PCC {{line_1_rate}}, 100 mm जाड, मिश्रण 1:2:4, 1,250.50 sqm', TranslationCheck::TOKEN],
            'a hidden character' => ['mr', "फुटिंगखाली M15\u{202E} PCC, 100 mm जाड, मिश्रण 1:2:4, 1,250.50 sqm साठी दर", TranslationCheck::CONTROL],
            'empty' => ['mr', "  \n ", TranslationCheck::EMPTY],
            'figures only' => ['mr', '15 100 1:2:4', TranslationCheck::EMPTY],
            'far longer than the line' => ['mr', str_repeat('फुटिंगखाली खोदकाम ', 30).'M15 100 1:2:4 1,250.50', TranslationCheck::LENGTH],
        ];
    }

    #[DataProvider('translations')]
    public function test_a_translation_is_kept_only_when_it_keeps_the_figures_of_its_line(string $locale, string $translation, ?string $fault): void
    {
        $this->assertSame($fault, TranslationCheck::fault(self::SOURCE, $translation, $locale));
    }

    public function test_the_joiners_of_marathi_spelling_are_text(): void
    {
        $this->assertNull(TranslationCheck::fault('Brickwork in CM 1:6', "सिमेंट मसाल्यात 1:6 वीटकाम, कऱ्\u{200D}याची", 'mr'));
        $this->assertSame(TranslationCheck::ENCODING, TranslationCheck::fault('Brickwork', "\xC3\x28", 'mr'));
    }

    public function test_numbers_and_grades_are_read_as_written(): void
    {
        $this->assertSame(['15', '100', '1', '2', '4', '1,250.50'], TranslationCheck::numbers(self::SOURCE));
        $this->assertSame(['M15', '1:2:4'], TranslationCheck::grades(self::SOURCE));
        $this->assertSame(['Fe 500D', 'M 25', '1 : 6'], TranslationCheck::grades('TMT Fe 500D bars in M 25 slab, CM 1 : 6, MS pipe, M.S. angle'));
        $this->assertSame(['१२', '3.5'], TranslationCheck::numbers('१२ नग, 3.5 मी'));
    }
}
