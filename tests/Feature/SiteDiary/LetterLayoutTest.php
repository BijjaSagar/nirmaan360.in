<?php

namespace Tests\Feature\SiteDiary;

use App\Models\SiteLetter;
use App\Services\SiteDiary\LetterFacts;
use App\Services\SiteDiary\LetterLayout;
use App\Services\Wording\DocumentType;
use App\Services\Wording\Fact;
use App\Services\Wording\FactFormat;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Services\Wording\Renderer;
use App\Services\Wording\Slot;
use App\Services\Wording\SlotGuard;
use App\Services\Wording\TemplateLoader;
use InvalidArgumentException;

/**
 * The sentences of the three letters. Every sentence that states a figure is code text with a
 * token the figure goes into; the three sentences around them state none. All of it exists in
 * English, Hindi and Marathi, so a letter is complete with no AI model at all.
 */
class LetterLayoutTest extends SiteLetterTestCase
{
    private function layout(): LetterLayout
    {
        return app(LetterLayout::class);
    }

    private function sheet(string $type, array $chosen): FactSheet
    {
        return app(LetterFacts::class)->build($this->project, $type, $chosen, today());
    }

    /** @return list<string> */
    private function slotIds(DocumentType $type): array
    {
        return array_map(fn (Slot $slot) => $slot->id, $type->slots);
    }

    /** A request for more time that uses every sentence such a letter can have. */
    private function fullRequest(): FactSheet
    {
        $this->agreement();
        $this->reference('extension_granted', ['value_date' => '2026-09-30']);
        $this->notice(['code' => 'eot_notice', 'source_ref' => 'Sample agreement, part K']);
        $ended = $this->entry('2026-03-02', '2026-03-06', [], ['2026-03-02', '2026-03-04']);
        $ongoing = $this->entry('2026-03-12', null, ['category' => 'weather', 'description' => 'Heavy rain, site flooded']);

        return $this->sheet(self::EOT, ['hindrance_ids' => [$ended->id, $ongoing->id], 'addressed_to' => 'The Engineer in charge']);
    }

    /** A payment follow-up with one part-paid bill that carries TDS on its receipt. */
    private function fullFollowUp(): FactSheet
    {
        $bill = $this->clientBill('SG1/RA/1', '250000.00', [], '5000.00');
        $this->receipt($bill, '100000.00', '2000.00');

        return $this->sheet(self::PAYMENT, ['bill_ids' => [$bill->id, $this->clientBill('SG1/RA/2', '1200.00')->id]]);
    }

    public function test_the_layout_of_a_letter_follows_from_its_facts(): void
    {
        $request = $this->layout()->type(self::EOT, $this->fullRequest());

        $this->assertSame([
            'date_line', 'to_line', 'subject', 'reference', 'opening', 'completion', 'extension', 'linking',
            'h1_title', 'h1_what', 'h1_period', 'h1_reports',
            'h2_title', 'h2_what', 'h2_period', 'h2_reports',
            'total', 'request', 'clause_1', 'closing', 'signature',
        ], $this->slotIds($request));
        $this->assertSame(['opening', 'linking', 'closing'], $request->freeIds());
        $this->assertSame(self::EOT, $request->key);

        // The same entries as a notice of delay: no completion date, no total and no request.
        $ongoing = $this->entry('2026-03-13', null);
        $this->notice(['code' => 'delay_notice', 'source_ref' => 'Sample agreement, part H']);
        $notice = $this->layout()->type(self::DELAY, $this->sheet(self::DELAY, ['hindrance_ids' => [$ongoing->id]]));
        $this->assertSame(['date_line', 'subject', 'reference', 'opening', 'linking', 'h1_title', 'h1_what', 'h1_period', 'h1_reports', 'clause_1', 'closing', 'signature'], $this->slotIds($notice));

        $followUp = $this->layout()->type(self::PAYMENT, $this->fullFollowUp());
        $this->assertSame([
            'date_line', 'subject', 'reference', 'opening', 'linking',
            'bill1', 'bill1_tds', 'bill1_due', 'bill1_age',
            'bill2', 'bill2_due', 'bill2_age',
            'total_due', 'closing', 'signature',
        ], $this->slotIds($followUp));

        // Every fact a letter prints is declared by a fixed sentence, and the category is the one fact that is not printed as a figure.
        $sheet = $this->sheet(self::EOT, ['hindrance_ids' => [$ongoing->id]]);
        $this->assertSame(['h1_category'], array_values(array_diff($sheet->keys(), $this->layout()->type(self::EOT, $sheet)->factKeys())));

        $this->expectException(InvalidArgumentException::class);
        $this->layout()->type('reminder', $sheet);
    }

