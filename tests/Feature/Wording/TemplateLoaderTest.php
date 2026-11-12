<?php

namespace Tests\Feature\Wording;

use App\Services\Wording\DocumentType;
use App\Services\Wording\Slot;
use App\Services\Wording\TemplateLoader;
use App\Services\Wording\WordingException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/** A document type has a complete code text in English, Hindi and Marathi, and no template states a figure of its own. */
class TemplateLoaderTest extends WordingTestCase
{
    private const EXTENSION = 'We request that the time for completion be extended up to {{proposed_completion}}.';

    private function loader(): TemplateLoader
    {
        return app(TemplateLoader::class);
    }

    public function test_templates_given_in_three_languages_are_loaded_as_they_are(): void
    {
        $this->loader()->assertComplete($this->letter());

        $this->assertSame(
            ['opening' => self::OPENING['mr'], 'bill_1' => self::BILL_LINE['mr'], 'closing' => self::CLOSING['mr']],
            $this->loader()->load($this->letter(), 'mr'),
        );
    }

    public function test_a_template_written_in_english_takes_hindi_and_marathi_from_the_language_files(): void
    {
        $type = new DocumentType('eot_request', [
            Slot::free('opening', 'Open the letter.', 'We write to you about the progress of the work.'),
            Slot::fixed('request', self::EXTENSION, ['proposed_completion']),
            // Tokens and punctuation only: there is nothing to translate.
            Slot::fixed('reference', '{{agreement_no}} / {{agreement_date}}', ['agreement_no', 'agreement_date']),
        ]);

        $this->loader()->assertComplete($type);

        $this->assertSame(self::EXTENSION, $this->loader()->load($type, 'en')['request']);
        $this->assertSame('हम अनुरोध करते हैं कि कार्य पूरा करने का समय {{proposed_completion}} तक बढ़ाया जाए।', $this->loader()->load($type, 'hi')['request']);
        $this->assertSame('काम पूर्ण करण्याची मुदत {{proposed_completion}} पर्यंत वाढवावी, ही विनंती.', $this->loader()->load($type, 'mr')['request']);
        $this->assertSame('कामाच्या प्रगतीबाबत आम्ही आपल्याला लिहीत आहोत.', $this->loader()->load($type, 'mr')['opening']);
        $this->assertSame('{{agreement_no}} / {{agreement_date}}', $this->loader()->load($type, 'hi')['reference']);
    }

    public function test_a_template_with_no_hindi_or_marathi_is_refused(): void
    {
        $type = new DocumentType('note', [Slot::fixed('line', 'This sentence about {{total_days}} was never translated.', ['total_days'])]);

        $this->assertArrayHasKey('line', $this->loader()->load($type, 'en'));

        foreach (['hi', 'mr'] as $locale) {
            try {
                $this->loader()->load($type, $locale);
                $this->fail("A document with no {$locale} text was accepted.");
            } catch (WordingException $e) {
                $this->assertStringContainsString("no '{$locale}' template", $e->getMessage());
            }
        }

        $this->expectException(WordingException::class);
        $this->loader()->assertComplete($type);
    }

