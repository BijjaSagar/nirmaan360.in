<?php

namespace Tests\Feature\SiteDiary;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\ContractTerm;
use App\Models\Hindrance;
use App\Models\SiteLetter;
use App\Models\SiteLetterHindrance;
use App\Services\SiteDiary\LetterLayout;
use App\Services\SiteDiary\NoticeDeadlineSource;
use App\Services\SiteDiary\SiteLetters;
use App\Services\Wording\SlotText;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** The letters screen and its writes: who may use them, what they keep, and what stops a letter from being finalised. */
class SiteLetterHttpTest extends SiteLetterTestCase
{
    private function index($project = null): string
    {
        return route('site-diary.letters.index', $project ?? $this->project);
    }

    private function store($project = null): string
    {
        return route('site-diary.letters.store', $project ?? $this->project);
    }

    private function show(SiteLetter $letter): string
    {
        return route('site-diary.letters.show', $letter);
    }

    private function update(SiteLetter $letter): string
    {
        return route('site-diary.letters.update', $letter);
    }

    private function finalise(SiteLetter $letter): string
    {
        return route('site-diary.letters.finalise', $letter);
    }

    /**
     * Sends the finalise form: these ticks, or every tick the draft needs, given on the draft as it stands now
     * unless $seen says which draft the person was looking at.
     */
    private function tick(SiteLetter $letter, ?array $ticks = null, ?string $seen = null): TestResponse
    {
        return $this->post($this->finalise($letter), ['number_ticks' => $ticks ?? $this->ticks($letter), 'seen' => $seen ?? $letter->refresh()->seal()]);
    }

    /** A figure as the draft page marks it. */
    private function marked(string $label, string $text): string
    {
        return '<mark class="rounded-[2px] bg-haldi/20 px-1 text-ink" title="'.e($label).'">'.e($text).'</mark>';
    }

    public function test_the_letters_page_offers_what_can_be_cited_and_chooses_nothing_for_the_person(): void
    {
        $ended = $this->entry('2026-03-02', '2026-03-06', ['description' => 'Podium slab drawings awaited']);
        $ongoing = $this->entry('2026-03-12', null, ['category' => 'weather', 'description' => 'Heavy rain, site flooded']);
        $this->entry('2026-03-08', '2026-03-09', ['status' => Hindrance::DISMISSED, 'description' => 'Dismissed entry about the crane']);
        $this->entry('2026-03-08', '2026-03-09', ['status' => Hindrance::PROPOSED, 'confirmed_by' => null, 'confirmed_at' => null, 'description' => 'Proposed entry about power']);
        $this->entry('2026-03-02', '2026-03-06', ['description' => 'Entry of the other project'], null, $this->makeProject($this->org, ['code' => 'SG2']));
        $unpaid = $this->clientBill('SG1/RA/1', '250000.00');
        $paid = $this->clientBill('SG1/RA/2', '4000.00');
        $this->receipt($paid, '4000.00');
        // Marked paid with the button on its RA bill page, which records no receipt.
        $marked = $this->clientBill('SG1/RA/3', '9000.00');
        $this->actingAs($this->pm)->post(route('ra-bills.paid', $marked))->assertRedirect();
        $this->assertSame('PAID', $marked->fresh()->status);

        $page = $this->actingAs($this->pm)->get($this->index())->assertOk()
            ->assertSee('Prepare a letter')
            ->assertSee('Every date, day count, quantity and amount, and the sentence that states it, is placed by the app from your records. Check the draft against your agreement before sending.')
            ->assertSee('The app sends nothing. You print the letter or copy its text.')
            ->assertSee('No letters yet')
            ->assertSee('Request for extension of time')->assertSee('Notice of delay')->assertSee('Payment follow-up')
            ->assertSee('02 Mar 2026 to 06 Mar 2026')->assertSee('Podium slab drawings awaited')
            ->assertSee('From 12 Mar 2026, still going on')->assertSee('Heavy rain, site flooded')
            ->assertDontSee('Dismissed entry about the crane')
            ->assertDontSee('Proposed entry about power')
            ->assertDontSee('Entry of the other project')
            ->assertSee('SG1/RA/1')->assertSee('Outstanding: ₹2,50,000.00')
            ->assertDontSee('SG1/RA/2')
            ->assertDontSee('SG1/RA/3')->assertDontSee('₹9,000.00')
            ->assertSee(route('site-diary.hindrances.index', $this->project), false);

        $html = $page->getContent();
        $this->assertSame(2, substr_count($html, 'name="hindrance_ids[]"'));
        $this->assertSame(1, substr_count($html, 'name="bill_ids[]"'));

        // No kind of letter, language, entry or bill is chosen for the person, and no date is filled in.
        $this->assertSame(0, preg_match('/<option value="[a-z_]+" selected/', $html));
        $this->assertSame(0, preg_match('/name="(hindrance_ids|bill_ids)\[\]"[^>]*checked/', $html));
        $this->assertSame(1, preg_match('/name="proposed_completion" type="date" value=""/', $html));
        $this->assertSame(1, preg_match('/name="addressed_to" value=""/', $html));

        // An entry that has ended is switched off for a notice of delay, and nothing is sent for a kind it does not belong to.
        $page->assertSee('value="'.$ended->id.'" class="mt-0.5 size-5 shrink-0"', false)
            ->assertSee(':disabled="type !== &#039;eot_request&#039;"', false)
            ->assertSee(':disabled="type !== &#039;eot_request&#039; &amp;&amp; type !== &#039;delay_notice&#039;"', false)
            ->assertSee(":disabled=\"type !== 'payment_followup'\"", false);

        $this->assertNotNull($ongoing);
        $this->assertNotNull($unpaid);

        // The register leads here for someone who may write letters, and for nobody else.
        $this->get(route('site-diary.hindrances.index', $this->project))->assertOk()->assertSee($this->index(), false);
        $this->actingAs($this->engineer)->get(route('site-diary.hindrances.index', $this->project))->assertOk()->assertDontSee($this->index(), false);
    }

    public function test_a_draft_is_prepared_once_from_the_records_and_kept_with_its_facts(): void
    {
        $this->agreement('2026-08-31');
        $first = $this->entry('2026-03-02', '2026-03-06');
        $second = $this->entry('2026-03-05', '2026-03-09', ['category' => 'weather', 'description' => 'Heavy rain, site flooded']);
        $form = $this->letterForm(self::EOT, ['language' => 'mr', 'hindrance_ids' => [$second->id, $first->id], 'addressed_to' => ' The Engineer in charge ']);

        $response = $this->actingAs($this->pm)->post($this->store(), $form)->assertSessionHasNoErrors()->assertSessionHas('success');
        $letter = SiteLetter::sole();
        $response->assertRedirect($this->show($letter));

        $this->assertSame([$this->org->id, $this->project->id], [$letter->organization_id, $letter->project_id]);
        $this->assertSame([self::EOT, 'mr', SiteLetter::DRAFT], [$letter->type, $letter->language, $letter->status]);
        $this->assertSame('The Engineer in charge', $letter->addressed_to);
        $this->assertSame('विषय: Lake View Residency या कामासाठी मुदतवाढ मिळण्याबाबत विनंती', $letter->subject);
        $this->assertSame($form['client_uuid'], $letter->client_uuid);
        $this->assertNull($letter->finalised_by);
        $this->assertNull($letter->finalised_at);
        $this->assertSame(['bill_ids' => [], 'proposed_completion' => null], $letter->inputs);
        $this->assertSame([], $letter->number_ticks);
        $this->assertSame([], $letter->typed_numbers);

        // The facts, their fingerprint and the text are kept together. 2 to 9 March is 8 days; 31 August and 8 days more.
        $facts = array_column($letter->facts, 'value', 'key');
        $this->assertSame(['2026-03-20', '8', '2026-09-08'], [$facts['letter_date'], $facts['total_days'], $facts['proposed_completion']]);
        $this->assertSame(64, strlen($letter->fact_fingerprint));
        $this->assertStringContainsString('काम पूर्ण करण्याची मुदत 8 सप्टेंबर 2026 पर्यंत वाढवून मिळावी, ही विनंती.', $letter->body_rendered);
        $this->assertStringContainsString("\n\nShivganga Buildcon करिता", $letter->body_rendered);

        // The three sentences around the figures are the app's own, since no AI model wrote anything.
        $this->assertSame(['opening' => 'template', 'linking' => 'template', 'closing' => 'template'], array_map(fn (array $slot) => $slot['source'], $letter->slots));

        // What it cites, each row with its company and project.
        $citations = SiteLetterHindrance::orderBy('hindrance_id')->get();
        $this->assertSame([$first->id, $second->id], $citations->pluck('hindrance_id')->all());
        $this->assertSame([[$this->org->id, $this->project->id]], $citations->map(fn ($row) => [$row->organization_id, $row->project_id])->unique()->values()->all());
        $this->assertSame([$first->id, $second->id], $letter->hindrances->pluck('id')->all());

        // A draft locks nothing and changes no entry.
        $this->assertNull($first->refresh()->locked_at);

        // The audit log says what was prepared and from what, and holds no sentence and no figure.
        $audit = AuditLog::where('action', 'site_letter.prepared')->sole();
        $this->assertSame([$this->pm->id, $letter->getMorphClass(), $letter->id], [$audit->user_id, $audit->auditable_type, $audit->auditable_id]);
        $this->assertSame([self::EOT, 'mr', [$first->id, $second->id], [], $letter->fact_fingerprint], [$audit->new_values['type'], $audit->new_values['language'], $audit->new_values['hindrance_ids'], $audit->new_values['bill_ids'], $audit->new_values['fact_fingerprint']]);
        $this->assertStringNotContainsString('2026', json_encode($audit->new_values));
        $this->assertStringNotContainsString('Engineer', json_encode($audit->new_values));

        // Pressing the button twice prepares one letter, even if the records changed in between.
        $second->update(['ended_on' => '2026-03-10']);
        $this->post($this->store(), $form)->assertRedirect($this->show($letter));
        $this->assertSame(1, SiteLetter::count());
        $this->assertSame($letter->fact_fingerprint, $letter->refresh()->fact_fingerprint);

        // The same request id on another project is an error, not a second letter.
        $elsewhere = $this->makeProject($this->org, ['code' => 'SG2']);
        $this->agreement('2026-08-31', $elsewhere);
        $this->post($this->store($elsewhere), [...$form, 'hindrance_ids' => [$this->entry('2026-03-02', '2026-03-03', [], null, $elsewhere)->id]])
            ->assertSessionHasErrors(['client_uuid' => 'This request id was already used on another project.']);
        $this->assertSame(1, SiteLetter::count());
    }