    public function test_every_sentence_exists_in_three_languages_and_none_states_a_figure_of_its_own(): void
    {
        $loader = app(TemplateLoader::class);

        // Every sentence a letter can have, with every category the register knows.
        $loader->assertComplete($this->layout()->type(self::EOT, $this->fullRequest()));
        $loader->assertComplete($this->layout()->type(self::PAYMENT, $this->fullFollowUp()));

        foreach (array_keys(config('modules.site_diary.categories')) as $at => $category) {
            $entry = $this->entry('2026-03-1'.$at, null, ['category' => $category], []);
            $loader->assertComplete($this->layout()->type(self::DELAY, $this->sheet(self::DELAY, ['hindrance_ids' => [$entry->id]])));
        }

        // The two shorter forms of the reference line.
        $common = [
            new Fact('letter_date', Fact::DATE, '2026-03-20', 'Date of the letter', FactSource::computed('letter_date')),
            new Fact('project_name', Fact::TEXT, 'Lake View Residency', 'Name of the work', FactSource::record('project', 1, 'name')),
            new Fact('company_name', Fact::TEXT, 'Shivganga Buildcon', 'Name of your company', FactSource::record('organization', 1, 'name')),
            new Fact('total_outstanding', Fact::MONEY, '10.00', 'Total', FactSource::computed('total_outstanding')),
        ];
        $number = new Fact('agreement_no', Fact::REFERENCE, 'SG/LVR/07', 'Agreement number', FactSource::record('contract_term', 1, 'value_text'));
        $date = new Fact('agreement_date', Fact::DATE, '2025-09-15', 'Agreement date', FactSource::record('contract_term', 2, 'value_date'));

        foreach ([[$number], [$date]] as $reference) {
            $loader->assertComplete($this->layout()->type(self::PAYMENT, new FactSheet([...$common, ...$reference])));
        }

        // No sentence of the code carries a numeral, in any of the three languages. A figure is always a token.
        $guard = app(SlotGuard::class);
        $sentences = [...array_values(LetterLayout::FIXED), ...array_merge(...array_map('array_values', array_values(LetterLayout::FREE)))];
        $this->assertCount(33, $sentences);

        foreach ($sentences as $english) {
            foreach (FactFormat::LOCALES as $locale) {
                $text = __($english, [], $locale);
                $this->assertSame(0, preg_match('/\p{N}/u', $text), "A numeral is written in: {$text}");
                $this->assertSame(Slot::tokens($english), array_values(array_intersect(Slot::tokens($english), Slot::tokens($text))), "A token is missing in: {$text}");
            }
        }

        // The sentences around the figures pass every rule a model's sentence has to pass, so they state no figure in words either.
        foreach (LetterLayout::FREE as $type => $free) {
            $this->assertSame(['opening', 'linking', 'closing'], array_keys($free), $type);

            foreach ($free as $english) {
                foreach (FactFormat::LOCALES as $locale) {
                    $this->assertSame([], $guard->check(__($english, [], $locale), $locale), __($english, [], $locale));
                }
            }
        }

        $this->assertSame(array_keys(LetterLayout::FREE), SiteLetter::TYPES);
        $this->assertSame(['opening', 'linking', 'closing'], array_keys(LetterLayout::PURPOSES));
    }

