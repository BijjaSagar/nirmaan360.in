<?php

namespace Tests\Feature\Wording;

use App\Services\Wording\SlotGuard;
use App\Services\Wording\Violation;
use App\Services\Wording\WordingException;
use App\Services\Wording\WordLists;
use PHPUnit\Framework\Attributes\DataProvider;

/** Guard A: a sentence from a model may not state a figure, in digits or in words, in any of the three languages. */
class SlotGuardTest extends WordingTestCase
{
    /** @return list<string> The rules a text broke, once each. */
    private function rules(string $text, ?string $locale = 'en'): array
    {
        return array_values(array_unique(array_map(fn (Violation $violation) => $violation->rule, $this->guard()->check($text, $locale))));
    }

    /** @return array<string, array{0: string, 1: string, 2?: string}> Text, the rule it must break, the language of the document. */
    public static function sentencesThatStateAFigure(): array
    {
        return [
            // The model never places a token.
            'an opening token' => ['The amount is {{bill_1_outstanding}}.', Violation::TOKEN],
            'half a token' => ['The amount is {{ as agreed.', Violation::TOKEN],
            'closing braces' => ['As agreed }} we continue.', Violation::TOKEN],

            // Numerals of any script.
            'an ASCII digit' => ['We wrote to you 3 times.', Violation::DIGIT],
            'a digit inside a word' => ['Concrete of grade M25 was used.', Violation::DIGIT],
            'Devanagari digits' => ['हमने आपको १५ बार लिखा।', Violation::DIGIT, 'hi'],
            'Marathi text with Devanagari digits' => ['काम ३० टप्प्यांत झाले.', Violation::DIGIT, 'mr'],
            'full-width digits' => ['Paid in １２ parts.', Violation::DIGIT],
            'a fraction sign' => ['About ½ of the slab is cast.', Violation::DIGIT],
            'a superscript' => ['An area in m² was handed over.', Violation::DIGIT],
            'a Roman numeral sign' => ['See annexure Ⅳ.', Violation::DIGIT],

            // Currency.
            'the rupee sign' => ['A sum in ₹ is due.', Violation::CURRENCY],
            'a dollar sign' => ['A sum in $ is due.', Violation::CURRENCY],
            'Rs as a mark' => ['A sum of Rs. is due.', Violation::CURRENCY],
            'INR' => ['The INR value is pending.', Violation::CURRENCY],
            'the Devanagari short form' => ['रु. मध्ये रक्कम बाकी आहे.', Violation::CURRENCY, 'mr'],

            // Percent.
            'the percent sign' => ['Most of the work, about % wise, is done.', Violation::PERCENT],
            'percent as a word' => ['A large percent of the work is done.', Violation::PERCENT],
            'percentage' => ['A large percentage of the work is done.', Violation::PERCENT],
            'per cent as two words' => ['A good per cent of the work is done.', Violation::PERCENT],
            'per cent with a hyphen' => ['A good per-cent of the work is done.', Violation::PERCENT],
            'percent as said in Hindi' => ['काम का बड़ा परसेंट पूरा हुआ है।', Violation::PERCENT, 'hi'],
            'Hindi percent' => ['काम का बड़ा प्रतिशत पूरा हुआ है।', Violation::PERCENT, 'hi'],
            'Hindi percent with a nukta' => ['काम कुछ फ़ीसदी बाकी है।', Violation::PERCENT, 'hi'],
            'Hindi percent without the nukta' => ['काम कुछ फीसदी बाकी है।', Violation::PERCENT, 'hi'],
            'Marathi percent' => ['कामाचे बरेच टक्के पूर्ण झाले.', Violation::PERCENT, 'mr'],
            'Marathi percent with an ending' => ['काम काही टक्क्यांनी वाढले.', Violation::PERCENT, 'mr'],

            // Months.
            'an English month' => ['We wrote to you in November.', Violation::MONTH],
            'a short English month' => ['We wrote to you in Nov.', Violation::MONTH],
            'a month in capitals' => ['WE WROTE IN DECEMBER.', Violation::MONTH],
            'March with a capital' => ['The slab was cast in March.', Violation::MONTH],
            'August with a capital' => ['The slab was cast in August.', Violation::MONTH],
            'May inside a sentence' => ['Please reply by May.', Violation::MONTH],
            'May after a preposition' => ['The work stopped in May and has not resumed.', Violation::MONTH],
            'a Hindi month' => ['हमने नवंबर में पत्र भेजा था।', Violation::MONTH, 'hi'],
            'a Hindi month in its other spelling' => ['हमने नवम्बर में पत्र भेजा था।', Violation::MONTH, 'hi'],
            'Hindi May' => ['काम मई में रुका था।', Violation::MONTH, 'hi'],
            'a Marathi month' => ['आम्ही नोव्हेंबर महिन्यात पत्र पाठवले.', Violation::MONTH, 'mr'],
            'a Marathi month with an ending' => ['काम डिसेंबरपर्यंत पूर्ण होईल.', Violation::MONTH, 'mr'],
            'Marathi May' => ['काम मे महिन्यात थांबले.', Violation::MONTH, 'mr'],
            'Marathi June with an ending' => ['काम जूनमध्ये सुरू झाले.', Violation::MONTH, 'mr'],
            'an English month in a Marathi letter' => ['काम October मध्ये थांबले.', Violation::MONTH, 'mr'],

            // Weekdays.
            'a weekday' => ['We will write again on Monday.', Violation::WEEKDAY],
            'a Hindi weekday' => ['हम सोमवार को फिर लिखेंगे।', Violation::WEEKDAY, 'hi'],
            'a Marathi weekday with an ending' => ['आम्ही मंगळवारी पुन्हा लिहू.', Violation::WEEKDAY, 'mr'],

            // A number word beside a quantity word.
            'days in words' => ['The work was stopped for fifteen days.', Violation::NUMBER_WORD],
            'a compound number' => ['It took twenty-five days to restart.', Violation::NUMBER_WORD],
            'the quantity first' => ['The lost days, fifteen in all, are listed below.', Violation::NUMBER_WORD],
            'two words apart' => ['We waited two long weeks.', Violation::NUMBER_WORD],
            'money in words' => ['About two lakh rupees are pending.', Violation::NUMBER_WORD],
            'lakhs of rupees' => ['Lakhs of rupees are pending.', Violation::NUMBER_WORD],
            'a unit of measure' => ['We cast forty cubic metres.', Violation::NUMBER_WORD],
            'half a month' => ['The site was shut for half the month.', Violation::NUMBER_WORD],
            'a week' => ['Our crew was idle for a week.', Violation::NUMBER_WORD],
            'an hour' => ['The pour was held up for an hour.', Violation::NUMBER_WORD],
            'a lakh' => ['About a lakh of rupees is pending.', Violation::NUMBER_WORD],
            'Hindi days' => ['काम पंद्रह दिन रुका रहा।', Violation::NUMBER_WORD, 'hi'],
            'Hindi days, the other spelling' => ['काम पन्द्रह दिनों तक रुका रहा।', Violation::NUMBER_WORD, 'hi'],
            'Hindi two days' => ['हम दो दिन में उत्तर देंगे।', Violation::NUMBER_WORD, 'hi'],
            'Hindi money' => ['लगभग दो लाख रुपये बाकी हैं।', Violation::NUMBER_WORD, 'hi'],
            'Hindi with a chandrabindu' => ['काम पाँच हफ़्ते रुका।', Violation::NUMBER_WORD, 'hi'],
            'Hindi with an anusvara' => ['काम पांच हफ्ते रुका।', Violation::NUMBER_WORD, 'hi'],
            'Hindi one and a half months' => ['काम डेढ़ महीने रुका।', Violation::NUMBER_WORD, 'hi'],
            'a Hindi number above twenty' => ['काम पैंतालीस दिन रुका।', Violation::NUMBER_WORD, 'hi'],
            'Marathi days with an ending' => ['काम पंधरा दिवसांत पूर्ण होईल.', Violation::NUMBER_WORD, 'mr'],
            'Marathi one week' => ['काम एका आठवड्यात पूर्ण होईल.', Violation::NUMBER_WORD, 'mr'],
            'Marathi months' => ['काम तीन महिन्यांपासून बंद आहे.', Violation::NUMBER_WORD, 'mr'],
            'Marathi money' => ['सुमारे दोन लाख रुपये बाकी आहेत.', Violation::NUMBER_WORD, 'mr'],
            'a Marathi number above twenty' => ['काम पंचेचाळीस दिवस बंद होते.', Violation::NUMBER_WORD, 'mr'],
            'Marathi one and a half' => ['काम दीड महिना बंद होते.', Violation::NUMBER_WORD, 'mr'],
            'a Marathi number in a Hindi letter' => ['काम पंधरा दिवस रुका।', Violation::NUMBER_WORD, 'hi'],

            // A word that is a figure by itself.
            'a fortnight' => ['We expect to resume within a fortnight.', Violation::FIGURE_WORD],
            'twice' => ['We have written to you twice.', Violation::FIGURE_WORD],
            'a Hindi fortnight' => ['काम एक पखवाड़े से बंद है।', Violation::FIGURE_WORD, 'hi'],
            'a Marathi fortnight' => ['काम पंधरवड्यापासून बंद आहे.', Violation::FIGURE_WORD, 'mr'],
            'Marathi double' => ['खर्च दुप्पट झाला आहे.', Violation::FIGURE_WORD, 'mr'],

            // Roman numerals.
            'a Roman numeral' => ['Please see Annexure IV with this letter.', Violation::ROMAN],
            'a longer Roman numeral' => ['Phase XII of the work is under way.', Violation::ROMAN],

            // Hidden characters that could split a word so the lists miss it.
            'a zero-width space inside a number word' => ["The work stopped for fif\u{200B}teen days.", Violation::CONTROL],
            'a soft hyphen' => ["The work stopped for fif\u{00AD}teen days.", Violation::CONTROL],
            'a control character' => ["Kind regards\u{0007}", Violation::CONTROL],
        ];
    }

