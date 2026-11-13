<?php

namespace Tests\Feature\Wording;

use App\Services\Wording\DocumentType;
use App\Services\Wording\Fact;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Services\Wording\GuardViolation;
use App\Services\Wording\RenderedSlot;
use App\Services\Wording\Slot;
use App\Services\Wording\SlotText;
use App\Services\Wording\Span;
use App\Services\Wording\SpanGuard;
use App\Services\Wording\Violation;
use App\Services\Wording\WordingException;

/** Guard C: every numeral in a document lies inside a stretch the code filled from a fact. */
class RendererTest extends WordingTestCase
{
    public function test_a_document_is_written_from_code_text_alone_in_three_languages(): void
    {
        $expected = [
            'en' => "We write to you about our running bills.\n\nThe amount outstanding on bill RA-P101-003 is ₹1,50,000.00 as on 5 November 2026.\n\nWe look forward to your reply.",
            'hi' => "हम अपने चालू बिलों के बारे में आपको लिख रहे हैं।\n\nबिल RA-P101-003 पर 5 नवंबर 2026 को बकाया राशि ₹1,50,000.00 है।\n\nहम आपके उत्तर की प्रतीक्षा में हैं।",
            'mr' => "आमच्या चालू बिलांबाबत आम्ही आपल्याला लिहीत आहोत.\n\nबिल RA-P101-003 वर 5 नोव्हेंबर 2026 रोजी थकबाकी ₹1,50,000.00 आहे.\n\nआपल्या उत्तराची आम्ही वाट पाहत आहोत.",
        ];

        foreach ($expected as $locale => $text) {
            $document = $this->renderer()->render($this->letter(), $this->sheet(), $locale);

            $this->assertSame($text, $document->text());
            $this->assertSame($locale, $document->locale);
            $this->assertSame('payment_followup', $document->type);
            $this->assertSame($this->sheet()->fingerprint(), $document->fingerprint);
            $this->assertSame([SlotText::TEMPLATE, RenderedSlot::CODE, SlotText::TEMPLATE], array_map(fn (RenderedSlot $slot) => $slot->source, $document->slots));
            $this->assertSame([], $document->typedNumbers());
            $this->assertSame([], $document->replaced());
        }
    }

    public function test_every_inserted_figure_has_a_span_that_covers_exactly_what_the_fact_prints(): void
    {
        $sheet = $this->sheet();
        $document = $this->renderer()->render($this->letter(), $sheet, 'hi');
        $slot = $document->slot('bill_1');

        $this->assertSame(['bill_1_number', 'as_on', 'bill_1_outstanding'], array_map(fn (Span $span) => $span->fact, $slot->spans));

        foreach ($slot->spans as $span) {
            $this->assertSame($sheet->get($span->fact)->display('hi'), mb_substr($slot->text, $span->offset, $span->length));
        }

        $this->assertSame(['bill_1_number', 'as_on', 'bill_1_outstanding'], $document->factKeys());
        $this->assertSame(['bill_1_number' => 'Bill number', 'as_on' => 'As on date', 'bill_1_outstanding' => 'Outstanding amount'], $document->labels);
        $this->assertSame([], SpanGuard::outside($slot->text, $slot->spans));
    }

    public function test_the_review_screen_gets_each_figure_with_its_label(): void
    {
        $parts = $this->renderer()->render($this->letter(), $this->sheet(), 'en')->parts();

        $this->assertSame(['opening', 'bill_1', 'closing'], array_column($parts, 'id'));
        $this->assertSame([
            ['text' => 'The amount outstanding on bill ', 'fact' => null, 'label' => null, 'typed' => null],
            ['text' => 'RA-P101-003', 'fact' => 'bill_1_number', 'label' => 'Bill number', 'typed' => null],
            ['text' => ' is ', 'fact' => null, 'label' => null, 'typed' => null],
            ['text' => '₹1,50,000.00', 'fact' => 'bill_1_outstanding', 'label' => 'Outstanding amount', 'typed' => null],
            ['text' => ' as on ', 'fact' => null, 'label' => null, 'typed' => null],
            ['text' => '5 November 2026', 'fact' => 'as_on', 'label' => 'As on date', 'typed' => null],
            ['text' => '.', 'fact' => null, 'label' => null, 'typed' => null],
        ], $parts[1]['parts']);

        // Put back together, the parts are the text: nothing is lost and nothing is added.
        foreach ($this->renderer()->render($this->letter(), $this->sheet(), 'mr')->slots as $slot) {
            $this->assertSame($slot->text, implode('', array_column($slot->parts(), 'text')));
        }
    }