    public function test_what_a_model_would_be_told_about_the_three_sentences_carries_no_figure_and_no_record(): void
    {
        foreach ([[self::EOT, $this->fullRequest()], [self::PAYMENT, $this->fullFollowUp()]] as [$kind, $sheet]) {
            $brief = $this->layout()->type($kind, $sheet)->brief($sheet);
            $told = json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->assertSame(['opening', 'linking', 'closing'], array_column($brief, 'id'));
            $this->assertSame(0, preg_match('/\p{N}/u', $told), $told);

            // No value of the sheet is in it: no date, name, amount, clause or description, only what kind of thing stands nearby.
            foreach ($sheet as $fact) {
                if (mb_strlen($fact->value) > 2) {
                    $this->assertStringNotContainsString($fact->value, $told, $fact->key);
                }
            }

            foreach ($brief as $slot) {
                $this->assertSame(LetterLayout::PURPOSES[$slot['id']], $slot['purpose']);
            }
        }

        // The labels of the facts beside a sentence are named, and a label with a number in it is left out.
        $brief = $this->layout()->type(self::EOT, $sheet = $this->sheet(self::EOT, ['hindrance_ids' => [$this->entry('2026-03-10', '2026-03-11')->id]]))->brief($sheet);
        $this->assertContains('Stipulated completion date, as entered under Contract terms', $brief[0]['nearby_facts']);
        $this->assertContains('Name of your company', $brief[2]['nearby_facts']);
    }

    public function test_a_request_for_more_time_reads_the_same_in_english_hindi_and_marathi_with_the_same_figures(): void
    {
        $sheet = $this->fullRequest();
        $type = $this->layout()->type(self::EOT, $sheet);
        $letter = fn (string $language) => $this->layout()->text(app(Renderer::class)->render($type, $sheet, $language));

        $this->assertSame(implode("\n\n", [
            "Date: 20 March 2026\nTo: The Engineer in charge",
            "Subject: Request for extension of time for the work Lake View Residency\nReference: Agreement SG/LVR/07 dated 15 September 2025",
            'Dear Sir or Madam, we write to request more time to complete the work named above, for the reasons set out below.',
            'The stipulated date of completion of the work is 31 August 2026.',
            'The time for completion has since been extended up to 30 September 2026.',
            'The following hindrances to the work are recorded in our daily site reports.',
            "Drawings or decisions awaited\nPodium slab drawings awaited\nFrom 2 March 2026 to 6 March 2026: 5 calendar days, counting the first and the last day.\nRecorded in our daily site reports on 2 dates, from 2 March 2026 to 4 March 2026.",
            "Weather\nHeavy rain, site flooded\nFrom 12 March 2026 and still going on: 9 calendar days up to 20 March 2026, counting the first day and that day.\nRecorded in our daily site reports of 12 March 2026.",
            'Counting every calendar day once, also where hindrances overlap, these come to 14 days.',
            // 30 September and 14 days more.
            'We request that the time for completion be extended up to 14 October 2026.',
            'Reference in the agreement: Sample agreement, part K',
            'We request you to consider the above and to grant the extension of time. Kindly acknowledge receipt of this letter.',
            'For Shivganga Buildcon',
        ]), $letter('en'));

        $hindi = $letter('hi');
        $marathi = $letter('mr');

        foreach ([
            'दिनांक: 20 मार्च 2026',
            'विषय: कार्य Lake View Residency के लिए समय-वृद्धि का अनुरोध',
            'संदर्भ: अनुबंध SG/LVR/07, दिनांक 15 सितंबर 2025',
            'कार्य पूरा करने की निर्धारित तारीख़ 31 अगस्त 2026 है।',
            'इसके बाद कार्य पूरा करने का समय 30 सितंबर 2026 तक बढ़ाया गया है।',
            "ड्रॉइंग या निर्णय का इंतज़ार\nPodium slab drawings awaited\n2 मार्च 2026 से 6 मार्च 2026 तक: 5 कैलेंडर दिन, पहला और आख़िरी दिन गिनकर।",
            '12 मार्च 2026 से, अभी जारी: 20 मार्च 2026 तक 9 कैलेंडर दिन, पहला दिन और वह दिन गिनकर।',
            'ये कुल 14 दिन होते हैं।',
            'हमारा अनुरोध है कि कार्य पूरा करने का समय 14 अक्टूबर 2026 तक बढ़ाया जाए।',
            'अनुबंध में संदर्भ: Sample agreement, part K',
            'कृते Shivganga Buildcon',
        ] as $line) {
            $this->assertStringContainsString($line, $hindi);
        }

        foreach ([
            'दिनांक: 20 मार्च 2026',
            'विषय: Lake View Residency या कामासाठी मुदतवाढ मिळण्याबाबत विनंती',
            'संदर्भ: करारनामा SG/LVR/07, दिनांक 15 सप्टेंबर 2025',
            'काम पूर्ण करण्याची ठरलेली तारीख 31 ऑगस्ट 2026 आहे.',
            'त्यानंतर काम पूर्ण करण्याची मुदत 30 सप्टेंबर 2026 पर्यंत वाढवण्यात आली आहे.',
            "हवामान\nHeavy rain, site flooded\n12 मार्च 2026 पासून, अजून सुरू: 20 मार्च 2026 पर्यंत 9 कॅलेंडर दिवस, पहिला दिवस आणि तो दिवस धरून.",
            'आमच्या दैनिक साइट अहवालांत 2 तारखांना नोंद आहे, 2 मार्च 2026 ते 4 मार्च 2026.',
            'हे मिळून 14 दिवस होतात.',
            'काम पूर्ण करण्याची मुदत 14 ऑक्टोबर 2026 पर्यंत वाढवून मिळावी, ही विनंती.',
            'Shivganga Buildcon करिता',
        ] as $line) {
            $this->assertStringContainsString($line, $marathi);
        }

        // Digits are the same ASCII digits in every language, and each letter has every figure the English one has.
        preg_match_all('/\d+/', $letter('en'), $english);

        foreach ([$hindi, $marathi] as $text) {
            $this->assertSame(0, preg_match('/[०-९]/u', $text));
            preg_match_all('/\d+/', $text, $found);
            $this->assertEqualsCanonicalizing($english[0], $found[0]);
            $this->assertStringNotContainsString('{{', $text);
        }
    }

