<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTask;
use App\Services\Crm\ActivityService;
use App\Services\Crm\MessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

/** Every screen opens for a signed-in owner and shows what was saved. */
class CrmPagesTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_screen_opens_with_data_in_place(): void
    {
        $user = $this->crmUser();
        $organization = $user->organization;
        $party = $this->makeParty($organization);
        $lead = $this->makeLead($organization, ['tags' => ['highway'], 'party_id' => $party->id]);
        $tender = $this->makeTender($organization, [
            'party_id' => $party->id, 'lead_id' => $lead->id, 'tags' => ['highway', 'nhai'],
            'due_at' => now()->addDays(2), 'pre_bid_at' => now()->addDay(), 'owner_id' => $user->id, 'bid_value' => '48000000.00',
        ]);
        $this->makePayment($tender, ['valid_until' => today()->addDays(10)]);
        $this->makePayment($tender, ['kind' => 'tender_fee', 'amount' => '2360.00', 'refundable' => false]);
        $this->makeBoqLine($tender);
        app(ActivityService::class)->log($tender, 'note', 'Called the executive engineer', $user);
        CrmTask::create(['organization_id' => $organization->id, 'title' => 'Chase the EE', 'due_on' => today()->subDay(), 'assigned_to' => $user->id, 'subject_type' => 'tender', 'subject_id' => $tender->id]);
        $template = app(MessageService::class)->templates($organization->id)->first();

        foreach ([
            route('crm.dashboard'),
            route('crm.tenders.index'),
            route('crm.tenders.index', ['view' => 'list']),
            route('crm.tenders.index', ['all' => 1, 'q' => 'road']),
            route('crm.tenders.create'),
            route('crm.tenders.create', ['lead' => $lead->id]),
            route('crm.tenders.create', ['party' => $party->id]),
            route('crm.tenders.show', $tender),
            route('crm.tenders.edit', $tender),
            route('crm.payments.index'),
            route('crm.payments.index', ['view' => 'held']),
            route('crm.leads.index'),
            route('crm.leads.index', ['status' => 'new', 'tag' => 'highway', 'q' => 'ramesh']),
            route('crm.leads.create'),
            route('crm.leads.show', $lead),
            route('crm.leads.edit', $lead),
            route('crm.companies.index'),
            route('crm.companies.index', ['type' => 'client', 'tag' => 'highway']),
            route('crm.companies.create'),
            route('crm.companies.show', $party),
            route('crm.companies.edit', $party),
            route('crm.tasks.index'),
            route('crm.tasks.index', ['scope' => 'all', 'when' => 'overdue']),
            route('crm.tasks.index', ['status' => 'done']),
            route('crm.reports.win-loss'),
            route('crm.setup'),
            route('crm.setup', ['tab' => 'reasons']),
            route('crm.setup', ['tab' => 'fields']),
            route('crm.messages.compose', ['template' => $template->id, 'subject_type' => 'tender', 'subject_id' => $tender->id]),
            route('crm.messages.compose', ['template' => $template->id, 'subject_type' => 'lead', 'subject_id' => $lead->id]),
            route('crm.messages.compose', ['template' => $template->id, 'subject_type' => 'company', 'subject_id' => $party->id]),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('crm.tenders.show', $tender))->assertSee('Road widening, NH-48')->assertSee('Called the executive engineer')->assertSee('Chase the EE');
        $this->get(route('crm.dashboard'))->assertSee('Sales overview');
    }

    public function test_menu_lists_the_sales_pages_for_a_plan_that_includes_the_crm(): void
    {
        $this->crmUser();

        $this->get('/dashboard')->assertOk()->assertSee('Sales')->assertSee('Tenders')->assertSee('Leads')->assertSee('Follow-ups');
    }
}