    public function test_a_translation_that_drops_a_figure_is_refused(): void
    {
        $type = new DocumentType('note', [Slot::fixed('line', 'A translation that lost its figure: {{total_days}}.', ['total_days'])]);

        $this->assertArrayHasKey('line', $this->loader()->load($type, 'mr'));

        $this->expectException(WordingException::class);
        $this->expectExceptionMessage('exactly the facts');
        $this->loader()->load($type, 'hi');
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> A template in three languages, and what the refusal must say. */
    public static function fixedTemplatesThatStateAFigureOfTheirOwn(): array
    {
        $ok = 'Total {{total}}.';

        return [
            'a digit in English' => [['en' => 'The work took 7 days, total {{total}}.', 'hi' => $ok, 'mr' => $ok], "rule 'digit'"],
            'a digit in Hindi only' => [['en' => $ok, 'hi' => 'कुल {{total}}, 7 दिन में।', 'mr' => $ok], "rule 'digit'"],
            'a Devanagari digit in Marathi' => [['en' => $ok, 'hi' => $ok, 'mr' => 'एकूण {{total}}, ७ दिवसांत.'], "rule 'digit'"],
            'a numbered list' => [['en' => '1. Total {{total}}.', 'hi' => $ok, 'mr' => $ok], "rule 'digit'"],
            'a number in words beside a unit' => [['en' => 'The work took fifteen days, total {{total}}.', 'hi' => $ok, 'mr' => $ok], "rule 'number_word'"],
            'a number in Marathi words' => [['en' => $ok, 'hi' => $ok, 'mr' => 'एकूण {{total}}, पंधरा दिवसांत.'], "rule 'number_word'"],
            'a month name' => [['en' => 'Total {{total}} as of November.', 'hi' => $ok, 'mr' => $ok], "rule 'month'"],
            'a weekday' => [['en' => 'Total {{total}} by Monday.', 'hi' => $ok, 'mr' => $ok], "rule 'weekday'"],
            'a Roman numeral' => [['en' => 'Total {{total}}, see Annexure IV.', 'hi' => $ok, 'mr' => $ok], "rule 'roman_numeral'"],
            'a token the slot does not declare, in a translation' => [['en' => $ok, 'hi' => 'कुल {{total}} और {{advance}}।', 'mr' => $ok], 'exactly the facts'],
            'a token left out of a translation' => [['en' => $ok, 'hi' => $ok, 'mr' => 'एकूण.'], 'exactly the facts'],
            'a token written with spaces' => [['en' => $ok, 'hi' => 'कुल {{total}} {{ total }}।', 'mr' => $ok], 'not written as {{name}}'],
            'a single closing pair' => [['en' => $ok, 'hi' => $ok, 'mr' => 'एकूण {{total}} }}.'], 'not written as {{name}}'],
        ];
    }

    #[DataProvider('fixedTemplatesThatStateAFigureOfTheirOwn')]
    public function test_a_fixed_template_may_state_figures_only_through_its_declared_tokens(array $template, string $refusal): void
    {
        $type = new DocumentType('note', [Slot::fixed('line', $template, ['total'])]);

        $this->expectException(WordingException::class);
        $this->expectExceptionMessage($refusal);

        $this->loader()->assertComplete($type);
    }

    public function test_a_fixed_template_may_name_the_rupee_and_the_percentage_beside_its_token(): void
    {
        $type = new DocumentType('note', [Slot::fixed('line', [
            'en' => 'Percentage of work measured so far: {{done}}. Value in rupees: {{value}}.',
            'hi' => 'अब तक मापे गए काम का प्रतिशत: {{done}}। रुपये में मूल्य: {{value}}।',
            'mr' => 'आतापर्यंत मोजलेल्या कामाची टक्केवारी: {{done}}. रुपयांत मूल्य: {{value}}.',
        ], ['done', 'value'])]);

        $this->loader()->assertComplete($type);
        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function freeTemplatesThatAreNotFigureFree(): array
    {
        return [
            'a token' => [['en' => 'We write about {{total}}.', 'hi' => self::OPENING['hi'], 'mr' => self::OPENING['mr']], 'carries a token'],
            'a digit' => [['en' => self::OPENING['en'], 'hi' => 'हम 3 बिलों के बारे में लिख रहे हैं।', 'mr' => self::OPENING['mr']], "rule 'digit'"],
            'a percent word' => [['en' => 'A large percentage of the work is done.', 'hi' => self::OPENING['hi'], 'mr' => self::OPENING['mr']], "rule 'percent'"],
            'a rupee sign' => [['en' => self::OPENING['en'], 'hi' => self::OPENING['hi'], 'mr' => 'रक्कम ₹ मध्ये आहे.'], "rule 'currency'"],
            'a number beside a unit' => [['en' => self::OPENING['en'], 'hi' => self::OPENING['hi'], 'mr' => 'काम दोन आठवड्यांत होईल.'], "rule 'number_word'"],
        ];
    }

    #[DataProvider('freeTemplatesThatAreNotFigureFree')]
    public function test_the_code_text_of_a_free_slot_keeps_every_rule_a_models_text_keeps(array $template, string $refusal): void
    {
        $type = new DocumentType('note', [Slot::free('opening', 'Open the note.', $template)]);

        $this->expectException(WordingException::class);
        $this->expectExceptionMessage($refusal);

        $this->loader()->assertComplete($type);
    }

    public function test_a_slot_must_declare_exactly_the_facts_its_english_template_states(): void
    {
        foreach ([
            fn () => Slot::fixed('line', 'Total {{total}} and {{advance}}.', ['total']),
            fn () => Slot::fixed('line', 'Total {{total}}.', ['total', 'advance']),
            fn () => Slot::fixed('line', 'Total {{number}}.', ['number' => 'bill_1_number', 'outstanding' => 'bill_1_outstanding']),
            fn () => Slot::fixed('line', 'Total {{total}}.', ['Total']),
            fn () => Slot::fixed('Line 1', 'Total {{total}}.', ['total']),
            fn () => Slot::fixed('line', '   ', []),
            fn () => Slot::fixed('line', ['en' => 'Total {{total}}.', 'hi' => 'कुल {{total}}।'], ['total']),
            fn () => Slot::free('opening', ' ', self::OPENING),
            fn () => Slot::free('opening', 'Open the note.', self::OPENING, maxChars: 0),
            fn () => new DocumentType('note', []),
            fn () => new DocumentType('note', [Slot::free('opening', 'Open.', self::OPENING), Slot::free('opening', 'Open again.', self::OPENING)]),
            fn () => new DocumentType('Payment Letter', [Slot::free('opening', 'Open.', self::OPENING)]),
        ] as $wrong) {
            try {
                $wrong();
                $this->fail('A wrongly declared slot or document type was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_one_template_can_be_used_for_several_records(): void
    {
        $first = Slot::fixed('bill_1', self::BILL_LINE, ['number' => 'bill_1_number', 'outstanding' => 'bill_1_outstanding', 'as_on' => 'as_on']);
        $second = Slot::fixed('bill_2', self::BILL_LINE, ['number' => 'bill_2_number', 'outstanding' => 'bill_2_outstanding', 'as_on' => 'as_on']);
        $type = new DocumentType('payment_followup', [$first, $second]);

        $this->assertSame(['bill_1_number', 'bill_1_outstanding', 'as_on'], $first->factKeys());
        $this->assertSame(['bill_1_number', 'bill_1_outstanding', 'as_on', 'bill_2_number', 'bill_2_outstanding'], $type->factKeys());
        $this->assertSame([], $type->freeIds());
        $this->assertNull($type->freeSlot('bill_1'));
        $this->loader()->assertComplete($type);
    }
}