    public function test_the_form_is_checked_before_anything_is_prepared(): void
    {
        $this->agreement();
        $ended = $this->entry('2026-03-02', '2026-03-06');
        $ongoing = $this->entry('2026-03-12', null);
        $bill = $this->clientBill('SG1/RA/1', '250000.00');
        $this->actingAs($this->pm);
        $post = fn (string $type, array $with = []) => $this->post($this->store(), $this->letterForm($type, $with));

        $this->post($this->store(), [])->assertSessionHasErrors(['client_uuid', 'type', 'language']);
        $post('reminder', ['hindrance_ids' => [$ended->id]])->assertSessionHasErrors('type');
        $post(self::EOT, ['hindrance_ids' => [$ended->id], 'language' => 'gu'])->assertSessionHasErrors('language');
        $post(self::EOT, ['hindrance_ids' => [$ended->id], 'client_uuid' => 'abc'])->assertSessionHasErrors('client_uuid');
        $post(self::EOT)->assertSessionHasErrors('hindrance_ids');
        $post(self::EOT, ['hindrance_ids' => [$ended->id, $ended->id]])->assertSessionHasErrors('hindrance_ids.0');
        $post(self::EOT, ['hindrance_ids' => [999999]])->assertSessionHasErrors('hindrance_ids.0');
        $post(self::EOT, ['hindrance_ids' => [$ended->id], 'proposed_completion' => '31/10/2026'])->assertSessionHasErrors('proposed_completion');
        $post(self::EOT, ['hindrance_ids' => [$ended->id], 'addressed_to' => str_repeat('x', 201)])->assertSessionHasErrors('addressed_to');
        $post(self::DELAY, ['hindrance_ids' => [$ended->id]])->assertSessionHasErrors('hindrance_ids');
        $post(self::PAYMENT)->assertSessionHasErrors('bill_ids');
        $post(self::PAYMENT, ['bill_ids' => [999999]])->assertSessionHasErrors('bill_ids.0');
        // Entries are not bills, and bills are not entries.
        $post(self::PAYMENT, ['hindrance_ids' => [$ended->id]])->assertSessionHasErrors('bill_ids');
        $post(self::DELAY, ['bill_ids' => [$bill->id]])->assertSessionHasErrors('hindrance_ids');

        $this->assertSame(0, SiteLetter::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'site_letter.%')->count());

        // What does not belong to the kind of letter is left out, not kept.
        $post(self::DELAY, ['hindrance_ids' => [$ongoing->id], 'bill_ids' => [$bill->id], 'proposed_completion' => '2026-10-31'])->assertSessionHasNoErrors();
        $notice = SiteLetter::sole();
        $this->assertSame(['bill_ids' => [], 'proposed_completion' => null], $notice->inputs);
        $this->assertStringNotContainsString('2026-10-31', json_encode($notice->facts));

