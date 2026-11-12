<?php

namespace Tests\Feature\Wording;

use App\Services\Wording\DocumentType;
use App\Services\Wording\Fact;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Services\Wording\Slot;
use App\Services\Wording\SlotIntake;
use App\Services\Wording\SlotText;
use App\Services\Wording\Violation;

/** Guard B: only text for a declared free slot is kept, and what is kept has passed Guard A. */
class SlotIntakeTest extends WordingTestCase
{
    private function intake(): SlotIntake
    {
        return app(SlotIntake::class);
    }

    public function test_text_for_a_free_slot_is_kept_as_written_by_the_model(): void
    {
        $draft = $this->intake()->accept($this->letter(), ['slots' => [
            ['id' => 'opening', 'text' => "  We hope this letter finds you well.\r\nWe write about our running bills.  "],
            ['id' => 'closing', 'text' => 'We look forward to hearing from you.'],
        ]], 'en');

        $this->assertSame("We hope this letter finds you well.\nWe write about our running bills.", $draft->accepted['opening']);
        $this->assertSame([], $draft->rejected);
        $this->assertSame([], $draft->dropped);
        $this->assertSame([], $draft->missing);
        $this->assertFalse($draft->hasRejections());
        $this->assertFalse($draft->malformed);
        $this->assertEquals(SlotText::ai('We look forward to hearing from you.'), $draft->texts()['closing']);
    }

    public function test_an_id_that_is_not_a_declared_free_slot_is_dropped(): void
    {
        $draft = $this->intake()->accept($this->letter(), ['slots' => [
            // The fixed slot carries the figures. A model may never write it.
            ['id' => 'bill_1', 'text' => 'The amount outstanding is small.'],
            ['id' => 'postscript', 'text' => 'An extra paragraph nobody asked for.'],
            ['id' => 'opening', 'text' => 'We write about our running bills.'],
            ['id' => 'opening', 'text' => 'A second opening.'],
            ['id' => 7, 'text' => 'No id.'],
            'not an entry',
        ]], 'en');

        $this->assertSame(['opening' => 'We write about our running bills.'], $draft->accepted);
        $this->assertSame(['bill_1', 'postscript', 'opening'], $draft->dropped);
        $this->assertSame(['closing'], $draft->missing);
        $this->assertArrayNotHasKey('bill_1', $draft->texts());
        $this->assertArrayNotHasKey('postscript', $draft->texts());
    }

    public function test_a_slot_that_states_a_figure_is_refused_and_the_others_are_kept(): void
    {
        $draft = $this->intake()->accept($this->letter(), ['slots' => [
            ['id' => 'opening', 'text' => 'Our bills have been pending for fifteen days and ₹1,50,000.00 is due.'],
            ['id' => 'closing', 'text' => 'We look forward to your reply.'],
        ]], 'en');

        $this->assertTrue($draft->hasRejections());
        $this->assertSame(['closing' => 'We look forward to your reply.'], $draft->accepted);
        $this->assertSame(['opening'], array_keys($draft->rejected));
        $this->assertEqualsCanonicalizing(
            [Violation::DIGIT, Violation::CURRENCY, Violation::NUMBER_WORD],
            $draft->rejectedRules()['opening'],
        );
        $this->assertSame([], $draft->missing);
        $this->assertArrayNotHasKey('opening', $draft->texts());

        // The rules can be logged and sent back in a corrective request. The refused text is not among them.
        $this->assertStringNotContainsString('1,50,000', json_encode($draft->rejectedRules()));
    }

    public function test_a_token_placed_by_the_model_is_refused(): void
    {
        $draft = $this->intake()->accept($this->letter(), ['slots' => [
            ['id' => 'opening', 'text' => 'The amount due is {{outstanding}}.'],
        ]], 'en');

        $this->assertSame([Violation::TOKEN], $draft->rejectedRules()['opening']);
        $this->assertSame([], $draft->accepted);
    }