    #[DataProvider('sentencesThatStateAFigure')]
    public function test_a_sentence_that_states_a_figure_is_refused(string $text, string $rule, string $locale = 'en'): void
    {
        $this->assertContains($rule, $this->rules($text, $locale), $text);
        $this->assertFalse($this->guard()->passes($text, $locale));
    }

    /** @return array<string, array{0: string, 1?: string}> */
    public static function everydaySentences(): array
    {
        return [
            'a courtesy line' => ['We thank you for your continued support and request your kind attention to the matter below.'],
            'one, alone' => ['One of our engineers will be glad to walk you through the details.'],
            'no one' => ['No one at site was told about the change in drawings.'],
            'May as the first word' => ['May we request you to look into this at the earliest.'],
            'May as the first word of a later sentence' => ['Thank you for your time. May we hear from you soon.'],
            'May as the first word of a new line' => ["Dear Sir,\nMay we draw your attention to the bills listed below."],
            'May after the salutation on one line' => ['Dear Sir, May we draw your attention to the bills listed below.'],
            'may as a verb' => ['You may reach our site office for any clarification.'],
            'march as a verb' => ['The team will march ahead with the remaining work.'],
            'august as a word of respect' => ['We place this before your august office.'],
            'a quantity word with no number' => ['The days lost to the stoppage are listed below.'],
            'a number word far from a quantity word' => ['Two of our engineers have looked into the matter over the past days.'],
            'rupee with no number' => ['Every rupee in the statement below comes from the approved bills.'],
            'the pronoun I' => ['I request your kind attention to the statement below.'],
            'an article before something that is not a unit of time' => ['A piece of the record and a unit of our team are at your service.'],
            'the week, with no count' => ['The work of the week is described below.'],
            'a line break and a tab' => ["We remain at your disposal.\n\tYours faithfully"],

            'Hindi courtesy' => ['आपके निरंतर सहयोग के लिए हम आभारी हैं।', 'hi'],
            'Hindi "एक" alone' => ['एक बार फिर आपसे निवेदन है कि इस विषय पर ध्यान दें।', 'hi'],
            'Hindi "दो" as a verb' => ['कृपया हमें समय दो और मार्गदर्शन करें।', 'hi'],
            'Hindi "में" typed without its dot is not the month of May' => ['इस विषय मे हम आपको लिख रहे हैं।', 'hi'],
            'Hindi closing' => ['नीचे दी गई जानकारी हमारे रिकॉर्ड से ली गई है।', 'hi'],
            'Hindi "जूनियर" is not June' => ['हमारे जूनियर इंजीनियर आपसे मिलेंगे।', 'hi'],

            'Marathi courtesy' => ['आपल्या सहकार्याबद्दल आम्ही आभारी आहोत.', 'mr'],
            'Marathi "एक" alone' => ['एक विनंती आहे की या विषयाकडे लक्ष द्यावे.', 'mr'],
            'Marathi closing' => ['खालील माहिती आमच्या नोंदींवरून घेतली आहे.', 'mr'],
            'Marathi "साठी" is not sixty' => ['या कामासाठी आणि पुढील दिवसांसाठी आपले मार्गदर्शन हवे आहे.', 'mr'],
            'Marathi "आठवण" is not eight' => ['या विषयाची आठवण करून देत आहोत.', 'mr'],
            'Marathi "टक्कर" is not percent' => ['कामात कोणतीही टक्कर नाही.', 'mr'],
            'Marathi "मेहनत" is not May' => ['आमच्या चमूने खूप मेहनत घेतली आहे.', 'mr'],
        ];
    }

