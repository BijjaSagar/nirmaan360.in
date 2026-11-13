<?php

namespace Tests\Feature\BillCheck;

use App\Models\BillCheck;
use App\Services\BillCheck\Explain\BillCheckExplanations;
use App\Services\BillCheck\Explain\ExplanationDocument;
use App\Services\BillCheck\Explain\FindingTopics;
use App\Services\BillCheck\Flag;
use App\Services\BillCheck\FlagCatalog;
use App\Services\BillCheck\FlagText;
use App\Services\Wording\Renderer;
use App\Services\Wording\SlotGuard;
use App\Services\Wording\TemplateLoader;

/**
 * The explanation as a document of slots: every finding the catalogue can make reads in three
 * languages with its figures placed by the code, the plain form gives no private figure away, every
 * topic sentence keeps the rules a model's text is held to, and only topic sentences are left for a model.
 */
class ExplanationDocumentTest extends BillCheckTestCase
{
    /** One made-up value for each kind of figure, as in BillCheckWordingTest. */
    private const SAMPLES = [
        'money' => '12345.67', 'pct' => '7.25', 'source' => 'Sample agreement, part Z', 'qty' => '3.500',
        'ratio' => '9.5', 'count' => 4, 'date' => '2026-11-30', 'text' => 'XYZ-9', 'head' => 'retention',
    ];

    private const PRIVATE_SHOWN = ['money' => '12,345.67', 'pct' => '7.25%', 'source' => 'Sample agreement, part Z'];

    private function sample(string $code, int $row): Flag
    {
        $definition = FlagCatalog::definition($code);

        return new Flag($code.':'.$row, $code, $definition['level'], array_map(fn (string $type) => self::SAMPLES[$type], $definition['params']));
    }

