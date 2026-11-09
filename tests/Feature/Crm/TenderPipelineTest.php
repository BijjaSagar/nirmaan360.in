<?php

namespace Tests\Feature\Crm;

use App\Models\CrmActivity;
use App\Models\CrmTender;
use App\Services\Crm\ReasonService;
use App\Services\Crm\TenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;
use Tests\TestCase;

class TenderPipelineTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function reason(int $organizationId, string $kind): int
    {
        return app(ReasonService::class)->options($organizationId, $kind)->first()->id;
    }

    public function test_a_tender_is_saved_with_its_dates_value_tags_and_owner(): void
    {
        $user = $this->crmUser();
        $client = $this->makeParty($user->organization);

        $this->post('/crm/tenders', [
            'title' => 'Bridge over Mula river', 'authority' => 'PWD Pune', 'reference' => 'PWD/2026/114', 'party_id' => $client->id,
            'estimated_value' => '123456789.45', 'published_on' => '2026-12-01', 'pre_bid_at' => '2026-12-10T11:00',
            'pre_bid_note' => 'Collector office, 2nd floor', 'due_at' => '2026-12-20T15:00', 'opens_at' => '2026-12-22T11:30',
            'tags' => ' Bridge, NHAI ,bridge,  Priority  Client ', 'portal_url' => 'https://mahatenders.gov.in/x', 'state' => 'Maharashtra',
        ])->assertSessionHasNoErrors();

        $tender = CrmTender::firstOrFail();
        $this->assertSame('enquiry', $tender->stage);
        $this->assertSame('123456789.45', $tender->estimated_value);
        $this->assertSame(['bridge', 'nhai', 'priority client'], $tender->tags);
        $this->assertSame('2026-12-20 15:00', $tender->due_at->format('Y-m-d H:i'));
        $this->assertSame('2026-12-10 11:00', $tender->pre_bid_at->format('Y-m-d H:i'));
        $this->assertSame($user->id, $tender->owner_id);
        $this->assertSame($client->id, $tender->party_id);
        $this->assertSame($user->organization_id, $tender->organization_id);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $tender->getMorphClass(), 'auditable_id' => $tender->id, 'action' => 'created']);
        $this->assertSame(1, CrmActivity::query()->where('subject_type', 'tender')->where('subject_id', $tender->id)->count());
    }

    public function test_posting_the_same_form_twice_creates_one_tender(): void
    {
        $this->crmUser();
        $payload = ['title' => 'Canal lining', 'authority' => 'Irrigation Dept', 'client_uuid' => (string) Str::uuid()];

        $this->post('/crm/tenders', $payload)->assertRedirect();
        $this->post('/crm/tenders', $payload)->assertRedirect();

        $this->assertSame(1, CrmTender::count());
    }

    public function test_a_title_and_authority_are_required_and_a_bad_link_is_refused(): void
    {
        $this->crmUser();

        $this->post('/crm/tenders', ['title' => '', 'authority' => ''])->assertSessionHasErrors(['title', 'authority']);
        foreach (['javascript:alert(1)', 'javascript://example.test/%0Aalert(1)', 'data:text/html,hi', 'ftp://files.example.test/x'] as $link) {
            $this->post('/crm/tenders', ['title' => 'x', 'authority' => 'y', 'portal_url' => $link])->assertSessionHasErrors('portal_url');
        }
        $this->post('/crm/tenders', ['title' => 'x', 'authority' => 'y', 'estimated_value' => '-5'])->assertSessionHasErrors('estimated_value');
        $this->assertSame(0, CrmTender::count());
    }

    public function test_a_web_link_to_the_portal_is_kept(): void
    {
        $this->crmUser();

        $this->post('/crm/tenders', ['title' => 'Linked', 'authority' => 'y', 'portal_url' => 'https://mahatenders.gov.in/nit/114'])->assertSessionHasNoErrors();

        $this->assertSame('https://mahatenders.gov.in/nit/114', CrmTender::firstOrFail()->portal_url);
    }

    public function test_a_tender_moves_through_the_open_stages(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.tenders.move', $tender), ['stage' => 'bidding'])->assertRedirect(route('crm.tenders.index').'#bidding');
        $this->post(route('crm.tenders.move', $tender), ['stage' => 'submitted']);

        $this->assertSame('submitted', $tender->fresh()->stage);
        $this->assertSame(2, CrmActivity::query()->where('kind', 'stage')->where('subject_id', $tender->id)->count());
    }

    public function test_moving_to_a_closed_stage_asks_for_the_reason_instead_of_closing(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.tenders.move', $tender), ['stage' => 'lost'])->assertRedirect(route('crm.tenders.show', [$tender, 'close' => 'lost']));

        $this->assertSame('enquiry', $tender->fresh()->stage);
        $this->get(route('crm.tenders.show', [$tender, 'close' => 'lost']))->assertOk()->assertSee('Close tender');
    }

    public function test_an_unknown_stage_is_refused(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.tenders.move', $tender), ['stage' => 'archived'])->assertSessionHasErrors('stage');
        $this->assertSame('enquiry', $tender->fresh()->stage);
    }

    public function test_losing_a_tender_needs_a_reason_of_the_right_kind(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['stage' => 'submitted', 'bid_value' => '48000000.00']);

        $this->post(route('crm.tenders.close', $tender), ['outcome' => 'lost'])->assertSessionHasErrors('outcome_reason_id');
        $this->post(route('crm.tenders.close', $tender), ['outcome' => 'lost', 'outcome_reason_id' => $this->reason($user->organization_id, 'won')])->assertSessionHasErrors('outcome_reason_id');
        $this->assertSame('submitted', $tender->fresh()->stage);

        $this->post(route('crm.tenders.close', $tender), [
            'outcome' => 'lost', 'outcome_reason_id' => $this->reason($user->organization_id, 'lost'),
            'winner_name' => 'Bhosale Constructions', 'winning_value' => '45000000.50', 'outcome_note' => 'Five percent dearer',
        ])->assertSessionHasNoErrors();

        $tender->refresh();
        $this->assertSame('lost', $tender->stage);
        $this->assertSame(today()->toDateString(), $tender->closed_on->toDateString());
        $this->assertSame('Bhosale Constructions', $tender->winner_name);
        $this->assertSame('45000000.50', $tender->winning_value);
        $this->assertNotNull($tender->outcome_reason_id);
    }

    public function test_winning_takes_the_quoted_value_unless_an_award_is_entered(): void
    {
        $user = $this->crmUser();
        $quoted = $this->makeTender($user->organization, ['stage' => 'submitted', 'bid_value' => '48000000.00']);
        $awarded = $this->makeTender($user->organization, ['stage' => 'submitted', 'bid_value' => '48000000.00']);

        $this->post(route('crm.tenders.close', $quoted), ['outcome' => 'won'])->assertSessionHasNoErrors();
        $this->post(route('crm.tenders.close', $awarded), ['outcome' => 'won', 'awarded_value' => '47500000.25', 'closed_on' => '2026-11-01'])->assertSessionHasNoErrors();

        $this->assertSame('48000000.00', $quoted->fresh()->awarded_value);
        $this->assertSame('47500000.25', $awarded->fresh()->awarded_value);
        $this->assertSame('2026-11-01', $awarded->fresh()->closed_on->toDateString());
    }

    public function test_a_closed_tender_cannot_be_closed_or_moved_again_and_a_future_close_date_is_refused(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['stage' => 'submitted']);

        $this->post(route('crm.tenders.close', $tender), ['outcome' => 'won', 'closed_on' => now()->addWeek()->toDateString()])->assertSessionHasErrors('closed_on');

        $this->post(route('crm.tenders.close', $tender), ['outcome' => 'won']);
        $this->post(route('crm.tenders.close', $tender), ['outcome' => 'lost', 'outcome_reason_id' => $this->reason($user->organization_id, 'lost')])->assertSessionHasErrors('outcome');
        $this->post(route('crm.tenders.move', $tender), ['stage' => 'bidding'])->assertSessionHasErrors('stage');

        $this->assertSame('won', $tender->fresh()->stage);
    }

    public function test_reopening_clears_the_result_and_is_logged(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['stage' => 'submitted']);
        $this->post(route('crm.tenders.close', $tender), ['outcome' => 'lost', 'outcome_reason_id' => $this->reason($user->organization_id, 'lost'), 'winner_name' => 'X']);

        $this->post(route('crm.tenders.reopen', $tender), ['stage' => 'submitted'])->assertSessionHasNoErrors();

        $tender->refresh();
        $this->assertSame('submitted', $tender->stage);
        $this->assertNull($tender->closed_on);
        $this->assertNull($tender->outcome_reason_id);
        $this->assertNull($tender->winner_name);
        $this->assertTrue(CrmActivity::query()->where('subject_id', $tender->id)->where('body', 'like', 'Reopened%')->exists());
    }

    public function test_a_tender_that_is_still_open_cannot_be_reopened(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.tenders.reopen', $tender))->assertSessionHasErrors('stage');
    }

    public function test_the_board_groups_tenders_and_adds_values_exactly(): void
    {
        $user = $this->crmUser();
        $this->makeTender($user->organization, ['title' => 'A', 'stage' => 'enquiry', 'estimated_value' => '0.10']);
        $this->makeTender($user->organization, ['title' => 'B', 'stage' => 'enquiry', 'estimated_value' => '0.20']);
        $this->makeTender($user->organization, ['title' => 'C', 'stage' => 'submitted', 'estimated_value' => '100.00', 'bid_value' => '99.99']);

        $board = app(TenderService::class)->board($user->organization_id);

        $this->assertSame(['enquiry', 'bidding', 'submitted', 'won', 'lost', 'no_bid'], array_keys($board));
        $this->assertSame(2, $board['enquiry']['count']);
        $this->assertSame('0.30', $board['enquiry']['value']);
        $this->assertSame('99.99', $board['submitted']['value']);
        $this->assertSame(0, $board['won']['count']);
    }

    public function test_old_closed_tenders_leave_the_board_but_not_the_list(): void
    {
        $user = $this->crmUser();
        $this->makeTender($user->organization, ['title' => 'Recent win', 'stage' => 'won', 'closed_on' => today()->subDays(10)]);
        $this->makeTender($user->organization, ['title' => 'Ancient loss', 'stage' => 'lost', 'closed_on' => today()->subDays(200)]);

        $this->get(route('crm.tenders.index'))->assertSee('Recent win')->assertDontSee('Ancient loss');
        $this->get(route('crm.tenders.index', ['all' => 1]))->assertSee('Ancient loss');
        $this->get(route('crm.tenders.index', ['view' => 'list']))->assertSee('Ancient loss');
    }

    public function test_search_and_tag_filters_narrow_the_board(): void
    {
        $user = $this->crmUser();
        $this->makeTender($user->organization, ['title' => 'Highway package 3', 'tags' => ['highway']]);
        $this->makeTender($user->organization, ['title' => 'School building', 'authority' => 'ZP Nashik', 'tags' => ['education']]);

        $this->get(route('crm.tenders.index', ['q' => 'HIGHWAY']))->assertSee('Highway package 3')->assertDontSee('School building');
        $this->get(route('crm.tenders.index', ['q' => 'nashik']))->assertSee('School building')->assertDontSee('Highway package 3');
        $this->get(route('crm.tenders.index', ['tag' => 'education']))->assertSee('School building')->assertDontSee('Highway package 3');
        $this->get(route('crm.tenders.index', ['tag' => 'shiksha']))->assertDontSee('School building');
    }

    public function test_tags_in_marathi_can_be_filtered(): void
    {
        $user = $this->crmUser();
        $this->makeTender($user->organization, ['title' => 'Pul', 'tags' => ['पूल']]);
        $this->makeTender($user->organization, ['title' => 'Rasta', 'tags' => ['रस्ता']]);

        $this->get(route('crm.tenders.index', ['tag' => 'पूल']))->assertSee('Pul')->assertDontSee('Rasta');
    }

    public function test_an_overdue_or_close_deadline_is_flagged_only_while_the_tender_is_being_prepared(): void
    {
        $user = $this->crmUser();
        $overdue = $this->makeTender($user->organization, ['due_at' => now()->subHour()]);
        $soon = $this->makeTender($user->organization, ['due_at' => now()->addDay(), 'stage' => 'bidding']);
        $later = $this->makeTender($user->organization, ['due_at' => now()->addDays(20)]);
        $submitted = $this->makeTender($user->organization, ['due_at' => now()->subDay(), 'stage' => 'submitted']);

        $this->assertSame('overdue', $overdue->deadlineState());
        $this->assertSame('soon', $soon->deadlineState());
        $this->assertSame('ok', $later->deadlineState());
        $this->assertNull($submitted->deadlineState());
    }

    public function test_changing_the_deadline_resets_the_warning(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['due_at' => now()->addDay(), 'due_alerted_at' => now()]);

        $this->patch(route('crm.tenders.update', $tender), ['title' => $tender->title, 'authority' => $tender->authority, 'due_at' => now()->addDays(5)->format('Y-m-d\TH:i')]);

        $this->assertNull($tender->fresh()->due_alerted_at);
    }

    public function test_editing_a_tender_keeps_what_the_form_did_not_send(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization);
        $tender = $this->makeTender($user->organization, ['lead_id' => $lead->id, 'bid_value' => '100.00', 'stage' => 'bidding']);

        $this->patch(route('crm.tenders.update', $tender), ['title' => 'New title', 'authority' => 'ZP', 'lead_id' => $lead->id, 'estimated_value' => '200', 'bid_value' => '']);

        $tender->refresh();
        $this->assertSame('New title', $tender->title);
        $this->assertSame('bidding', $tender->stage);
        $this->assertSame($lead->id, $tender->lead_id);
        $this->assertSame('200.00', $tender->estimated_value);
        $this->assertNull($tender->bid_value);
    }

    public function test_a_tender_with_unsettled_money_cannot_be_deleted_but_a_clean_one_can(): void
    {
        $user = $this->crmUser();
        $held = $this->makeTender($user->organization);
        $this->makePayment($held);
        $clean = $this->makeTender($user->organization, ['title' => 'Clean']);

        $this->delete(route('crm.tenders.destroy', $held))->assertSessionHasErrors('tender');
        $this->delete(route('crm.tenders.destroy', $clean))->assertRedirect(route('crm.tenders.index'));

        $this->assertNull(CrmTender::find($clean->id));
        $this->assertNotNull(CrmTender::find($held->id));
    }

    public function test_the_service_refuses_to_close_with_a_reason_from_another_kind(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['stage' => 'submitted']);

        $this->expectException(ValidationException::class);
        app(TenderService::class)->close($tender, 'no_bid', ['outcome_reason_id' => $this->reason($user->organization_id, 'won')], $user);
    }

    public function test_changes_are_written_to_the_audit_log(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.tenders.move', $tender), ['stage' => 'bidding']);

        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $tender->getMorphClass(), 'auditable_id' => $tender->id, 'action' => 'updated']);
    }
}