        // With no confirmed completion date the request for more time is refused and says where to enter it.
        ContractTerm::query()->where('code', 'stipulated_completion')->delete();
        $post(self::EOT, ['hindrance_ids' => [$ended->id]])->assertSessionHasErrors(['type' => 'No stipulated completion date is confirmed under Contract terms for today. Enter and confirm it there first: the app does not know it.']);
        $this->assertSame(1, SiteLetter::count());
    }

    public function test_the_draft_page_marks_every_figure_and_asks_for_a_tick_for_each(): void
    {
        $this->agreement('2026-08-31');
        $this->notice(['code' => 'eot_notice', 'source_ref' => 'Sample agreement, part K']);
        $entry = $this->entry('2026-03-02', '2026-03-06', ['description' => 'Drawings for <b>podium</b> & ramp awaited'], ['2026-03-02', '2026-03-04']);
        $letter = $this->draft(self::EOT, ['hindrance_ids' => [$entry->id], 'addressed_to' => 'The Engineer in charge']);

        $page = $this->actingAs($this->pm)->get($this->show($letter))->assertOk()
            ->assertSee(LetterLayout::CAUTION)
            ->assertSee('Draft prepared from your site records. Check it against your agreement before sending.')
            ->assertSee('Figures in this letter')
            ->assertSee('Finalise the letter')
            ->assertSee('Change the draft')
            ->assertDontSee('window.print()', false)
            ->assertDontSee('Copy text')
            ->assertDontSee('The records behind this draft have changed');

        // Each figure stands in the letter marked with what it is.
        foreach ([
            ['Date of the letter', '20 March 2026'],
            ['Addressed to, as typed by you', 'The Engineer in charge'],
            ['Name of the work', 'Lake View Residency'],
            ['Agreement number, as entered under Contract terms', 'SG/LVR/07'],
            ['Agreement date, as entered under Contract terms', '15 September 2025'],
            ['Stipulated completion date, as entered under Contract terms', '31 August 2026'],
            ['Hindrance 1: description from the register', 'Drawings for <b>podium</b> & ramp awaited'],
            ['Hindrance 1: first day', '2 March 2026'],
            ['Hindrance 1: last day', '6 March 2026'],
            ['Hindrance 1: calendar days, both ends counted', '5'],
            ['Hindrance 1: dates that have a cited daily report', '2'],
            ['Hindrance 1: date of the last cited daily report', '4 March 2026'],
            ['Days of all the hindrances, a day they share counted once', '5'],
            ['Proposed completion date, worked out by the app', '5 September 2026'],
            ['Clause or page 1 in the agreement, as typed under Contract terms', 'Sample agreement, part K'],
            ['Name of your company', 'Shivganga Buildcon'],
        ] as [$label, $text]) {
            $page->assertSee($this->marked($label, $text), false);
        }

        // What a person typed in the register is printed as text, never as markup.
        $page->assertDontSee('<b>podium</b>', false);

        $words = $this->words($page->getContent());
        $this->assertStringContainsString('We request that the time for completion be extended up to 5 September 2026.', $words);
        $this->assertStringContainsString('From 2 March 2026 to 6 March 2026: 5 calendar days, counting the first and the last day.', $words);

        // One tick box for each figure the letter shows, in the order they stand, each with where it was read. The category is words, not a figure.
        preg_match_all('/name="number_ticks\[\]" value="([^"]+)"/', $page->getContent(), $boxes);
        $this->assertSame([
            'letter_date', 'addressed_to', 'project_name', 'agreement_no', 'agreement_date', 'stipulated_completion',
            'h1_what', 'h1_start', 'h1_end', 'h1_days', 'h1_report_days', 'h1_first_report', 'h1_last_report',
            'total_days', 'proposed_completion', 'clause_1', 'company_name',
        ], $boxes[1]);
        $this->assertSame($boxes[1], $this->ticks($letter));
        $this->assertSame(0, preg_match('/name="number_ticks\[\]"[^>]*(checked|disabled)/', $page->getContent()), 'No figure is ticked for the person.');
        $page->assertSee('Read from: Hindrance register')
            ->assertSee('Read from: Contract terms, as entered and confirmed')
            ->assertSee('Read from: Worked out by the app')
            ->assertSee('Read from: Typed by you on this letter');

        // The three sentences a person may rewrite are offered as they stand.
        $page->assertSee('name="slots[opening]"', false)->assertSee('name="slots[linking]"', false)->assertSee('name="slots[closing]"', false)
            ->assertSee('Opening sentences')->assertSee('Sentence before the list')->assertSee('Closing sentences');
    }

    public function test_a_letter_is_not_finalised_while_a_figure_is_not_ticked(): void
    {
        $this->agreement();
        $entry = $this->entry('2026-03-02', '2026-03-06');
        $letter = $this->draft(self::EOT, ['hindrance_ids' => [$entry->id]]);
        $ticks = $this->ticks($letter);
        $this->assertCount(13, $ticks);
        $this->actingAs($this->pm);

        $refused = function (array $sent, string $names) use ($letter, $entry) {
            $this->tick($letter, $sent)
                ->assertSessionHasErrors(['number_ticks' => 'Tick every figure in the draft before you continue. Not ticked yet: '.$names]);

            $this->assertSame(SiteLetter::DRAFT, $letter->refresh()->status);
            $this->assertNull($entry->refresh()->locked_at);
        };

        // Nothing ticked, one figure left out, and ticks for things the letter does not show.
        $this->tick($letter, [])->assertSessionHasErrors('number_ticks');
        $this->post($this->finalise($letter), ['seen' => $letter->seal()])->assertSessionHasErrors('number_ticks');
        $this->assertSame(SiteLetter::DRAFT, $letter->refresh()->status);
        // Without saying which draft was read, or naming another one, nothing is finalised either.
        $this->post($this->finalise($letter), ['number_ticks' => $ticks])->assertSessionHasErrors('seen');
        $this->tick($letter, $ticks, str_repeat('0', 64))->assertSessionHasErrors(['seen' => 'This draft was prepared again after you opened the page. Read it once more and tick its figures again.']);
        $this->assertSame(SiteLetter::DRAFT, $letter->refresh()->status);
        $refused(array_values(array_diff($ticks, ['proposed_completion'])), 'Proposed completion date, worked out by the app');
        $refused(array_values(array_diff($ticks, ['h1_days', 'total_days'])), 'Hindrance 1: calendar days, both ends counted; Days of all the hindrances, a day they share counted once');
        $refused([...array_values(array_diff($ticks, ['h1_end'])), 'h1_category', 'h2_end', 'all'], 'Hindrance 1: last day');
        $this->post($this->finalise($letter), ['number_ticks' => 'all', 'seen' => $letter->seal()])->assertSessionHasErrors('number_ticks');
        $this->assertSame(0, AuditLog::where('action', 'site_letter.finalised')->count());

        // The boxes that were ticked stay ticked when the page comes back.
        $this->tick($letter, ['letter_date', 'h1_days']);
        $back = $this->get($this->show($letter))->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('/name="number_ticks\[\]" value="(letter_date|h1_days)"[^>]*checked/', $back));
        $this->assertSame(2, preg_match_all('/name="number_ticks\[\]"[^>]*checked/', $back));

        // Every figure ticked: finalised by this person, and the entry is locked.
        $this->tick($letter, $ticks)->assertSessionHasNoErrors()->assertRedirect($this->show($letter))
            ->assertSessionHas('success', 'Letter finalised. It can no longer be changed. Print it or copy it: the app sends nothing.');

        $letter->refresh();
        $this->assertSame([SiteLetter::FINALISED, $this->pm->id], [$letter->status, $letter->finalised_by]);
        $this->assertSame('2026-03-20', $letter->finalised_at->toDateString());
        $this->assertSame($ticks, $letter->number_ticks);
        $this->assertSame([], $letter->typed_numbers);
        $this->assertNotNull($entry->refresh()->locked_at);

        $audit = AuditLog::where('action', 'site_letter.finalised')->sole();
        $this->assertSame($this->pm->id, $audit->user_id);
        $this->assertSame([SiteLetter::FINALISED, 13, [$entry->id], [$entry->id]], [$audit->new_values['status'], $audit->new_values['figures_ticked'], $audit->new_values['hindrance_ids'], $audit->new_values['entries_locked']]);
        $this->assertSame(0, AuditLog::where('action', 'wording.typed_numbers_ticked')->count(), 'Nobody typed a number, so no such row is written.');

        // Pressing again finalises nothing a second time.
        $this->tick($letter, $ticks)->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::where('action', 'site_letter.finalised')->count());
    }

    public function test_a_change_in_the_records_stops_the_draft_until_it_is_prepared_again(): void
    {
        $this->agreement('2026-08-31');
        $entry = $this->cite($this->hindrance(['started_on' => '2026-03-02', 'ended_on' => '2026-03-06']), $report = $this->dpr('2026-03-02'));
        $letter = $this->draft(self::EOT, ['hindrance_ids' => [$entry->id], 'addressed_to' => 'The Engineer in charge']);
        $ticks = $this->ticks($letter);
        $before = $letter->fact_fingerprint;
        $stale = $letter->seal();
        $this->actingAs($this->pm);

        // The entry is changed in the register: it now ends two days later.
        $this->patch(route('site-diary.hindrances.update', $entry), ['category' => 'drawings_or_decisions', 'description' => 'Podium slab drawings awaited', 'ended_on' => '2026-03-08', 'dpr_ids' => [$report->id]])
            ->assertSessionHasNoErrors();

        $this->tick($letter, $ticks)
            ->assertSessionHasErrors(['facts' => 'The records behind this draft have changed since it was prepared. Prepare the draft again and check it.']);
        $this->assertSame(SiteLetter::DRAFT, $letter->refresh()->status);
        $this->assertNull($entry->refresh()->locked_at);

        // The page says so, shows the draft as it was prepared, and takes no tick until it is prepared again.
        $page = $this->get($this->show($letter))->assertOk()
            ->assertSee('The records behind this draft have changed since it was prepared. Prepare the draft again and check it.')
            ->assertSee('Prepare again from the records')
            ->assertSee($this->marked('Hindrance 1: last day', '6 March 2026'), false);
        $this->assertSame(count($ticks), preg_match_all('/name="number_ticks\[\]"[^>]*disabled/', $page->getContent()));
        $this->assertSame(1, preg_match('/<button class="btn btn-primary min-h-11" disabled>\s*Finalise the letter/', $page->getContent()));

        // "Prepare again" sends the same choices and reads the records as they are now. 2 to 8 March is 7 days.
        $this->patch($this->update($letter), ['addressed_to' => 'The Engineer in charge', 'hindrance_ids' => [$entry->id]])
            ->assertSessionHasNoErrors()->assertRedirect($this->show($letter));
        $letter->refresh();
        $this->assertNotSame($before, $letter->fact_fingerprint);
        $this->assertStringContainsString('From 2 March 2026 to 8 March 2026: 7 calendar days', $letter->body_rendered);
        $this->assertStringContainsString('extended up to 7 September 2026.', $letter->body_rendered);
        $this->assertSame([$before], [AuditLog::where('action', 'site_letter.prepared_again')->sole()->old_values['fact_fingerprint']]);
        $this->get($this->show($letter))->assertOk()->assertDontSee('The records behind this draft have changed');

        // The next day the letter would carry another date, so it is prepared again then too.
        $this->travel(1)->days();
        $this->tick($letter, $ticks)->assertSessionHasErrors('facts');
        $this->get($this->show($letter))->assertOk()->assertSee('The records behind this draft have changed since it was prepared.');
        $this->patch($this->update($letter), ['addressed_to' => 'The Engineer in charge', 'hindrance_ids' => [$entry->id]])->assertSessionHasNoErrors();
        $this->assertStringContainsString('Date: 21 March 2026', $letter->refresh()->body_rendered);

        // A confirmed term changes: the stipulated date is another one now.
        ContractTerm::query()->where('code', 'stipulated_completion')->update(['value_date' => '2026-09-30']);
        $this->tick($letter, $ticks)->assertSessionHasErrors('facts');

        // The entry is dismissed: the draft cannot be prepared again as it is, and the page says why.
        $this->post(route('site-diary.hindrances.dismiss', $entry))->assertSessionHasNoErrors();
        $this->tick($letter, $ticks)->assertSessionHasErrors('hindrance_ids');
        $this->patch($this->update($letter), ['hindrance_ids' => [$entry->id]])->assertSessionHasErrors('hindrance_ids');
        $this->get($this->show($letter))->assertOk()
            ->assertSee('One of the chosen entries is not a confirmed entry that counts any more: it was dismissed, removed or corrected since. Choose the entries once more.')
            ->assertDontSee('Prepare again from the records');

        // Another entry is chosen instead, and the letter goes through with fresh ticks.
        $other = $this->entry('2026-03-10', '2026-03-11');
        $this->patch($this->update($letter), ['addressed_to' => 'The Engineer in charge', 'hindrance_ids' => [$other->id]])->assertSessionHasNoErrors();
        $this->assertSame([$other->id], SiteLetterHindrance::where('site_letter_id', $letter->id)->pluck('hindrance_id')->all());
        // The figures go by the same names as before, so ticks sent from the page of the earlier draft would name them all. They do not count.
        $this->assertSame($ticks, $this->ticks($letter));
        $this->tick($letter, $ticks, $stale)->assertSessionHasErrors('seen');
        $this->assertSame(SiteLetter::DRAFT, $letter->refresh()->status);
        $this->tick($letter)->assertSessionHasNoErrors();

        $this->assertSame(SiteLetter::FINALISED, $letter->refresh()->status);
        $this->assertNotNull($other->refresh()->locked_at);
        $this->assertNull($entry->refresh()->locked_at, 'The entry the letter no longer cites is not locked.');
    }

    public function test_a_rewritten_sentence_is_the_persons_own_and_a_number_typed_in_it_needs_its_own_tick(): void
    {
        $this->agreement();
        $entry = $this->entry('2026-03-02', '2026-03-06');
        $letter = $this->draft(self::EOT, ['hindrance_ids' => [$entry->id]]);
        $code = $letter->slots;
        $this->actingAs($this->pm);
        $save = fn (array $slots) => $this->patch($this->update($letter), ['hindrance_ids' => [$entry->id], 'slots' => $slots]);

        // Sending the sentences back as they are, with the line ends a browser adds, keeps them the app's own.
        $save(['opening' => $code['opening']['text']."\r\n", 'linking' => '  '.$code['linking']['text'], 'closing' => $code['closing']['text']])->assertSessionHasNoErrors();
        $this->assertSame(['template', 'template', 'template'], array_column($letter->refresh()->slots, 'source'));

        // The close is rewritten, with two numbers and a date typed by hand.
        $typed = "We wrote to you 3 times before, last on 12/03/2026.\r\nKindly reply within 45 days {{total_days}}.";
        $save(['opening' => $code['opening']['text'], 'linking' => '', 'closing' => $typed, 'signature' => 'For somebody else'])->assertSessionHasNoErrors();

        $letter->refresh();
        $this->assertSame(['template', 'template', 'edited'], array_column($letter->slots, 'source'));
        $this->assertSame("We wrote to you 3 times before, last on 12/03/2026.\nKindly reply within 45 days {{total_days}}.", $letter->slots['closing']['text']);
        $this->assertSame(['typed:closing:1:3', 'typed:closing:2:12/03/2026', 'typed:closing:3:45'], array_column($letter->typed_numbers, 'id'));
        $this->assertStringContainsString('Kindly reply within 45 days {{total_days}}.', $letter->body_rendered, 'What a person types is never read as a token.');
        $this->assertStringEndsWith('For Shivganga Buildcon', $letter->body_rendered);

        // The page lists each typed number as typed by the person, and marks it in the letter.
        $page = $this->get($this->show($letter))->assertOk()
            ->assertSee('typed by you, not from the records')
            ->assertSee('Where it stands: We wrote to you 3 times before, last on')
            ->assertSee('<mark class="rounded-[2px] bg-laterite/10 px-1 text-ink" title="typed by you, not from the records">45</mark>', false)
            ->assertSee('name="number_ticks[]" value="typed:closing:3:45"', false);
        $this->assertStringContainsString('Kindly reply within 45 days {{total_days}}.', $this->words($page->getContent()));

        // Ticking the figures from the records is not enough.
        $facts = array_values(array_filter($this->ticks($letter), fn (string $tick) => ! str_starts_with($tick, 'typed:')));
        $this->tick($letter, [...$facts, 'typed:closing:1:3', 'typed:closing:2:12/03/2026'])
            ->assertSessionHasErrors(['number_ticks' => 'Tick every figure in the draft before you continue. Not ticked yet: 45 (typed by you, not from the records)']);
        // A tick given for one number does not cover another typed in its place.
        $save(['closing' => str_replace('45 days', '54 days', $typed)])->assertSessionHasNoErrors();
        $this->tick($letter, [...$facts, 'typed:closing:1:3', 'typed:closing:2:12/03/2026', 'typed:closing:3:45'])->assertSessionHasErrors('number_ticks');
        $this->assertSame(SiteLetter::DRAFT, $letter->refresh()->status);

        $this->tick($letter)->assertSessionHasNoErrors();
        $letter->refresh();
        $this->assertSame(SiteLetter::FINALISED, $letter->status);
        $this->assertSame(['3', '12/03/2026', '54'], array_column($letter->typed_numbers, 'text'));
        $this->assertContains('typed:closing:3:54', $letter->number_ticks);

        // The ticks for the typed numbers are in the audit log, under the letter and the person.
        $audit = AuditLog::where('action', 'wording.typed_numbers_ticked')->sole();
        $this->assertSame([$this->pm->id, $letter->id, $letter->getMorphClass()], [$audit->user_id, $audit->auditable_id, $audit->auditable_type]);
        $this->assertSame(['3', '12/03/2026', '54'], array_column($audit->new_values['numbers'], 'text'));
        $this->assertSame(3, AuditLog::where('action', 'site_letter.finalised')->sole()->new_values['numbers_typed']);

        // The finalised page shows what was ticked, the typed numbers among it.
        $this->get($this->show($letter))->assertOk()->assertSee('Figures ticked when it was finalised')->assertSee('typed by you, not from the records')->assertSee('12/03/2026');
    }

    public function test_a_sentence_from_an_ai_model_is_kept_as_such_and_left_out_when_it_states_a_figure(): void
    {
        $this->agreement();
        $entry = $this->entry('2026-03-02', '2026-03-06');
        $letter = $this->draft(self::EOT, ['hindrance_ids' => [$entry->id]]);
        $letters = app(SiteLetters::class);
        $code = $letter->slots['closing']['text'];
        $this->actingAs($this->pm);

        // No model is called here. A later piece of work hands its sentences in like this, after the Wording intake.
        $letters->useSentences($letter, ['opening' => SlotText::ai('Dear Sir, we seek your kind attention to the progress of this work.'), 'signature' => SlotText::ai('For another firm')], $this->pm);
        $letter->refresh();
        $this->assertSame(['ai', 'template', 'template'], array_column($letter->slots, 'source'));
        $this->assertStringContainsString("\n\nDear Sir, we seek your kind attention to the progress of this work.\n\n", $letter->body_rendered);
        $this->assertStringEndsWith('For Shivganga Buildcon', $letter->body_rendered);
        $this->get($this->show($letter))->assertOk()->assertDontSee('A sentence written by the AI stated a figure');

        // Saving the form with that sentence untouched keeps it the model's; changing a word makes it the person's.
        $this->patch($this->update($letter), ['hindrance_ids' => [$entry->id], 'slots' => ['opening' => $letter->slots['opening']['text'], 'closing' => $code]])->assertSessionHasNoErrors();
        $this->assertSame(['ai', 'template', 'template'], array_column($letter->refresh()->slots, 'source'));

        // A sentence that states a figure never reaches the letter, however it got as far as this.
        foreach (['Kindly grant us 30 days more.', 'Kindly grant us thirty days more.', 'Kindly reply by Monday.', 'We claim {{total_days}} days.', 'The cost to us is ₹5 lakh.'] as $figure) {
            $letters->useSentences($letter, ['closing' => SlotText::ai($figure)], $this->pm);
            $letter->refresh();
            $this->assertSame(['text' => $code, 'source' => 'template'], $letter->slots['closing'], $figure);
            $this->assertStringNotContainsString($figure, $letter->body_rendered);
            $this->assertSame([], $letter->typed_numbers);
        }

        // Stored text whose writer is not known is trusted least: with a figure in it, it is not used.
        $letter->update(['slots' => [...$letter->slots, 'closing' => ['text' => 'Kindly grant us 30 days more.']]]);
        $this->get($this->show($letter))->assertOk()
            ->assertSee('A sentence written by the AI stated a figure, so the app left it out and put its own sentence there.')
            ->assertSee($code)
            ->assertDontSee('Kindly grant us 30 days more.')
            // The letter itself still reads as it was prepared, so nothing has changed for the reader.
            ->assertDontSee('The records behind this draft have changed');
        $this->tick($letter)->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('30 days', $letter->refresh()->body_rendered);
        $this->assertSame(['text' => $code, 'source' => 'template'], $letter->slots['closing'], 'What was left out does not stay on the finalised row.');

        // A finalised letter takes no sentence from anyone.
        try {
            $letters->useSentences($letter, ['opening' => SlotText::ai('Dear Sir, we write again.')], $this->pm);
            $this->fail('A finalised letter was changed.');
        } catch (ImmutableRecordException $refused) {
            $this->assertSame('This letter is finalised and cannot be changed. Prepare a new letter instead.', $refused->getMessage());
        }
    }

    public function test_a_draft_whose_words_no_longer_match_what_was_prepared_is_prepared_again_first(): void
    {
        $ongoing = $this->entry('2026-03-12', null);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$ongoing->id]]);
        $prepared = $letter->body_rendered;
        $this->actingAs($this->pm);

        // The sentences on the row are changed without the letter being put together again, as a later
        // version of the app's own sentences would do to a draft that was prepared before it.
        $letter->update(['slots' => [...$letter->slots, 'closing' => ['text' => 'We await your early reply.', 'source' => 'edited']]]);

        // Every figure is as it was, and so is the draft the person last read. The words are not.
        $this->tick($letter)->assertSessionHasErrors(['facts' => 'The wording of this draft has changed since it was prepared. Prepare the draft again and check it.']);
        $this->assertSame([SiteLetter::DRAFT, $prepared], [$letter->refresh()->status, $letter->body_rendered]);
        $this->assertNull($ongoing->refresh()->locked_at);

        $page = $this->get($this->show($letter))->assertOk()
            ->assertSee('The records behind this draft have changed since it was prepared. Prepare the draft again and check it.')
            ->assertSee('Prepare again from the records')
            ->assertSee('We await your early reply.');
        $this->assertSame(1, preg_match('/<button class="btn btn-primary min-h-11" disabled>\s*Finalise the letter/', $page->getContent()));

        $this->patch($this->update($letter), ['hindrance_ids' => [$ongoing->id]])->assertSessionHasNoErrors();
        $this->assertStringContainsString('We await your early reply.', $letter->refresh()->body_rendered);
        $this->tick($letter)->assertSessionHasNoErrors();
        $this->assertSame(SiteLetter::FINALISED, $letter->refresh()->status);
    }

    public function test_emptying_a_rewritten_sentence_brings_the_sentence_of_the_app_back(): void
    {
        $ongoing = $this->entry('2026-03-12', null);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$ongoing->id], 'language' => 'hi']);
        $code = $letter->slots['opening']['text'];
        $this->actingAs($this->pm);

        $this->patch($this->update($letter), ['hindrance_ids' => [$ongoing->id], 'slots' => ['opening' => 'महोदय, काम रुका हुआ है।']])->assertSessionHasNoErrors();
        $this->assertSame(['text' => 'महोदय, काम रुका हुआ है।', 'source' => 'edited'], $letter->refresh()->slots['opening']);
        $this->assertStringContainsString('महोदय, काम रुका हुआ है।', $letter->body_rendered);
        $this->assertSame([], $letter->typed_numbers);

        // Saving without the sentences leaves them as they are, and the person's sentence stays the person's.
        $this->patch($this->update($letter), ['hindrance_ids' => [$ongoing->id], 'addressed_to' => 'अधीक्षण अभियंता'])->assertSessionHasNoErrors();
        $this->assertSame('edited', $letter->refresh()->slots['opening']['source']);
        $this->assertStringContainsString("प्रति: अधीक्षण अभियंता\n\n", $letter->body_rendered);
        $this->patch($this->update($letter), ['hindrance_ids' => [$ongoing->id], 'slots' => ['opening' => 'महोदय, काम रुका हुआ है।']])->assertSessionHasNoErrors();
        $this->assertSame('edited', $letter->refresh()->slots['opening']['source']);

        $this->patch($this->update($letter), ['hindrance_ids' => [$ongoing->id], 'slots' => ['opening' => '   ']])->assertSessionHasNoErrors();
        $this->assertSame(['text' => $code, 'source' => 'template'], $letter->refresh()->slots['opening']);
        $this->assertNull($letter->addressed_to, 'The form sends the whole letter: a field left out is saved empty.');

        // A sentence that is too long is refused, and so is anything that is not text.
        $this->patch($this->update($letter), ['hindrance_ids' => [$ongoing->id], 'slots' => ['closing' => str_repeat('क', 2001)]])->assertSessionHasErrors('slots.closing');
        $this->patch($this->update($letter), ['hindrance_ids' => [$ongoing->id], 'slots' => 'all'])->assertSessionHasErrors('slots');
        $this->patch($this->update($letter), ['hindrance_ids' => [$ongoing->id], 'slots' => ['closing' => ['a']]])->assertSessionHasErrors('slots.closing');
    }

    public function test_a_finalised_letter_cannot_be_changed_and_the_entries_it_cites_are_locked(): void
    {
        $this->notice();
        $report = $this->dpr('2026-03-14');
        $entry = $this->cite($this->hindrance(['started_on' => '2026-03-14', 'ended_on' => null]), $report);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]]);
        $this->actingAs($this->pm);

        // Until a letter cites it, the register reminds people of the notice date worked out from the confirmed period.
        $reminders = fn () => iterator_to_array(app(NoticeDeadlineSource::class)->findings($this->project, today()), false);
        $this->assertCount(1, $reminders());

        $this->tick($letter)->assertSessionHasNoErrors();
        $body = $letter->refresh()->body_rendered;
        $this->assertSame([], $reminders(), 'A finalised letter cites the entry, so the reminder stops.');

        // The page of a finalised letter: the text as finalised, print and copy, and no way to change it.
        $this->get($this->show($letter))->assertOk()
            ->assertSee('Finalised by '.$this->pm->name.' on 20 Mar 2026')
            ->assertSee('window.print()', false)->assertSee('Print')->assertSee('Copy text')
            ->assertSee('Reference in the agreement: Sample agreement, part H')
            ->assertSee('Figures ticked when it was finalised')
            ->assertSee(LetterLayout::CAUTION)
            ->assertDontSee('Finalise the letter')->assertDontSee('Change the draft')->assertDontSee('name="number_ticks[]"', false)
            ->assertDontSee('<mark', false);

        // Every change answers 409 and changes nothing.
        $this->patch($this->update($letter), ['hindrance_ids' => [$entry->id], 'addressed_to' => 'Somebody else'])->assertStatus(409);
        $this->patchJson($this->update($letter), ['hindrance_ids' => [$entry->id]])->assertStatus(409)->assertJsonFragment(['message' => 'This letter is finalised and cannot be changed. Prepare a new letter instead.']);
        $this->assertSame([$body, null], [$letter->refresh()->body_rendered, $letter->addressed_to]);

        // Whoever writes the row, it stays as it was finalised.
        foreach ([fn () => $letter->update(['subject' => 'Changed']), fn () => $letter->update(['status' => SiteLetter::DRAFT]), fn () => $letter->delete()] as $write) {
            try {
                $write();
                $this->fail('A finalised letter was changed.');
            } catch (ImmutableRecordException) {
                $letter->refresh();
            }
        }

        $this->assertSame(SiteLetter::FINALISED, $letter->status);

        // The entry it cites is locked: the register says so, refuses a change and offers a correction.
        $this->assertNotNull($entry->refresh()->locked_at);
        $this->get(route('site-diary.hindrances.index', $this->project))->assertOk()->assertSee('Cited in a letter')->assertSee('Correct this entry');
        $this->patch(route('site-diary.hindrances.update', $entry), ['category' => 'weather', 'description' => 'Changed', 'dpr_ids' => [$report->id]])->assertStatus(409);
        $this->post(route('site-diary.hindrances.dismiss', $entry))->assertStatus(409);

        // A second letter may cite the locked entry, and finalising it leaves the first lock as it is.
        $lockedAt = $entry->locked_at->toDateTimeString();
        $this->travel(2)->hours();
        $again = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id], 'language' => 'mr']);
        $this->tick($again)->assertSessionHasNoErrors();
        $this->assertSame(SiteLetter::FINALISED, $again->refresh()->status);
        $this->assertSame($lockedAt, $entry->refresh()->locked_at->toDateTimeString());
        $this->assertSame([], AuditLog::where('action', 'site_letter.finalised')->latest('id')->first()->new_values['entries_locked']);

        // Once the entry is corrected in the register, a new letter cites the correction, not the locked entry.
        $this->post(route('site-diary.hindrances.store', $this->project), $this->form([$report], ['supersedes_id' => $entry->id, 'started_on' => '2026-03-13', 'still_going_on' => '1']))->assertSessionHasNoErrors();
        $correction = Hindrance::where('supersedes_id', $entry->id)->sole();
        $this->post($this->store(), $this->letterForm(self::DELAY, ['hindrance_ids' => [$entry->id]]))->assertSessionHasErrors('hindrance_ids');
        $this->post($this->store(), $this->letterForm(self::DELAY, ['hindrance_ids' => [$correction->id]]))->assertSessionHasNoErrors();

        // The list shows all three, newest first, with who finalised what.
        $this->get($this->index())->assertOk()
            ->assertSeeInOrder(['Draft', 'Finalised', 'Finalised'])
            ->assertSee('Finalised by '.$this->pm->name.' on 20 Mar 2026')
            ->assertSee('Subject: Notice of delay to the work Lake View Residency');
    }

    public function test_only_people_who_hold_the_letters_permission_on_a_plan_with_site_control_use_letters(): void
    {
        $this->agreement();
        $entry = $this->entry('2026-03-02', '2026-03-06');
        $letter = $this->draft(self::EOT, ['hindrance_ids' => [$entry->id]]);
        $ticks = $this->ticks($letter);
        $form = fn () => $this->letterForm(self::EOT, ['hindrance_ids' => [$entry->id]]);

        // The site engineer keeps the register, the estimator reads it; neither writes letters. Nor does anyone without the register.
        foreach ([$this->engineer, $this->qs, $this->member($this->org, 'accounts'), $this->member($this->org, 'store'), $this->member($this->org, 'viewer'), $this->holding(['project.view', 'site_diary.view', 'site_diary.manage', 'dpr.view', 'money.view'])] as $user) {
            $this->actingAs($user);
            $this->get($this->index())->assertForbidden();
            $this->post($this->store(), $form())->assertForbidden();
            $this->get($this->show($letter))->assertForbidden();
            $this->patch($this->update($letter), ['hindrance_ids' => [$entry->id]])->assertForbidden();
            $this->tick($letter, $ticks)->assertForbidden();
            $this->get(route('projects.show', $this->project))->assertOk()->assertDontSee($this->index(), false);
        }

        $this->assertSame([1, SiteLetter::DRAFT], [SiteLetter::count(), $letter->refresh()->status]);

        // The project manager, the owner, an admin, and a role that was given just this permission.
        foreach ([$this->pm, $this->owner, $this->member($this->org, 'admin'), $this->holding(['project.view', 'site_diary.letters'])] as $user) {
            $this->actingAs($user);
            $this->get($this->index())->assertOk();
            $this->get($this->show($letter))->assertOk();
            $this->post($this->store(), $form())->assertSessionHasNoErrors();
            $this->get(route('projects.show', $this->project))->assertOk()->assertSee($this->index(), false);
        }

        $this->assertSame(5, SiteLetter::count());

        // A guest is sent to sign in.
        auth()->logout();
        $this->get($this->index())->assertRedirect(route('login'));
        $this->tick($letter, $ticks)->assertRedirect(route('login'));

        // A plan without site control has no letters at all.
        $free = $this->tenant('Small Site Co', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free, ['code' => 'FR1']);
        $theirs = $this->entry('2026-03-02', null, [], null, $project);

        $this->actingAs($owner)->get($this->index($project))->assertForbidden();
        $this->post($this->store($project), $this->letterForm(self::DELAY, ['hindrance_ids' => [$theirs->id]]))->assertForbidden();
        $this->get(route('projects.show', $project))->assertOk()->assertDontSee('site-letters', false);
        $this->assertSame(5, SiteLetter::withoutGlobalScopes()->count());
    }

    public function test_a_payment_follow_up_needs_money_view_and_shows_no_rupee_to_anyone_else(): void
    {
        $entry = $this->entry('2026-03-12', null);
        $bill = $this->clientBill('SG1/RA/1', '250000.00', [], '5000.00');
        $this->receipt($bill, '100000.00', '2000.00');

        // The project manager sees money and prepares the letter.
        $this->actingAs($this->pm)->post($this->store(), $this->letterForm(self::PAYMENT, ['bill_ids' => [$bill->id], 'addressed_to' => 'The Accounts Officer']))->assertSessionHasNoErrors();
        $letter = SiteLetter::sole();
        $this->assertSame(['bill_ids' => [$bill->id], 'proposed_completion' => null], $letter->inputs);
        $this->assertSame(0, SiteLetterHindrance::count());

        $page = $this->get($this->show($letter))->assertOk()
            ->assertSee($this->marked('Bill 1: outstanding', '₹1,53,000.00'), false)
            ->assertSee($this->marked('Bill 1: TDS shown on the receipts', '₹2,000.00'), false)
            ->assertSee($this->marked('Total outstanding on these bills', '₹1,53,000.00'), false)
            ->assertSee('Read from: Client bills and the receipts recorded on them')
            ->assertSee('Client bills this letter follows up')->assertSee('Outstanding: ₹1,53,000.00');
        $this->assertStringContainsString('The amount outstanding on bill SG1/RA/1 is ₹1,53,000.00.', $this->words($page->getContent()));
        $this->get($this->index())->assertOk()->assertSee($this->show($letter), false)->assertSee('Subject: Payment outstanding on our bills for the work Lake View Residency');

        // Someone who may write letters but may not see money.
        $noMoney = $this->holding(['project.view', 'site_diary.view', 'site_diary.letters', 'ra_bill.view']);
        $this->actingAs($noMoney);

        $list = $this->get($this->index())->assertOk()
            ->assertSee('Payment follow-up')
            ->assertSee('This document carries money figures, which your role does not see.')
            ->assertDontSee($this->show($letter), false)
            ->assertDontSee('Payment outstanding on our bills')
            ->assertDontSee('SG1/RA/1')
            ->assertDontSee('name="bill_ids[]"', false)
            ->assertDontSee('<option value="payment_followup">', false)
            ->assertSee('<option value="delay_notice">', false);

        $this->get($this->show($letter))->assertForbidden()->assertDontSee('₹');
        $this->patch($this->update($letter), ['bill_ids' => [$bill->id]])->assertForbidden();
        $this->tick($letter)->assertForbidden();
        $this->post($this->store(), $this->letterForm(self::PAYMENT, ['bill_ids' => [$bill->id]]))->assertForbidden();
        // Refused before anything about the bill is checked or said.
        $this->post($this->store(), $this->letterForm(self::PAYMENT, ['bill_ids' => [999999]]))->assertForbidden();
        $this->postJson($this->store(), $this->letterForm(self::PAYMENT, ['bill_ids' => [$bill->id]]))->assertForbidden()->assertDontSee('153000')->assertDontSee('1,53,000');

        foreach ([$list->getContent(), $this->get($this->index())->getContent()] as $html) {
            $this->assertStringNotContainsString('₹', $html);
            $this->assertStringNotContainsString('53,000', $html);
            $this->assertStringNotContainsString('250000', preg_replace('/\D+/', '', $html));
        }

        $this->assertSame([1, SiteLetter::DRAFT], [SiteLetter::count(), $letter->refresh()->status]);

        // The other two letters are theirs to write, and carry no rupee.
        $this->post($this->store(), $this->letterForm(self::DELAY, ['hindrance_ids' => [$entry->id]]))->assertSessionHasNoErrors();
        $notice = SiteLetter::where('type', self::DELAY)->sole();
        $this->get($this->show($notice))->assertOk()->assertDontSee('₹')->assertDontSee('SG1/RA/1');
        $this->tick($notice)->assertSessionHasNoErrors();

        // Nothing kept about a letter outside its own row carries an amount, and the row does not hand its figures to a plain toArray().
        $logged = AuditLog::where('action', 'like', 'site_letter.%')->get()->map(fn (AuditLog $row) => json_encode([$row->old_values, $row->new_values]))->implode(' ');
        $this->assertStringNotContainsString('153000', $logged);
        $this->assertStringNotContainsString('SG1/RA/1', $logged);
        $this->assertSame([], array_intersect(array_keys($letter->toArray()), ['facts', 'slots', 'body_rendered', 'inputs', 'typed_numbers', 'number_ticks']));

        // With money, the letter goes through, and a bill is not locked by it.
        $this->actingAs($this->owner);
        $this->tick($letter)->assertSessionHasNoErrors();
        $this->assertSame(SiteLetter::FINALISED, $letter->refresh()->status);
        $this->get($this->show($letter))->assertOk()->assertSee('The total outstanding on these bills is ₹1,53,000.00.');
        $this->assertSame('APPROVED', $bill->refresh()->status);
    }

    public function test_a_receipt_recorded_after_the_draft_stops_a_payment_follow_up(): void
    {
        $bill = $this->clientBill('SG1/RA/1', '250000.00');
        $other = $this->clientBill('SG1/RA/2', '9000.00');
        $letter = $this->draft(self::PAYMENT, ['bill_ids' => [$bill->id, $other->id]], $this->owner);
        $ticks = $this->ticks($letter);
        $this->actingAs($this->owner);

        // Part of the first bill comes in.
        $this->receipt($bill, '50000.00');
        $this->tick($letter, $ticks)->assertSessionHasErrors('facts');
        $this->get($this->show($letter))->assertOk()->assertSee('The records behind this draft have changed since it was prepared.')->assertSee('Prepare again from the records');
        $this->patch($this->update($letter), ['bill_ids' => [$bill->id, $other->id]])->assertSessionHasNoErrors();
        $this->assertStringContainsString('The total outstanding on these bills is ₹2,09,000.00.', $letter->refresh()->body_rendered);

        // The second bill is paid in full: it is no longer a bill to follow up, and the page says so.
        $this->receipt($other, '9000.00');
        $this->tick($letter)->assertSessionHasErrors('bill_ids');
        $this->get($this->show($letter))->assertOk()
            ->assertSee('One of the chosen bills is not an approved client bill of this project with an amount outstanding any more. Choose the bills once more.')
            ->assertDontSee('Prepare again from the records');
        $this->assertSame(SiteLetter::DRAFT, $letter->refresh()->status);

        $this->patch($this->update($letter), ['bill_ids' => [$bill->id]])->assertSessionHasNoErrors();
        $this->tick($letter)->assertSessionHasNoErrors();
        $this->assertSame(SiteLetter::FINALISED, $letter->refresh()->status);
        $this->assertStringContainsString('The total outstanding on these bills is ₹2,00,000.00.', $letter->body_rendered);
        $this->assertStringNotContainsString('SG1/RA/2', $letter->body_rendered);
    }

    public function test_another_company_reaches_no_letter_and_cites_nothing_of_ours(): void
    {
        $this->agreement();
        $entry = $this->entry('2026-03-02', null, ['description' => 'East plot not handed over']);
        $bill = $this->clientBill('SG1/RA/1', '250000.00');
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]]);
        $ticks = $this->ticks($letter);
        [$rivalOrg, $rivalOwner, $rivalProject] = $this->rival();

        $this->actingAs($rivalOwner);
        $this->get($this->index())->assertNotFound();
        $this->post($this->store(), $this->letterForm(self::DELAY, ['hindrance_ids' => [$entry->id]]))->assertNotFound();
        $this->get($this->show($letter))->assertNotFound();
        $this->patch($this->update($letter), ['hindrance_ids' => [$entry->id]])->assertNotFound();
        $this->tick($letter, $ticks)->assertNotFound();

        // Their own page shows nothing of ours, and neither our entry nor our bill can be cited from their project.
        $this->get($this->index($rivalProject))->assertOk()->assertSee('No letters yet')->assertDontSee('East plot not handed over')->assertDontSee('SG1/RA/1');
        $this->post($this->store($rivalProject), $this->letterForm(self::DELAY, ['hindrance_ids' => [$entry->id]]))->assertSessionHasErrors('hindrance_ids.0');
        $this->post($this->store($rivalProject), $this->letterForm(self::PAYMENT, ['bill_ids' => [$bill->id]]))->assertSessionHasErrors('bill_ids.0');
        // A request id we used is free for them to use.
        $theirs = $this->entry('2026-03-03', null, [], null, $rivalProject);
        $this->post($this->store($rivalProject), ['client_uuid' => $letter->client_uuid, 'type' => self::DELAY, 'language' => 'en', 'hindrance_ids' => [$theirs->id]])->assertSessionHasNoErrors();

        $letter->refresh();
        $this->assertSame([SiteLetter::DRAFT, $this->org->id], [$letter->status, $letter->organization_id]);
        $this->assertNull($entry->refresh()->locked_at);
        $this->assertSame([$this->org->id, $rivalOrg->id], SiteLetter::withoutGlobalScopes()->orderBy('id')->pluck('organization_id')->all());
        $this->assertStringContainsString('For Rival Builders', SiteLetter::withoutGlobalScopes()->latest('id')->first()->body_rendered);
    }

    public function test_someone_limited_to_other_projects_reaches_no_letter_here(): void
    {
        $elsewhere = $this->makeProject($this->org, ['code' => 'SG2', 'name' => 'Hill Road Offices']);
        $entry = $this->entry('2026-03-02', null, ['description' => 'East plot not handed over']);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]]);
        $limited = $this->limitTo($this->member($this->org, 'pm'), $elsewhere);

        $this->actingAs($limited);
        $this->get($this->index())->assertNotFound();
        $this->post($this->store(), $this->letterForm(self::DELAY, ['hindrance_ids' => [$entry->id]]))->assertNotFound();
        $this->get($this->show($letter))->assertNotFound();
        $this->patch($this->update($letter), ['hindrance_ids' => [$entry->id]])->assertNotFound();
        $this->tick($letter)->assertNotFound();

        // In their own project they work as usual, without the entries, letters or request ids of the hidden one.
        $this->get($this->index($elsewhere))->assertOk()->assertSee('No letters yet')->assertDontSee('East plot not handed over');
        $this->post($this->store($elsewhere), $this->letterForm(self::DELAY, ['hindrance_ids' => [$entry->id]]))->assertSessionHasErrors('hindrance_ids.0');
        $this->post($this->store($elsewhere), ['client_uuid' => $letter->client_uuid, 'type' => self::DELAY, 'language' => 'en', 'hindrance_ids' => [$this->entry('2026-03-03', null, [], null, $elsewhere)->id]])
            ->assertSessionHasErrors('client_uuid');

        $this->assertSame([1, SiteLetter::DRAFT], [SiteLetter::withoutGlobalScopes()->count(), $letter->refresh()->status]);

        // Limited to this project, the same person reaches it.
        $this->actingAs($this->limitTo($this->member($this->org, 'pm'), $this->project))->get($this->show($letter))->assertOk();
    }

    public function test_a_letter_of_a_removed_project_cannot_be_opened_or_finalised(): void
    {
        $entry = $this->entry('2026-03-02', null);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]]);
        $ticks = $this->ticks($letter);
        $this->project->delete();

        $this->actingAs($this->owner);
        $this->get($this->show($letter))->assertNotFound();
        $this->patch($this->update($letter), ['hindrance_ids' => [$entry->id]])->assertNotFound();
        $this->tick($letter, $ticks)->assertNotFound();
        $this->assertSame(SiteLetter::DRAFT, $letter->refresh()->status);
    }

    public function test_a_letter_in_hindi_or_marathi_is_an_html_page_in_devanagari_and_the_screen_follows_the_reader(): void
    {
        $this->agreement('2026-08-31');
        $entry = $this->entry('2026-03-02', '2026-03-06', ['description' => 'पोडियम स्लैब के ड्रॉइंग नहीं मिले']);
        $this->actingAs($this->pm);

        // A Marathi letter read by someone whose screen is in English: the letter is Marathi, the labels English.
        $this->post($this->store(), $this->letterForm(self::EOT, ['language' => 'mr', 'hindrance_ids' => [$entry->id], 'addressed_to' => 'कार्यकारी अभियंता']))->assertSessionHasNoErrors();
        $marathi = SiteLetter::latest('id')->first();
        $page = $this->get($this->show($marathi))->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee('<article class="card letter-sheet mb-6" lang="mr">', false)
            ->assertSee('प्रति: ')->assertSee('कार्यकारी अभियंता')->assertSee('पोडियम स्लैब के ड्रॉइंग नहीं मिले')
            ->assertSee($this->marked('Stipulated completion date, as entered under Contract terms', '31 ऑगस्ट 2026'), false)
            ->assertSee($this->marked('Proposed completion date, worked out by the app', '5 सप्टेंबर 2026'), false)
            ->assertSee('Figures in this letter');
        $words = $this->words($page->getContent());
        $this->assertStringContainsString('काम पूर्ण करण्याची ठरलेली तारीख 31 ऑगस्ट 2026 आहे.', $words);
        $this->assertStringContainsString('2 मार्च 2026 ते 6 मार्च 2026: 5 कॅलेंडर दिवस, पहिला आणि शेवटचा दिवस धरून.', $words);
        $this->assertStringContainsString('काम पूर्ण करण्याची मुदत 5 सप्टेंबर 2026 पर्यंत वाढवून मिळावी, ही विनंती.', $words);
        $this->assertStringContainsString('Shivganga Buildcon करिता', $words);

        // A Hindi letter read by someone whose screen is in Marathi, finalised and opened again: still a page of text.
        $this->pm->update(['locale' => 'mr']);
        $this->post($this->store(), $this->letterForm(self::EOT, ['language' => 'hi', 'hindrance_ids' => [$entry->id]]))
            ->assertSessionHas('success', 'मसुदा तुमच्या नोंदींवरून तयार झाला आहे. अंतिम करण्यापूर्वी प्रत्येक आकडा तपासा.');
        $hindi = SiteLetter::latest('id')->first();

        $this->get($this->show($hindi))->assertOk()
            ->assertSee('<article class="card letter-sheet mb-6" lang="hi">', false)
            ->assertSee('या पत्रातील आकडे')->assertSee('पत्र अंतिम करा')->assertSee('मसुदा बदला')
            ->assertSee('हा मसुदा तुमच्या साइट नोंदींवरून तयार केला आहे. पाठवण्यापूर्वी तो तुमच्या करारनाम्याशी पडताळून घ्या.')
            ->assertSee($this->marked('अडथळा 1: पहिला दिवस', '2 मार्च 2026'), false)
            ->assertSee('इथून घेतले: अडथळा नोंदवही')
            ->assertSee('कार्य पूरा करने की निर्धारित तारीख़')
            ->assertDontSee('Figures in this letter')->assertDontSee('Finalise the letter')->assertDontSee('Read from');

        $this->tick($hindi, [])->assertSessionHasErrors('number_ticks');
        $this->assertStringStartsWith('पुढे जाण्यापूर्वी मसुद्यातील प्रत्येक आकड्यावर खूण करा. अजून खूण न केलेले: पत्राची तारीख; कामाचे नाव;', session('errors')->first('number_ticks'));
        $this->tick($hindi)
            ->assertSessionHas('success', 'पत्र अंतिम झाले. आता ते बदलता येणार नाही. ते प्रिंट करा किंवा कॉपी करा: ॲप काहीही पाठवत नाही.');

        $final = $this->get($this->show($hindi))->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertSee('हमारा अनुरोध है कि कार्य पूरा करने का समय 5 सितंबर 2026 तक बढ़ाया जाए।')
            ->assertSee('कृते Shivganga Buildcon')
            ->assertSee('अंतिम करताना खूण केलेले आकडे')->assertSee('सर्व पत्रे')->assertSee('अंतिम केलेले')
            ->assertDontSee('All letters')->assertDontSee('Finalised by');
        $this->assertStringNotContainsString('%PDF', $final->getContent());
        $this->assertSame(0, preg_match('/[०-९]/u', $hindi->refresh()->body_rendered), 'Figures keep their ASCII digits.');

        // The list and its form in Hindi.
        $this->pm->update(['locale' => 'hi']);
        $this->get($this->index())->assertOk()
            ->assertSee('पत्र बनाएँ')->assertSee('समय-वृद्धि का अनुरोध')->assertSee('देरी की सूचना')->assertSee('बकाया भुगतान का पत्र')
            ->assertSee('ऐप कुछ नहीं भेजता। पत्र आप प्रिंट करते हैं या उसका टेक्स्ट कॉपी करते हैं।')
            ->assertSee('अंतिम किया गया')->assertSee('ड्राफ़्ट')
            ->assertDontSee('Prepare a letter')->assertDontSee('Kind of letter');
        $this->post($this->store(), $this->letterForm(self::DELAY, ['hindrance_ids' => [$entry->id]]))
            ->assertSessionHasErrors(['hindrance_ids' => 'देरी की सूचना में उन पुष्टि की हुई प्रविष्टियों का हवाला होता है जो अभी जारी हैं। चुनी गई प्रविष्टियों में से कोई इस बीच खत्म हो गई, खारिज हुई या सुधारी गई है। प्रविष्टियाँ फिर से चुनें।']);

        $this->assertNotNull(Str::uuid());
    }

    public function test_on_the_live_site_a_letter_is_offered_in_hindi_or_marathi_only_once_its_wording_is_reviewed(): void
    {
        $this->agreement();
        $entry = $this->entry('2026-03-02', '2026-03-06');
        $this->actingAs($this->pm);
        $options = function (): array {
            preg_match('/<select id="language".*?<\/select>/s', $this->get($this->index())->assertOk()->getContent(), $select);
            preg_match_all('/<option value="([a-z]+)"/', $select[0], $values);

            return $values[1];
        };
        $prepare = fn (string $language) => $this->post($this->store(), $this->letterForm(self::EOT, ['language' => $language, 'hindrance_ids' => [$entry->id]]));

        // Anywhere but the live site, staging included, every language is offered so that its wording can be read.
        $this->assertSame(['en', 'mr', 'hi'], $options());
        $this->assertSame([], config('wording.reviewed_languages.site_letters'), 'No language is marked reviewed by default.');

        // The live site checks the form's token, which the test environment leaves out.
        $this->app->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->assertSame(['en'], $options());

        foreach (['hi', 'mr'] as $language) {
            $prepare($language)->assertSessionHasErrors('language');
        }

        $this->assertSame(0, SiteLetter::count());
        $prepare('en')->assertSessionHasNoErrors();

        // Once a native reader has reviewed Marathi, the owner lists it, and only it.
        config(['wording.reviewed_languages.site_letters' => ['mr']]);
        $this->assertSame(['en', 'mr'], $options());
        $prepare('mr')->assertSessionHasNoErrors();
        $prepare('hi')->assertSessionHasErrors('language');
        $this->assertSame(['en', 'mr'], SiteLetter::orderBy('id')->pluck('language')->all());

        // A draft keeps its language, and its list shows it by name.
        $this->get($this->index())->assertOk()->assertSee('मराठी');
    }

    public function test_a_notice_of_delay_with_no_entry_still_going_on_says_so_instead_of_an_empty_list(): void
    {
        $this->entry('2026-03-02', '2026-03-06');
        $this->entry('2026-03-09', '2026-03-10');
        $message = 'No confirmed entry of the register is still going on, so a notice of delay has nothing to cite. A request for extension of time can cite entries that have ended.';

        // Shown for a notice of delay in place of the list, which then holds nothing it could show.
        $hidesList = '/overflow-y-auto border-t border-rule"\s+x-show="type !== \'delay_notice\'"/';
        $page = $this->actingAs($this->pm)->get($this->index())->assertOk()
            ->assertSee('x-show="type === \'delay_notice\'" x-cloak>'.$message.'</p>', false)
            ->assertDontSee('The register has no confirmed entry that this letter can cite.');
        $this->assertMatchesRegularExpression($hidesList, $page->getContent());
        $this->assertSame(2, substr_count($page->getContent(), 'name="hindrance_ids[]"'));

        // With one entry still going on there is something to cite, and the list shows it.
        $this->entry('2026-03-12', null);
        $page = $this->get($this->index())->assertOk()->assertDontSee($message);
        $this->assertDoesNotMatchRegularExpression($hidesList, $page->getContent());

        $this->pm->update(['locale' => 'mr']);
        Hindrance::query()->whereNull('ended_on')->update(['ended_on' => '2026-03-14']);
        $this->get($this->index())->assertOk()->assertSee('नोंदवहीतील कोणतीही निश्चित नोंद अजून सुरू नाही');
    }
}
