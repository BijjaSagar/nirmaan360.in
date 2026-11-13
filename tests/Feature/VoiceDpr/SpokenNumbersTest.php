<?php

namespace Tests\Feature\VoiceDpr;

use App\Services\VoiceDpr\SpokenNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Spoken and written number phrases in Marathi, Hindi and English, and the ones that are not one number. */
class SpokenNumbersTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function marathi(): array
    {
        return [
            'a word' => ['mr-IN', 'सहा', '6'],
            'a teen' => ['mr-IN', 'सोळा', '16'],
            'an irregular tens word' => ['mr-IN', 'सत्तेचाळीस', '47'],
            'half more, joined' => ['mr-IN', 'साडेबारा', '12.5'],
            'half more, apart' => ['mr-IN', 'साडे बारा', '12.5'],
            'a quarter more' => ['mr-IN', 'सव्वा दोन', '2.25'],
            'a quarter less, joined' => ['mr-IN', 'पावणेतीन', '2.75'],
            'one and a half' => ['mr-IN', 'दीड', '1.5'],
            'two and a half' => ['mr-IN', 'अडीच', '2.5'],
            'three quarters' => ['mr-IN', 'पाऊण', '0.75'],
            'a quarter' => ['mr-IN', 'पाव', '0.25'],
            'half' => ['mr-IN', 'अर्धा', '0.5'],
            'one and a quarter alone' => ['mr-IN', 'सव्वा', '1.25'],
            'hundreds joined' => ['mr-IN', 'दोनशे', '200'],
            'hundreds and tens' => ['mr-IN', 'दोनशे पन्नास', '250'],
            'one hundred and twenty' => ['mr-IN', 'एकशे वीस', '120'],
            'a hundred' => ['mr-IN', 'शंभर', '100'],
            'one and a half hundred' => ['mr-IN', 'दीडशे', '150'],
            'a quarter more than a hundred' => ['mr-IN', 'सव्वाशे', '125'],
            'a quarter less than two hundred' => ['mr-IN', 'पावणेदोनशे', '175'],
            'three and a half hundred' => ['mr-IN', 'साडेतीनशे', '350'],
            'thousands' => ['mr-IN', 'दोन हजार पाचशे', '2500'],
            'a thousand' => ['mr-IN', 'हजार', '1000'],
            'half more than twelve thousand' => ['mr-IN', 'साडे बारा हजार', '12500'],
            'a decimal word' => ['mr-IN', 'बारा पूर्णांक पाच', '12.5'],
            'a group of people' => ['mr-IN', 'दोघे', '2'],
            'Devanagari digits' => ['mr-IN', '१२.५', '12.5'],
            'ASCII digits with trailing zeros' => ['mr-IN', '12.500', '12.5'],
            'Indian grouping' => ['mr-IN', '1,20,000', '120000'],
            'a comma after the number' => ['mr-IN', 'सहा,', '6'],
            'English inside Marathi' => ['mr-IN', 'twelve point five', '12.5'],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function hindi(): array
    {
        return [
            'a word' => ['hi-IN', 'छह', '6'],
            'another spelling' => ['hi-IN', 'पांच', '5'],
            'a tens word' => ['hi-IN', 'अड़तीस', '38'],
            'half more' => ['hi-IN', 'साढ़े तीन', '3.5'],
            'a quarter more' => ['hi-IN', 'सवा दो', '2.25'],
            'a quarter less' => ['hi-IN', 'पौने तीन', '2.75'],
            'one and a half' => ['hi-IN', 'डेढ़', '1.5'],
            'two and a half' => ['hi-IN', 'ढाई', '2.5'],
            'half' => ['hi-IN', 'आधा', '0.5'],
            'one and a half hundred' => ['hi-IN', 'डेढ़ सौ', '150'],
            'two and a half thousand' => ['hi-IN', 'ढाई हज़ार', '2500'],
            'a quarter more than a hundred' => ['hi-IN', 'सवा सौ', '125'],
            'a quarter less than two hundred' => ['hi-IN', 'पौने दो सौ', '175'],
            'three and a half hundred' => ['hi-IN', 'साढ़े तीन सौ', '350'],
            'hundreds and tens' => ['hi-IN', 'दो सौ पचास', '250'],
            'thousands, both spellings' => ['hi-IN', 'दो हजार पांच सौ', '2500'],
            'a hundred alone' => ['hi-IN', 'सौ', '100'],
            'digits' => ['hi-IN', '40', '40'],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function english(): array
    {
        return [
            'a word' => ['en-IN', 'six', '6'],
            'tens and units' => ['en-IN', 'twenty-five', '25'],
            'tens and units, apart' => ['en-IN', 'forty two', '42'],
            'hundreds with and' => ['en-IN', 'two hundred and fifty', '250'],
            'a hundred' => ['en-IN', 'a hundred', '100'],
            'twelve hundred' => ['en-IN', 'twelve hundred', '1200'],
            'and a half' => ['en-IN', 'two and a half', '2.5'],
            'and a quarter' => ['en-IN', 'one and a quarter', '1.25'],
            'and a half thousand' => ['en-IN', 'one and a half thousand', '1500'],
            'point' => ['en-IN', 'twelve point five', '12.5'],
            'point two digits' => ['en-IN', 'point two five', '0.25'],
            'half' => ['en-IN', 'half', '0.5'],
            'a quarter' => ['en-IN', 'a quarter', '0.25'],
            'upper case' => ['en-IN', 'Six', '6'],
            'digits and a word' => ['en-IN', '2 thousand', '2000'],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function phrases(): array
    {
        $named = fn (string $language, array $cases) => array_combine(array_map(fn (string $name) => "{$language}: {$name}", array_keys($cases)), $cases);

        return [...$named('Marathi', self::marathi()), ...$named('Hindi', self::hindi()), ...$named('English', self::english())];
    }

    #[DataProvider('phrases')]
    public function test_a_phrase_says_this_number(string $locale, string $phrase, string $value): void
    {
        $this->assertSame($value, SpokenNumbers::parse($phrase, $locale));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function notOneNumber(): array
    {
        return [
            'a number and a trade' => ['mr-IN', 'सहा गवंडी'],
            'two numbers side by side' => ['hi-IN', 'दो तीन'],
            'two digit runs' => ['en-IN', '6 12'],
            'small before large' => ['hi-IN', 'पचास दो सौ'],
            'units before tens' => ['en-IN', 'five twenty'],
            'a group after a smaller one' => ['hi-IN', 'दो हज़ार बारह सौ'],
            'a size written on' => ['mr-IN', '12mm'],
            'a word alone' => ['en-IN', 'and'],
            'an article alone' => ['en-IN', 'a'],
            'half more with nothing after' => ['mr-IN', 'साडे'],
            'a Hindi word in Marathi' => ['mr-IN', 'छह'],
            'a Marathi word in English' => ['en-IN', 'सहा'],
            'digits of two scripts' => ['mr-IN', '1२'],
            'a raised digit' => ['mr-IN', '²'],
            'commas in the wrong places' => ['en-IN', '12,00'],
            'too many decimals' => ['en-IN', '1.2345'],
            'nothing' => ['en-IN', ' , '],
        ];
    }

    #[DataProvider('notOneNumber')]
    public function test_anything_but_one_number_is_refused(string $locale, string $phrase): void
    {
        $this->assertNull(SpokenNumbers::parse($phrase, $locale));
    }

    public function test_a_spelling_added_to_the_list_is_understood_and_nothing_else_changes(): void
    {
        $this->assertSame('600', SpokenNumbers::parse('सहाशे', 'mr-IN'));
        $this->assertNull(SpokenNumbers::parse('छे', 'mr-IN'));

        config(['modules.voice_dpr.number_words.mr.छे' => '6']);
        $this->assertSame('6', SpokenNumbers::parse('छे', 'mr-IN'));
    }
}