    #[DataProvider('everydaySentences')]
    public function test_an_everyday_sentence_passes(string $text, string $locale = 'en'): void
    {
        $this->assertSame([], $this->rules($text, $locale), $text);
        $this->assertTrue($this->guard()->passes($text, $locale));
    }

    public function test_text_that_is_not_valid_utf8_is_refused_whatever_it_says(): void
    {
        // Patterns report "no match" on broken bytes. Without this rule the digits here would slip through.
        $this->assertSame([Violation::ENCODING], $this->rules("Pay 15 days \xC3\x28 later"));
        $this->assertSame([Violation::ENCODING], $this->rules("\xFF"));
    }

    public function test_a_violation_says_what_was_found_and_where(): void
    {
        $violations = $this->guard()->check('कृपया पंधरा दिवसांत रक्कम द्यावी.', 'mr');

        $this->assertCount(1, $violations);
        $this->assertSame(Violation::NUMBER_WORD, $violations[0]->rule);
        $this->assertSame('पंधरा दिवसांत', $violations[0]->found);
        $this->assertSame(6, $violations[0]->offset);
        $this->assertSame(['rule' => 'number_word', 'found' => 'पंधरा दिवसांत', 'offset' => 6], $violations[0]->toArray());
    }

    public function test_without_a_document_language_the_marathi_month_of_may_is_refused(): void
    {
        $this->assertContains(Violation::MONTH, $this->rules('काम मे महिन्यात थांबले.', null));
        $this->assertContains(Violation::MONTH, $this->rules('काम मे महिन्यात थांबले.', 'mr'));
        $this->assertSame([], $this->rules('इस विषय मे हम लिख रहे हैं।', 'hi'));
    }