    public function test_a_numeral_outside_every_span_is_found(): void
    {
        $text = 'Paid 500 of ₹1,000.00 on १२ days';
        $spans = [new Span('total', 12, 9)];

        $this->assertSame('₹1,000.00', mb_substr($text, 12, 9));
        $this->assertSame([['found' => '500', 'offset' => 5], ['found' => '१२', 'offset' => 25]], SpanGuard::outside($text, $spans));

        // A span that covers only part of a number does not cover the number.
        $this->assertSame([['found' => '000', 'offset' => 12], ['found' => '00', 'offset' => 16]], SpanGuard::outside('Total of ₹1,000.00', [new Span('total', 9, 2)]));

        // Broken bytes cannot be searched, so they are never reported as clean.
        $this->assertNotSame([], SpanGuard::outside("Total \xC3\x28", []));

        $this->expectException(GuardViolation::class);
        SpanGuard::assertNone('line', $text, $spans);
    }

    public function test_a_fixed_template_with_a_numeral_of_its_own_never_becomes_a_document(): void
    {
        // compose() takes templates as they are, so this reaches Guard C without the loader refusing first.
        $templates = [
            'opening' => self::OPENING['en'],
            'bill_1' => 'Bill {{number}}: {{outstanding}} as on {{as_on}}, with 30 pages attached.',
            'closing' => self::CLOSING['en'],
        ];

        try {
            $this->renderer()->compose($this->letter(), $templates, $this->sheet(), 'en');
            $this->fail('A numeral that came from no fact was rendered.');
        } catch (GuardViolation $e) {
            $this->assertSame(Violation::DIGIT, $e->rule);
            $this->assertSame('bill_1', $e->slot);
            $this->assertSame('30', $e->found);
            $this->assertStringNotContainsString('30', $e->getMessage());
        }
    }

    public function test_the_code_text_of_a_free_slot_with_a_numeral_never_becomes_a_document(): void
    {
        $templates = ['opening' => 'We write about 3 bills.', 'bill_1' => self::BILL_LINE['en'], 'closing' => self::CLOSING['en']];

        $this->expectException(GuardViolation::class);

        $this->renderer()->compose($this->letter(), $templates, $this->sheet(), 'en');
    }

    public function test_a_models_text_with_a_figure_is_kept_out_even_when_it_reaches_the_renderer(): void
    {
        $document = $this->renderer()->render($this->letter(), $this->sheet(), 'en', [
            'opening' => SlotText::ai('Our bills of ₹9,99,999.00 are pending for 45 days.'),
            'closing' => ['text' => 'Our crew has waited at site for fifteen days.', 'source' => 'ai'],
        ]);

        $this->assertSame(self::OPENING['en'], $document->slot('opening')->text);
        $this->assertSame(self::CLOSING['en'], $document->slot('closing')->text);
        $this->assertSame(SlotText::TEMPLATE, $document->slot('opening')->source);
        $this->assertSame(['opening', 'closing'], $document->replaced());
        $this->assertStringNotContainsString('9,99,999', $document->text());
        $this->assertStringNotContainsString('45', $document->text());
        $this->assertStringNotContainsString('fifteen', $document->text());
    }

    public function test_stored_text_of_unknown_origin_is_treated_as_a_models(): void
    {
        $document = $this->renderer()->render($this->letter(), $this->sheet(), 'en', [
            'opening' => ['text' => 'We are owed ₹9,99,999.00 for 45 days.'],
            'closing' => ['text' => 'With regards.', 'source' => 'somebody'],
        ]);

        // Not listed for a tick as if a person had typed it: the figure is simply not used.
        $this->assertSame(self::OPENING['en'], $document->slot('opening')->text);
        $this->assertSame([], $document->typedNumbers());
        $this->assertSame(['opening'], $document->replaced());
        $this->assertSame('With regards.', $document->slot('closing')->text);
        $this->assertSame(SlotText::AI, $document->slot('closing')->source);
    }