    public function test_a_payment_follow_up_states_each_amount_in_a_sentence_of_the_code(): void
    {
        $sheet = $this->fullFollowUp();
        $type = $this->layout()->type(self::PAYMENT, $sheet);
        $document = app(Renderer::class)->render($type, $sheet, 'en');

        $this->assertSame(implode("\n\n", [
            'Date: 20 March 2026',
            'Subject: Payment outstanding on our bills for the work Lake View Residency',
            'Dear Sir or Madam, we write about the payment still due on our bills for the work named above.',
            'The position of each bill, as per our records, is given below.',
            "Bill SG1/RA/1 dated 10 February 2026: amount receivable ₹2,55,000.00, received so far ₹1,00,000.00.\nTax deducted at source on this bill, as shown on the receipts: ₹2,000.00.\nThe amount outstanding on bill SG1/RA/1 is ₹1,53,000.00.\nDays from the bill date to the date of this letter: 38.",
            "Bill SG1/RA/2 dated 10 February 2026: amount receivable ₹1,200.00, received so far ₹0.00.\nThe amount outstanding on bill SG1/RA/2 is ₹1,200.00.\nDays from the bill date to the date of this letter: 38.",
            'The total outstanding on these bills is ₹1,54,200.00.',
            'We request you to release the outstanding amount at the earliest. Kindly acknowledge receipt of this letter.',
            'For Shivganga Buildcon',
        ]), $this->layout()->text($document));

        // Every figure of the letter is a fact the code placed, and each needs its own tick.
        $this->assertSame([
            'letter_date', 'project_name',
            'bill_1_number', 'bill_1_date', 'bill_1_receivable', 'bill_1_received', 'bill_1_tds', 'bill_1_outstanding', 'bill_1_days',
            'bill_2_number', 'bill_2_date', 'bill_2_receivable', 'bill_2_received', 'bill_2_outstanding', 'bill_2_days',
            'total_outstanding', 'company_name',
        ], $document->ticksNeeded());

        $marathi = $this->layout()->text(app(Renderer::class)->render($type, $sheet, 'mr'));
        $this->assertStringContainsString('बिल SG1/RA/1, दिनांक 10 फेब्रुवारी 2026: येणे रक्कम ₹2,55,000.00, आतापर्यंत मिळालेली ₹1,00,000.00.', $marathi);
        $this->assertStringContainsString('बिल SG1/RA/1 वर थकबाकी ₹1,53,000.00 आहे.', $marathi);
        $this->assertStringContainsString('या बिलांवर एकूण थकबाकी ₹1,54,200.00 आहे.', $marathi);

        $hindi = $this->layout()->text(app(Renderer::class)->render($type, $sheet, 'hi'));
        $this->assertStringContainsString('बिल SG1/RA/1 पर बकाया राशि ₹1,53,000.00 है।', $hindi);
        $this->assertStringContainsString('बिल की तारीख़ से इस पत्र की तारीख़ तक के दिन: 38।', $hindi);
    }

