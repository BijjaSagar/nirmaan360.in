<?php

namespace Tests\Feature\OwnerPack;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Services\OwnerPack\QuotationDocument;
use App\Services\Wording\FactSheet;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** A quotation from draft to issued revision: what the code works out, what it refuses, and what can no longer change. */
class QuotationServiceTest extends TestCase
{
    use BuildsOwnerPack, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->house();
    }

    /** @return list<string> The keys of the errors a refused step gives. */
    private function refused(callable $step): array
    {
        try {
            $step();
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        $this->fail('The step was not refused.');
    }

    public function test_a_draft_copies_the_chosen_boq_lines_as_they_stand_and_works_out_every_figure(): void
    {
        $quotation = $this->draft();

        $this->assertSame([ClientQuotation::DRAFT, 1, 'en', 'Anil Deshmukh'], [$quotation->status, $quotation->revision, $quotation->language, $quotation->client_name]);
        $this->assertMatchesRegularExpression('/^CQ-\d{4}-\d{2}-0001$/', $quotation->number);
        $this->assertSame([$this->organization->id, $this->project->id, $this->manager->id], [$quotation->organization_id, $quotation->project_id, $quotation->created_by]);

        $items = $quotation->items()->get();
        $this->assertSame(['A-01', 'B-04', 'C-09'], $items->pluck('code')->all());
        $this->assertSame(['52501.25', '76000.00', '18333.33'], $items->pluck('amount')->all());
        $this->assertSame(['12.500', '4200.10', 'cum', $this->lines[0]->id, $this->project->id], [$items[0]->qty, $items[0]->rate, $items[0]->unit, $items[0]->boq_item_id, $items[0]->project_id]);

        $this->assertSame(['146834.58', '7.50', '11012.59', '157847.17'], [$quotation->subtotal, $quotation->gst_pct, $quotation->gst_amount, $quotation->total]);

        // The facts kept with the draft are the figures above, and their fingerprint is theirs.
        $sheet = FactSheet::fromArray($quotation->facts);
        $this->assertSame('157847.17', $sheet->get('total')->value);
        $this->assertSame('7.5', $sheet->get('gst_pct')->value);
        $this->assertSame('3', $sheet->get('line_count')->value);
        $this->assertSame('52501.25', $sheet->get('line_1_amount')->value);
        $this->assertSame('Rupees One Lakh Fifty Seven Thousand Eight Hundred Forty Seven and Seventeen Paise Only', $sheet->get('total_words')->value);
        $this->assertTrue($sheet->matches($quotation->fact_fingerprint));
    }

    public function test_a_later_boq_change_does_not_reach_a_quotation_already_prepared(): void
    {
        $quotation = $this->draft();

        $this->lines[0]->update(['rate' => '9999.00', 'description' => 'Changed afterwards']);
        $this->lines[1]->delete();

        $this->assertSame('157847.17', $quotation->fresh()->total);
        $this->assertSame(['Excavation for foundation in all soils', 'Brick masonry in cement mortar', 'Site clearance and levelling'], $quotation->items()->pluck('description')->all());
    }

    public function test_no_gst_percentage_is_assumed_when_none_is_typed(): void
    {
        // The project itself carries a GST default from an older screen. It must not leak into a quotation.
        $this->assertNotNull($this->project->fresh()->gst_pct);

        $quotation = $this->draft(['gst_pct' => null]);

        $this->assertNull($quotation->gst_pct);
        $this->assertSame(['146834.58', '0.00', '146834.58'], [$quotation->subtotal, $quotation->gst_amount, $quotation->total]);
        $this->assertFalse(FactSheet::fromArray($quotation->facts)->has('gst_pct'));

        [, $note] = $this->quotations()->review($quotation);
        $this->assertStringContainsString('GST is not included in the amounts of this quotation.', $note->text());
    }

    public function test_preparing_twice_with_the_same_client_uuid_returns_the_first_draft(): void
    {
        $uuid = (string) Str::uuid();
        $first = $this->draft(['client_uuid' => $uuid]);
        $again = $this->draft(['client_uuid' => $uuid, 'client_name' => 'Somebody Else']);

        $this->assertSame($first->id, $again->id);
        $this->assertSame('Anil Deshmukh', $again->client_name);
        $this->assertSame(1, ClientQuotation::withoutGlobalScopes()->count());
        $this->assertSame(3, ClientQuotationItem::withoutGlobalScopes()->count());

        // The next quotation takes the next number: the repeat used none up.
        $this->assertStringEndsWith('-0002', $this->draft()->number);
    }

    public function test_stage_amounts_follow_the_total_and_add_up_to_it_to_the_paisa(): void
    {
        $quotation = $this->withStages($this->draft());

        $this->assertSame(['47354.15', '52615.67', '57877.35'], $quotation->stages()->pluck('amount')->all());
        $this->assertSame('157847.17', (string) $quotation->stages()->get()->reduce(fn ($sum, $stage) => $sum->plus($stage->amount), BigDecimal::zero()));

        // Another percentage typed for GST changes the total, and every stage with it.
        $quotation = $this->quotations()->update($quotation, ['language' => 'en', 'client_name' => 'Anil Deshmukh', 'gst_pct' => '0'], $this->manager);

        $this->assertSame('146834.58', $quotation->total);
        $this->assertSame(['44050.37', '48944.81', '53839.40'], $quotation->stages()->pluck('amount')->all());
        $this->assertTrue(FactSheet::fromArray($quotation->facts)->matches($quotation->fact_fingerprint));
        $this->assertSame('53839.40', FactSheet::fromArray($quotation->facts)->get('stage_3_amount')->value);
    }

    public function test_stages_that_would_pass_the_total_are_refused_and_nothing_is_saved(): void
    {
        $quotation = $this->draft();
        $this->stage($quotation, 'Advance', null, '100000');

        $this->assertSame(['amount'], $this->refused(fn () => $this->stage($quotation, 'Too much', null, '57847.18')));
        $this->assertSame(['percent'], $this->refused(function () {
            $other = $this->draft();
            $this->stage($other, 'First', '70');
            $this->stage($other, 'Second', '30.5');
        }));

        $this->assertSame(1, ClientPaymentStage::withoutGlobalScopes()->where('quotation_id', $quotation->id)->count());
        $this->assertSame('100000.00', $quotation->stages()->sole()->amount);

        // The rest to the paisa is accepted.
        $this->stage($quotation, 'Balance', null, '57847.17');
        $this->assertSame(2, $quotation->stages()->count());
    }

    public function test_percentages_that_do_not_make_the_whole_cannot_be_issued(): void
    {
        $quotation = $this->draft();
        $this->stage($quotation, 'On agreement', '30');
        $this->stage($quotation, 'On completion', '60');

        $this->assertSame(['percent'], $this->refused(fn () => $this->quotations()->issue($quotation, $this->allTicks($quotation), $this->manager)));
        $this->assertSame(ClientQuotation::DRAFT, $quotation->fresh()->status);

        $this->stage($quotation, 'On handing over', '10');
        $this->assertSame(ClientQuotation::ISSUED, $this->issued($quotation)->status);
    }

    public function test_typed_amount_stages_may_leave_a_balance_and_the_document_says_so(): void
    {
        $quotation = $this->draft();
        $this->stage($quotation, 'Advance', null, '50000');
        $this->stage($quotation, 'On plinth', '20');

        [$sheet, $note] = $this->quotations()->review($quotation->fresh());

        $this->assertSame('81569.43', $sheet->get('stages_total')->value);
        $this->assertSame('76277.74', $sheet->get('stages_balance')->value);
        $this->assertStringContainsString('The balance of ₹76,277.74 is not covered by a stage.', $note->text());
        $this->assertSame(ClientQuotation::ISSUED, $this->issued($quotation)->status);
    }

    public function test_a_stage_can_be_placed_in_the_order_and_the_last_one_takes_the_remainder(): void
    {
        // Quoted without GST the total is 1,46,834.58, where a share and the remainder differ by a paisa.
        $quotation = $this->draft(['gst_pct' => null]);
        $this->stage($quotation, 'On agreement', '30');
        $this->stage($quotation, 'On completion of plinth', '33.3333');
        $handing = $this->stage($quotation, 'On handing over', '36.6667', null, ['position' => 1]);

        $this->assertSame(['On handing over', 'On agreement', 'On completion of plinth'], $quotation->stages()->pluck('title')->all());
        $this->assertSame([1, 2, 3], $quotation->stages()->pluck('sort')->map(fn ($sort) => (int) $sort)->all());
        // "On completion of plinth" is last, so it takes what is left: 48,944.82 and not its plain share of 48,944.81.
        $this->assertSame(['53839.39', '44050.37', '48944.82'], $quotation->stages()->pluck('amount')->all());

        $this->quotations()->updateStage($handing, ['title' => 'On handing over', 'basis' => 'percent', 'percent' => '36.6667', 'position' => 3], $this->manager);

        $this->assertSame(['On agreement', 'On completion of plinth', 'On handing over'], $quotation->stages()->pluck('title')->all());
        $this->assertSame(['44050.37', '48944.81', '53839.40'], $quotation->stages()->pluck('amount')->all());
        $this->assertSame('146834.58', FactSheet::fromArray($quotation->fresh()->facts)->get('stages_total')->value);

        // Taking a stage away leaves two shares short of the whole: each is its plain share again.
        $this->quotations()->removeStage($handing, $this->manager);
        $this->assertSame(['44050.37', '48944.81'], $quotation->stages()->pluck('amount')->all());
        $this->assertSame('53839.40', FactSheet::fromArray($quotation->fresh()->facts)->get('stages_balance')->value);
    }

    public function test_issuing_is_refused_while_a_figure_is_not_ticked(): void
    {
        $quotation = $this->withStages($this->draft());
        $all = $this->allTicks($quotation);

        // Every figure of the cover note, every typed text that prints, and every line of the table.
        foreach (['quotation_number', 'revision', 'client_name', 'line_count', 'subtotal', 'gst_pct', 'gst_amount', 'total', 'total_words', 'valid_until',
            'stage_count', 'stage_1_title', 'stage_1_percent', 'stage_1_amount', 'stage_2_condition', 'stage_3_amount', 'stages_total', 'line_1', 'line_2', 'line_3'] as $figure) {
            $this->assertContains($figure, $all['number_ticks']);

            $ticks = array_values(array_diff($all['number_ticks'], [$figure]));
            $this->assertSame(['number_ticks'], $this->refused(fn () => $this->quotations()->issue($quotation, [...$all, 'number_ticks' => $ticks], $this->manager)), "Issued without a tick for {$figure}.");
        }

        $this->assertSame(['number_ticks'], $this->refused(fn () => $this->quotations()->issue($quotation, [...$all, 'number_ticks' => []], $this->manager)));
        $this->assertSame(ClientQuotation::DRAFT, $quotation->fresh()->status);

        try {
            $this->quotations()->issue($quotation, [...$all, 'number_ticks' => array_values(array_diff($all['number_ticks'], ['total', 'line_2']))], $this->manager);
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Quotation total', $e->getMessage());
            $this->assertStringContainsString('Line 2', $e->getMessage());
        }
    }

    public function test_issuing_records_who_issued_it_and_what_was_ticked(): void
    {
        $quotation = $this->withStages($this->draft());
        $all = $this->allTicks($quotation);

        $issued = $this->quotations()->issue($quotation, [...$all, 'number_ticks' => [...$all['number_ticks'], 'made_up_tick']], $this->manager)->fresh();

        $this->assertSame([ClientQuotation::ISSUED, $this->manager->id], [$issued->status, $issued->issued_by]);
        $this->assertNotNull($issued->issued_at);
        $this->assertSame($all['number_ticks'], $issued->number_ticks);
        $this->assertSame($all['fact_fingerprint'], $issued->fact_fingerprint);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'quotation.issued')->sole();
        $this->assertSame([$this->organization->id, $this->manager->id, $issued->id], [$audit->organization_id, $audit->user_id, $audit->auditable_id]);
        $this->assertSame('157847.17', $audit->new_values['total']);

        // A second press of the button changes nothing and is not an error.
        $again = $this->quotations()->issue($issued, ['fact_fingerprint' => str_repeat('0', 64), 'number_ticks' => []], $this->owner);
        $this->assertSame($this->manager->id, $again->issued_by);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'quotation.issued')->count());
    }

    public function test_issuing_is_refused_when_the_draft_changed_after_the_page_was_opened(): void
    {
        $quotation = $this->withStages($this->draft());
        $seen = $this->allTicks($quotation);

        // Somebody else changes the percentage typed for GST while the first person is still ticking.
        $this->quotations()->update($quotation, ['language' => 'en', 'client_name' => 'Anil Deshmukh', 'gst_pct' => '2.5'], $this->owner);

        $this->assertSame(['facts'], $this->refused(fn () => $this->quotations()->issue($quotation, $seen, $this->manager)));
        $this->assertSame(ClientQuotation::DRAFT, $quotation->fresh()->status);

        // The same keys are ticked on the new page, and with its fingerprint the draft is issued.
        $this->assertSame($seen['number_ticks'], $this->allTicks($quotation)['number_ticks']);
        $this->assertSame(ClientQuotation::ISSUED, $this->issued($quotation)->status);
    }

    public function test_issuing_is_refused_when_a_row_no_longer_gives_the_figures_kept_with_the_draft(): void
    {
        $quotation = $this->withStages($this->draft());
        $seen = $this->allTicks($quotation);

        // Changed underneath the service: a rate, then only a stored total.
        DB::table('client_quotation_items')->where('quotation_id', $quotation->id)->where('code', 'B-04')->update(['rate' => '951.00']);
        $this->assertSame(['facts'], $this->refused(fn () => $this->quotations()->issue($quotation, $seen, $this->manager)));

        DB::table('client_quotation_items')->where('quotation_id', $quotation->id)->where('code', 'B-04')->update(['rate' => '950.00']);
        DB::table('client_quotations')->where('id', $quotation->id)->update(['total' => '157847.18']);
        $this->assertSame(['facts'], $this->refused(fn () => $this->quotations()->issue($quotation, $seen, $this->manager)));

        DB::table('client_quotations')->where('id', $quotation->id)->update(['total' => '157847.17']);
        DB::table('client_payment_stages')->where('quotation_id', $quotation->id)->where('sort', 1)->update(['amount' => '47354.16']);
        $this->assertSame(['facts'], $this->refused(fn () => $this->quotations()->issue($quotation, $seen, $this->manager)));

        $this->assertSame(ClientQuotation::DRAFT, $quotation->fresh()->status);
    }

    public function test_a_quotation_with_no_amount_or_a_validity_date_that_has_passed_cannot_be_issued(): void
    {
        $empty = $this->draft(['boq_item_ids' => [$this->line($this->project, 'Z-01', 'Provisional item', 'LS', '0.000', '5000.00', 9)->id]]);
        $this->assertSame(['boq_item_ids'], $this->refused(fn () => $this->quotations()->issue($empty, $this->allTicks($empty), $this->manager)));

        $quotation = $this->draft();
        $this->travel(21)->days();
        $this->assertSame(['valid_until'], $this->refused(fn () => $this->quotations()->issue($quotation, $this->allTicks($quotation), $this->manager)));
    }

    public function test_only_someone_who_may_see_money_can_issue(): void
    {
        $quotation = $this->draft();
        $engineer = $this->member($this->organization, 'engineer');

        $this->expectException(AuthorizationException::class);
        $this->quotations()->issue($quotation, $this->allTicks($quotation), $engineer);
    }

    public function test_a_number_typed_into_the_opening_needs_its_own_tick_and_is_written_to_the_audit_log(): void
    {
        $quotation = $this->draft();
        $quotation = $this->quotations()->update($quotation, [
            'language' => 'en', 'client_name' => 'Anil Deshmukh', 'gst_pct' => self::TYPED_GST,
            'opening' => 'As agreed on the phone, work can start within 10 days of your word.',
            'closing' => '',
        ], $this->manager);

        $this->assertSame(['opening'], array_keys($quotation->slots));
        $this->assertSame('edited', $quotation->slots['opening']['source']);

        $all = $this->allTicks($quotation);
        $this->assertContains('typed:opening:1:10', $all['number_ticks']);

        $this->assertSame(['number_ticks'], $this->refused(fn () => $this->quotations()->issue($quotation, [...$all, 'number_ticks' => array_values(array_diff($all['number_ticks'], ['typed:opening:1:10']))], $this->manager)));

        $issued = $this->quotations()->issue($quotation, $all, $this->manager);
        [, $note] = $this->quotations()->review($issued);
        $this->assertStringStartsWith('As agreed on the phone, work can start within 10 days of your word.', $note->text());

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'wording.typed_numbers_ticked')->sole();
        $this->assertSame([$this->organization->id, $this->manager->id, $issued->id], [$audit->organization_id, $audit->user_id, $audit->auditable_id]);
        $this->assertSame(['10'], array_column($audit->new_values['numbers'], 'text'));
    }

    public function test_the_code_sentence_is_never_stored_so_it_follows_the_language(): void
    {
        $quotation = $this->draft();
        [, $english] = $this->quotations()->review($quotation);

        // The form sends back the English sentences it showed, together with the new language.
        $quotation = $this->quotations()->update($quotation, [
            'language' => 'mr', 'client_name' => 'Anil Deshmukh', 'gst_pct' => self::TYPED_GST,
            'opening' => $english->slot('opening')->text, 'closing' => $english->slot('closing')->text,
        ], $this->manager);

        $this->assertNull($quotation->slots);
        [, $marathi] = $this->quotations()->review($quotation);
        $this->assertSame('तुमच्या कामासाठी आमच्याकडून कोटेशन मागवल्याबद्दल धन्यवाद. तपशील खाली दिला आहे.', $marathi->slot('opening')->text);
        $this->assertSame('template', $marathi->slot('closing')->source);
    }

    public function test_an_issued_quotation_cannot_be_changed_by_any_way_in(): void
    {
        $issued = $this->issued();
        $stage = $issued->stages()->first();
        $item = $issued->items()->first();

        $steps = [
            'update' => fn () => $this->quotations()->update($issued, ['language' => 'hi', 'client_name' => 'Other', 'gst_pct' => '1'], $this->owner),
            're-copy lines' => fn () => $this->quotations()->update($issued, ['boq_item_ids' => [$this->lines[0]->id]], $this->owner),
            'add stage' => fn () => $this->stage($issued, 'Extra', null, '1'),
            'change stage' => fn () => $this->quotations()->updateStage($stage, ['title' => 'Renamed', 'basis' => 'percent', 'percent' => '30'], $this->owner),
            'remove stage' => fn () => $this->quotations()->removeStage($stage, $this->owner),
            'model total' => fn () => $issued->update(['total' => '1.00']),
            'model status' => fn () => $issued->update(['status' => ClientQuotation::DRAFT]),
            'model delete' => fn () => $issued->delete(),
            'line rate' => fn () => $item->update(['rate' => '1.00']),
            'line delete' => fn () => $item->delete(),
            'new line' => fn () => ClientQuotationItem::create([...$item->only(['organization_id', 'project_id', 'quotation_id', 'code', 'description', 'unit', 'qty', 'rate', 'amount']), 'sort' => 9]),
            'stage amount' => fn () => $stage->update(['amount' => '1.00']),
            'stage delete' => fn () => $stage->delete(),
            'new stage' => fn () => ClientPaymentStage::create([...$stage->only(['organization_id', 'project_id', 'quotation_id', 'basis', 'percent', 'amount']), 'title' => 'Slipped in', 'sort' => 9]),
        ];

        foreach ($steps as $name => $step) {
            try {
                $step();
                $this->fail("An issued quotation was changed: {$name}.");
            } catch (ImmutableRecordException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }

        $after = $issued->fresh();
        $this->assertSame([ClientQuotation::ISSUED, '157847.17', 'en', 'Anil Deshmukh'], [$after->status, $after->total, $after->language, $after->client_name]);
        $this->assertSame(3, $after->items()->count());
        $this->assertSame(['On agreement', 'On completion of plinth', 'On handing over'], $after->stages()->pluck('title')->all());
        $this->assertSame('4200.10', $after->items()->first()->rate);
    }

    public function test_reaching_a_stage_is_recorded_only_on_an_issued_quotation_that_still_stands(): void
    {
        $draft = $this->withStages($this->draft());
        $this->assertSame(['reached'], $this->refused(fn () => $this->quotations()->markReached($draft->stages()->first(), ['reached' => true], $this->manager)));

        $issued = $this->issued($draft);
        $stage = $this->quotations()->markReached($issued->stages()->first(), ['reached' => '1', 'reached_on' => today()->subDay()->toDateString()], $this->manager);

        $this->assertSame([today()->subDay()->toDateString(), $this->manager->id], [$stage->fresh()->reached_on->toDateString(), $stage->fresh()->reached_by]);
        $this->assertSame('47354.15', $stage->fresh()->amount);

        $this->quotations()->markReached($stage, ['reached' => '0'], $this->manager);
        $this->assertNull($stage->fresh()->reached_on);

        // Once a later revision is issued, the stage is marked there.
        $this->issued($this->quotations()->revise($issued, $this->manager));
        $this->assertSame(['reached'], $this->refused(fn () => $this->quotations()->markReached($stage, ['reached' => true], $this->manager)));
    }

    public function test_a_revision_is_a_new_draft_under_the_same_number_and_the_issued_one_stays(): void
    {
        $issued = $this->issued();
        $this->quotations()->markReached($issued->stages()->first(), ['reached' => true], $this->manager);
        $uuid = (string) Str::uuid();

        $revision = $this->quotations()->revise($issued, $this->manager, $uuid);

        $this->assertSame([$issued->number, 2, ClientQuotation::DRAFT, '157847.17'], [$revision->number, $revision->revision, $revision->status, $revision->total]);
        $this->assertNull($revision->issued_at);
        $this->assertNull($revision->number_ticks);
        $this->assertSame(['A-01', 'B-04', 'C-09'], $revision->items()->pluck('code')->all());
        $this->assertSame(['47354.15', '52615.67', '57877.35'], $revision->stages()->pluck('amount')->all());
        $this->assertNotNull($revision->stages()->first()->reached_on);
        $this->assertNotSame($issued->fact_fingerprint, $revision->fact_fingerprint, 'The facts of a revision are its own rows.');

        // Asked again, with the same key or by another press, the same draft comes back.
        $this->assertSame($revision->id, $this->quotations()->revise($issued, $this->manager, $uuid)->id);
        $this->assertSame($revision->id, $this->quotations()->revise($issued, $this->owner, (string) Str::uuid())->id);
        $this->assertSame(2, ClientQuotation::withoutGlobalScopes()->count());

        // The draft can change; the issued one did not.
        $this->quotations()->update($revision, ['language' => 'en', 'client_name' => 'Anil Deshmukh', 'gst_pct' => '1'], $this->manager);
        $this->assertSame('157847.17', $issued->fresh()->total);
        $this->assertNull($this->quotations()->replacedBy($issued), 'A draft revision replaces nothing yet.');
        $this->assertSame($revision->id, $this->quotations()->openRevision($issued)->id);

        $this->issued($revision);
        $this->assertSame(2, $this->quotations()->replacedBy($issued));
        $this->assertNull($this->quotations()->replacedBy($revision->fresh()));

        // Only the latest revision is revised, and a draft is changed directly.
        $this->assertSame(['quotation'], $this->refused(fn () => $this->quotations()->revise($issued, $this->manager)));
        $this->assertSame(['quotation'], $this->refused(fn () => $this->quotations()->revise($this->draft(), $this->manager)));
        $this->assertSame(3, $this->quotations()->revise($revision->fresh(), $this->manager)->revision);
    }

    public function test_the_cover_note_is_written_in_hindi_and_marathi_with_plain_digits(): void
    {
        $expected = [
            'hi' => ['कोटेशन', 'इस कोटेशन का कुल योग ₹1,57,847.17 है।', 'इस मूल्य पर 7.5% की दर से GST ₹11,012.59 होता है।', 'On agreement: कुल का 30%, यानी ₹47,354.15।'],
            'mr' => ['कोटेशन', 'या कोटेशनची एकूण रक्कम ₹1,57,847.17 आहे.', 'या मूल्यावर 7.5% दराने GST ₹11,012.59 होतो.', 'On agreement: एकूण रकमेच्या 30%, म्हणजे ₹47,354.15.'],
        ];

        foreach ($expected as $language => $sentences) {
            $quotation = $this->withStages($this->draft(['language' => $language]));
            [$sheet, $note] = $this->quotations()->review($quotation);
            $text = $note->text();

            foreach ($sentences as $sentence) {
                $this->assertStringContainsString($sentence, $text);
            }

            // The amount in words exists in English only, so it is not printed here and needs no tick.
            $this->assertStringNotContainsString('Rupees', $text);
            $this->assertNotContains('total_words', app(QuotationDocument::class)->ticksNeeded($note, $sheet));
            $this->assertDoesNotMatchRegularExpression('/[\x{0966}-\x{096F}]/u', $text, 'Digits stay ASCII.');
            $this->assertSame(ClientQuotation::ISSUED, $this->issued($quotation)->status);
        }
    }

    public function test_the_audit_trail_names_the_steps_and_never_holds_the_share_token_or_the_fact_sheet(): void
    {
        [$quotation] = $this->shared();

        $actions = AuditLog::withoutGlobalScopes()->where('auditable_type', $quotation->getMorphClass())->pluck('action')->all();
        $this->assertSame(['quotation.created', 'quotation.issued', 'quotation.shared'], $actions);

        $everything = json_encode(AuditLog::withoutGlobalScopes()->get()->toArray());
        $this->assertStringNotContainsString($quotation->share_token, $everything);
        $this->assertStringNotContainsString('line_1_work', $everything);
        $this->assertArrayNotHasKey('share_token', $quotation->toArray());
    }
}