    public function test_a_models_clean_text_is_used_and_marked_as_the_models(): void
    {
        $document = $this->renderer()->render($this->letter(), $this->sheet(), 'mr', [
            'opening' => SlotText::ai('आपल्या सहकार्याबद्दल आम्ही आभारी आहोत.'),
            'postscript' => SlotText::ai('This slot does not exist.'),
            'bill_1' => SlotText::ai('A model may not write the sentence that carries the figures.'),
        ]);

        $this->assertSame('आपल्या सहकार्याबद्दल आम्ही आभारी आहोत.', $document->slot('opening')->text);
        $this->assertSame(SlotText::AI, $document->slot('opening')->source);
        $this->assertSame(SlotText::TEMPLATE, $document->slot('closing')->source);
        $this->assertSame(RenderedSlot::CODE, $document->slot('bill_1')->source);
        $this->assertStringContainsString('₹1,50,000.00', $document->slot('bill_1')->text);
        $this->assertStringNotContainsString('does not exist', $document->text());
        $this->assertStringNotContainsString('A model may not', $document->text());
        $this->assertSame([
            'opening' => ['text' => 'आपल्या सहकार्याबद्दल आम्ही आभारी आहोत.', 'source' => 'ai'],
            'closing' => ['text' => self::CLOSING['mr'], 'source' => 'template'],
        ], $document->freeTexts());
    }

    public function test_free_text_is_never_searched_for_tokens(): void
    {
        $document = $this->renderer()->render($this->letter(), $this->sheet(), 'en', [
            'opening' => SlotText::edited('Please see {{bill_1_outstanding}} below.'),
            'closing' => SlotText::ai('The total is {{bill_1_outstanding}}.'),
        ]);

        // A person's braces stay as typed. The model's are refused by Guard A. Neither calls up the figure.
        $this->assertSame('Please see {{bill_1_outstanding}} below.', $document->slot('opening')->text);
        $this->assertSame(self::CLOSING['en'], $document->slot('closing')->text);
        $this->assertSame(1, substr_count($document->text(), '₹1,50,000.00'));
    }

    public function test_a_value_that_looks_like_a_token_is_printed_as_text_and_not_filled_in(): void
    {
        $sheet = $this->sheet(['client_name' => '{{bill_1_outstanding}} {{as_on}}']);
        $type = new DocumentType('note', [
            Slot::fixed('to', ['en' => 'To {{client_name}}, amount {{outstanding}}.', 'hi' => '{{client_name}}: {{outstanding}}', 'mr' => '{{client_name}}: {{outstanding}}'], ['client_name', 'outstanding' => 'bill_1_outstanding']),
        ]);

        $document = $this->renderer()->render($type, $sheet, 'en');

        $this->assertSame('To {{bill_1_outstanding}} {{as_on}}, amount ₹1,50,000.00.', $document->text());
        $this->assertSame(['client_name', 'bill_1_outstanding'], $document->factKeys());
    }

    public function test_a_fact_typed_by_a_person_may_carry_digits_inside_its_own_span(): void
    {
        $sheet = FactSheet::of(new Fact('reference_note', Fact::TEXT, 'Our letter no. 12 of file 4', 'Reference as typed', FactSource::entered('reference_note')));
        $type = new DocumentType('note', [Slot::fixed('ref', ['en' => 'Reference: {{reference_note}}', 'hi' => 'संदर्भ: {{reference_note}}', 'mr' => 'संदर्भ: {{reference_note}}'], ['reference_note'])]);

        $document = $this->renderer()->render($type, $sheet, 'mr');

        $this->assertSame('संदर्भ: Our letter no. 12 of file 4', $document->text());
        $this->assertSame(['reference_note'], $document->factKeys());
    }

    public function test_a_document_cannot_be_written_without_a_fact_its_template_states(): void
    {
        $withoutDate = FactSheet::of(...array_filter($this->sheet()->all(), fn (Fact $fact) => $fact->key !== 'as_on'));

        $this->expectException(WordingException::class);
        $this->expectExceptionMessage("No fact 'as_on'");

        $this->renderer()->render($this->letter(), $withoutDate, 'en');
    }

    public function test_composing_refuses_templates_that_do_not_fit_the_slots(): void
    {
        $good = ['opening' => self::OPENING['en'], 'bill_1' => self::BILL_LINE['en'], 'closing' => self::CLOSING['en']];

        foreach ([
            'a missing template' => array_diff_key($good, ['closing' => true]),
            'a token the slot does not declare' => ['bill_1' => 'Bill {{number}} {{outstanding}} {{as_on}} {{days_open}}'] + $good,
            'a declared fact left out' => ['bill_1' => 'Bill {{number}} as on {{as_on}}.'] + $good,
            'a token with spaces' => ['bill_1' => 'Bill {{number}} {{outstanding}} {{as_on}} {{ number }}'] + $good,
        ] as $what => $templates) {
            try {
                $this->renderer()->compose($this->letter(), $templates, $this->sheet(), 'en');
                $this->fail("Composed with {$what}.");
            } catch (WordingException $e) {
                $this->assertNotInstanceOf(GuardViolation::class, $e, $what);
            }
        }
    }
}