    public function test_every_fact_has_a_label_in_the_readers_language_and_says_where_it_was_read(): void
    {
        $sheets = [$this->fullRequest(), $this->fullFollowUp()];
        $typed = $this->sheet(self::EOT, ['hindrance_ids' => [$this->entry('2026-03-02', '2026-03-03')->id], 'proposed_completion' => '2026-10-31']);

        foreach (['en', 'hi', 'mr'] as $locale) {
            app()->setLocale($locale);

            foreach ($sheets as $sheet) {
                foreach ($sheet as $fact) {
                    $label = $this->layout()->label($fact);
                    $source = $this->layout()->source($fact);

                    $this->assertNotSame('', trim($label), $fact->key);
                    $this->assertStringNotContainsString(':index', $label, $fact->key);
                    $this->assertSame($locale === 'en', preg_match('/\p{Devanagari}/u', $label) === 0, "{$locale}: {$fact->key} reads '{$label}'");
                    $this->assertSame($locale === 'en', preg_match('/\p{Devanagari}/u', $source) === 0, "{$locale}: {$fact->key} is from '{$source}'");
                }
            }
        }

        app()->setLocale('en');
        $request = $sheets[0];
        $this->assertSame('Hindrance 2: first day', $this->layout()->label($request->get('h2_start')));
        $this->assertSame('Hindrance 2: first day', $request->get('h2_start')->label, 'The sheet keeps the English label.');
        $this->assertSame('Clause or page 1 in the agreement, as typed under Contract terms', $this->layout()->label($request->get('clause_1')));
        $this->assertSame('Bill 2: outstanding', $this->layout()->label($sheets[1]->get('bill_2_outstanding')));
        $this->assertSame('Proposed completion date, worked out by the app', $this->layout()->label($request->get('proposed_completion')));
        $this->assertSame('Proposed completion date, entered by you', $this->layout()->label($typed->get('proposed_completion')));

        $this->assertSame('Hindrance register', $this->layout()->source($request->get('h1_start')));
        $this->assertSame('Daily reports cited in the register', $this->layout()->source($request->get('h1_first_report')));
        $this->assertSame('Contract terms, as entered and confirmed', $this->layout()->source($request->get('stipulated_completion')));
        $this->assertSame('Worked out by the app', $this->layout()->source($request->get('total_days')));
        $this->assertSame('Typed by you on this letter', $this->layout()->source($request->get('addressed_to')));
        $this->assertSame('Client bills and the receipts recorded on them', $this->layout()->source($sheets[1]->get('bill_1_received')));

        app()->setLocale('mr');
        $this->assertSame('अडथळा 2: पहिला दिवस', $this->layout()->label($request->get('h2_start')));
        app()->setLocale('hi');
        $this->assertSame('बिल 2: बकाया', $this->layout()->label($sheets[1]->get('bill_2_outstanding')));

        // A fact nobody wrote a label for is a fault in the code, not something to print without one.
        $this->expectException(InvalidArgumentException::class);
        LetterLayout::englishLabel('h1_weather');
    }
}