    /** @param  list<Flag>  $flags */
    private function storedCheck(array $flags): BillCheck
    {
        $this->measure('20');
        $bill = $this->clientBill();

        return BillCheck::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id, 'ra_bill_id' => $bill->id,
            'flags' => array_map(fn (Flag $flag) => $flag->toArray(), $flags), 'flags_hash' => str_repeat('a', 64),
            'summary' => ['blocking' => 0, 'tick' => 0, 'note' => 0], 'run_by' => $this->owner->id,
        ]);
    }

    public function test_every_finding_has_a_topic_and_every_topic_sentence_keeps_the_rules_of_a_models_text(): void
    {
        $this->assertSame([], FindingTopics::unassigned(), 'Every finding of the catalogue belongs to a topic.');
        $this->assertSame(FindingTopics::OTHER, FindingTopics::of('Z9'));
        $guard = app(SlotGuard::class);

        foreach (FindingTopics::TOPICS as $topic => $definition) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z_]*$/', $topic, 'A topic names a slot, so it has no digit.');

            foreach (['en', 'hi', 'mr'] as $locale) {
                $sentence = $locale === 'en' ? $definition['about'] : __($definition['about'], [], $locale);

                $this->assertSame([], $guard->check($sentence, $locale), "{$topic} {$locale}: {$sentence}");
                $this->assertLessThanOrEqual(ExplanationDocument::ABOUT_CHARS, mb_strlen($sentence), "{$topic} {$locale} is longer than a model may write.");
            }
        }
    }

    public function test_every_finding_reads_in_three_languages_with_its_figures_placed_by_the_code(): void
    {
        $flags = [];

        foreach (array_keys(FlagCatalog::DEFINITIONS) as $row => $code) {
            $flags[] = $this->sample($code, $row);
        }

        $flags[] = new Flag('Z9:1', 'Z9', Flag::TICK, ['amount' => '500.00']);
        $check = $this->storedCheck($flags);
        config(['modules.bill_check.explain.model_topics' => 100]);

        $documents = app(ExplanationDocument::class);
        $renderer = app(Renderer::class);
        $text = app(FlagText::class);

        foreach (['en', 'hi', 'mr'] as $locale) {
            foreach ([true, false] as $money) {
                $document = $documents->build($check, $locale, $money);
                app(TemplateLoader::class)->assertComplete($document->type);
                $rendered = $renderer->render($document->type, $document->sheet, $locale);

                // One topic slot per topic, before its first finding, and one finding slot per finding, in the check's order.
                $ids = array_map(fn ($slot) => $slot->id, $rendered->slots);
                $findings = array_values(array_filter($ids, fn (string $id) => str_starts_with($id, 'finding_')));
                $this->assertSame(array_map(fn (int $n) => 'finding_'.$n, range(1, count($flags))), $findings);
                $this->assertCount(count(FindingTopics::TOPICS), array_diff($ids, $findings));

                app()->setLocale($locale);

                foreach ($flags as $index => $flag) {
                    $line = $rendered->slot('finding_'.($index + 1))->text;
                    $types = FlagCatalog::definition($flag->code)['params'] ?? [];

                    $this->assertSame(0, preg_match('/:[a-z_]+|\{\{|\}\}/', $line), "{$locale} {$flag->code}: {$line}");
                    $this->assertSame($locale !== 'en', (bool) preg_match('/\p{Devanagari}/u', $line), "{$locale} {$flag->code}: {$line}");

                    // The same sentence as the card, but for the date, which the explanation writes out in the document's language.
                    $dates = array_keys(array_filter($types, fn (string $type) => $type === 'date'));
                    $template = (string) FlagCatalog::template($flag->code, $money);

                    if (array_filter($dates, fn (string $name) => str_contains($template, ':'.$name)) === []) {
                        $this->assertSame($text->line($flag, $money), $line, "{$locale} {$flag->code}");
                    } else {
                        $this->assertStringContainsString('30 '.config("wording.months.{$locale}.11").' 2026', $line, "{$locale} {$flag->code}");
                    }

                    foreach ($types as $type) {
                        if (isset(self::PRIVATE_SHOWN[$type])) {
                            $this->assertSame($money, str_contains($line, self::PRIVATE_SHOWN[$type]), "{$locale} {$flag->code} money=".var_export($money, true).": {$line}");
                        }
                    }

                    if (! $money) {
                        $this->assertStringNotContainsString('₹', $line);
                    }
                }

                app()->setLocale('en');
            }
        }
    }

    public function test_the_model_is_asked_only_about_topics_and_past_the_limit_the_apps_sentence_stands(): void
    {
        $codes = ['A1.amount', 'A2', 'A4', 'B3', 'B4', 'C3', 'D2', 'D3', 'D6', 'F1'];
        $flags = array_map(fn (string $code) => $this->sample($code, 1), $codes);
        $flags[] = $this->sample('A1.amount', 2);
        $check = $this->storedCheck($flags);
        config(['modules.bill_check.explain.model_topics' => 4]);

        $document = app(ExplanationDocument::class)->forModel($check, 'mr');

        $this->assertSame(['about_line_amount', 'about_gross', 'about_negative_net', 'about_earlier_open'], $document->type->freeIds());
        $this->assertTrue($document->type->slot('about_overlap')->isFixed());
        $this->assertSame(10, count(array_filter($document->type->slots, fn ($slot) => str_starts_with($slot->id, 'about_'))), 'Ten topics, the second line amount finding shares the first one\'s.');

        // What the model is told: what each slot is for and the finding's sentence without a figure. Never a value.
        $brief = json_encode($document->type->brief($document->sheet), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Item …: the amount on the bill is not its quantity times its rate.', $brief);
        $this->assertDoesNotMatchRegularExpression('/\p{N}/u', $brief);

        foreach (['XYZ', '12345', '12,345', '3.500', '7.25', 'Sample agreement', 'retention', 'November'] as $value) {
            $this->assertStringNotContainsString($value, $brief);
        }

        // The document a model is asked about is the plain one: it holds no rupee figure, percentage or term words at all.
        foreach ($document->sheet as $fact) {
            $this->assertNotContains($fact->kind, ['money'], $fact->key);
            $this->assertNotSame('Sample agreement, part Z', $fact->value);
        }
    }

    public function test_a_check_that_found_nothing_has_no_explanation(): void
    {
        $check = $this->storedCheck([]);

        $this->assertNull(app(ExplanationDocument::class)->forModel($check, 'en'));
        $this->assertSame([], app(BillCheckExplanations::class)->present($check, 'en', $this->owner)['paragraphs']);
    }
}