    public function test_an_empty_text_or_a_missing_slot_is_left_to_the_code_text(): void
    {
        $draft = $this->intake()->accept($this->letter(), ['slots' => [['id' => 'opening', 'text' => "  \n "]]], 'hi');

        $this->assertSame([], $draft->accepted);
        $this->assertSame([], $draft->rejected);
        $this->assertSame(['opening', 'closing'], $draft->missing);
    }

    public function test_an_answer_without_the_agreed_shape_keeps_nothing(): void
    {
        foreach ([null, 'We write about our bills.', ['slots' => 'opening'], ['text' => 'x'], []] as $answer) {
            $draft = $this->intake()->accept($this->letter(), $answer, 'en');

            $this->assertTrue($draft->malformed);
            $this->assertSame([], $draft->texts());
            $this->assertSame(['opening', 'closing'], $draft->missing);
        }
    }

    public function test_text_longer_than_the_slot_allows_is_cut_at_a_word(): void
    {
        $type = new DocumentType('note', [Slot::free('opening', 'Open the note.', self::OPENING, maxChars: 40)]);
        $draft = $this->intake()->accept($type, ['slots' => [['id' => 'opening', 'text' => 'We write to you about the running bills of the work in hand.']]], 'en');

        $this->assertSame('We write to you about the running bills', $draft->accepted['opening']);

        config(['wording.free_slot_max_chars' => 20]);
        $draft = app(SlotIntake::class)->accept($this->letter(), ['slots' => [['id' => 'closing', 'text' => 'Yours faithfully, the site team']]], 'en');

        $this->assertSame('Yours faithfully,', $draft->accepted['closing']);
    }

    public function test_the_shape_asked_of_the_model_is_one_fixed_schema(): void
    {
        $schema = DocumentType::OUTPUT_SCHEMA;

        $this->assertSame(['slots'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['id', 'text'], $schema['properties']['slots']['items']['required']);
        $this->assertFalse($schema['properties']['slots']['items']['additionalProperties']);
        $this->assertSame(['type' => 'string'], $schema['properties']['slots']['items']['properties']['text']);
    }

    public function test_the_model_is_told_labels_and_never_values(): void
    {
        $sheet = $this->sheet();
        $brief = $this->letter()->brief($sheet);

        $this->assertSame(['opening', 'closing'], array_column($brief, 'id'));
        $this->assertSame('Open the letter politely and say it is about unpaid bills.', $brief[0]['purpose']);
        $this->assertSame(['Bill number', 'Outstanding amount', 'As on date'], $brief[0]['nearby_facts']);
        $this->assertSame(['Bill number', 'Outstanding amount', 'As on date'], $brief[1]['nearby_facts']);

        $sent = json_encode($brief, JSON_UNESCAPED_UNICODE);

        foreach ($sheet as $fact) {
            $this->assertStringNotContainsString($fact->value, $sent, $fact->key);

            foreach (['en', 'hi', 'mr'] as $locale) {
                $this->assertStringNotContainsString($fact->display($locale), $sent, $fact->key);
            }
        }

        $this->assertSame(0, preg_match('/\p{N}/u', $sent));
    }

    public function test_a_label_that_carries_a_figure_or_a_value_is_not_told_to_the_model(): void
    {
        $sheet = FactSheet::of(
            new Fact('client_name', Fact::TEXT, 'Acme Buildcon', 'Name of Acme Buildcon', FactSource::record('party', 31, 'name')),
            new Fact('amount', Fact::MONEY, '150000', 'Outstanding on bill 3', FactSource::record('ra_bill', 7, 'outstanding')),
            new Fact('as_on', Fact::DATE, '2026-11-05', 'As on date', FactSource::computed('as_on')),
        );
        $type = new DocumentType('note', [
            Slot::fixed('line', ['en' => '{{client_name}}: {{amount}} on {{as_on}}', 'hi' => '{{client_name}}: {{amount}}, {{as_on}}', 'mr' => '{{client_name}}: {{amount}}, {{as_on}}'], ['client_name', 'amount', 'as_on']),
            Slot::free('closing', 'Close the note.', self::CLOSING),
        ]);

        $this->assertSame(['As on date'], $type->brief($sheet)[0]['nearby_facts']);
    }
}