    public function test_every_number_word_is_refused_beside_a_quantity_word_and_passes_alone(): void
    {
        $beside = ['en' => 'days', 'hi' => 'दिन', 'mr' => 'दिवस'];
        $checked = 0;

        foreach (config('wording.number_words') as $language => $words) {
            foreach ($words as $word) {
                $this->assertContains(Violation::NUMBER_WORD, $this->rules("{$word} {$beside[$language]}", $language), "{$word} {$beside[$language]}");
                $this->assertSame([], $this->rules($word, $language), "{$word} alone");
                $checked++;
            }
        }

        // Zero to ninety-nine in Hindi and in Marathi, and the English words they are built from.
        $this->assertGreaterThan(280, $checked);
    }

    public function test_every_quantity_word_is_refused_beside_a_number_word_and_passes_alone(): void
    {
        $number = ['en' => 'three', 'hi' => 'तीन', 'mr' => 'तीन'];

        foreach (config('wording.quantity_words') as $language => $words) {
            foreach ($words as $word) {
                $word = rtrim($word, '*');

                $this->assertContains(Violation::NUMBER_WORD, $this->rules("{$number[$language]} {$word}", $language), "{$number[$language]} {$word}");
                $this->assertSame([], $this->rules($word, $language), "{$word} alone");
            }
        }
    }

    public function test_every_month_and_weekday_name_the_product_prints_is_refused(): void
    {
        foreach (config('wording.months') as $language => $months) {
            $this->assertCount(12, $months);

            foreach ($months as $month) {
                // In running text, so "May" is not the first word of its sentence.
                $text = $language === 'en' ? "We wrote in {$month} about this." : "काम {$month} महिन्यात थांबले.";

                $this->assertContains(Violation::MONTH, $this->rules($text, $language), $text);
            }
        }

        foreach (config('wording.weekday_names') as $language => $days) {
            foreach ($days as $day) {
                $this->assertContains(Violation::WEEKDAY, $this->rules(rtrim($day, '*'), $language), $day);
            }
        }
    }

    public function test_a_guard_whose_word_lists_are_missing_stops_instead_of_letting_text_through(): void
    {
        $empty = new SlotGuard(new WordLists(['number_words' => [], 'quantity_words' => ['en' => ['days']]]));

        $this->expectException(WordingException::class);

        $empty->check('The work stopped for fifteen days.', 'en');
    }

    public function test_the_two_spellings_of_a_devanagari_word_are_one_word(): void
    {
        $this->assertSame(WordLists::fold('हज़ार'), WordLists::fold('हजार'));
        $this->assertSame(WordLists::fold('पाँच'), WordLists::fold('पांच'));
        $this->assertSame(WordLists::fold("चौ\u{0931}\u{094D}याहत्तर"), WordLists::fold("चौ\u{0930}\u{094D}\u{200D}याहत्तर"));
        $this->assertSame('november', WordLists::fold('NOVEMBER'));
        $this->assertSame(['काम', 'दिवसांत'], array_column(WordLists::words('काम, दिवसांत!'), 'text'));
        $this->assertSame([0, 5], array_column(WordLists::words('काम, दिवसांत!'), 'offset'));
    }
}
