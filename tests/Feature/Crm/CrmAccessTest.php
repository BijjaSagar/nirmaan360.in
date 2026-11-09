<?php

namespace Tests\Feature\Crm;

use App\Models\CrmActivity;
use App\Models\CrmCustomField;
use App\Models\CrmOutcomeReason;
use App\Models\CrmTask;
use App\Models\CrmTemplate;
use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Services\Crm\ReasonService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\BuildsTenant;
use Tests\TestCase;

class CrmAccessTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/crm')->assertRedirect('/login');
        $this->get('/crm/tenders')->assertRedirect('/login');
    }

    public function test_free_plan_does_not_include_the_crm(): void
    {
        $this->crmUser('owner', 'free');

        $this->get('/crm')->assertForbidden();
        $this->get('/crm/tenders')->assertForbidden();
        $this->post('/crm/tenders', ['title' => 'x', 'authority' => 'y'])->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee('Sales overview');
    }

    public function test_contractor_business_and_enterprise_plans_include_it(): void
    {
        foreach (['contractor', 'business', 'enterprise'] as $plan) {
            $this->crmUser('owner', $plan, 'Company '.$plan);
            $this->get('/crm')->assertOk();
        }
    }

    public function test_roles_get_the_access_the_module_gives_them(): void
    {
        $organization = $this->tenant();
        $tender = $this->makeTender($organization);
        $payload = ['title' => 'New tender', 'authority' => 'MSRDC'];

        // Roles that never see the CRM.
        foreach (['engineer', 'store', 'viewer'] as $role) {
            $this->actingAs($this->member($organization, $role));
            $this->get('/crm')->assertForbidden();
            $this->get(route('crm.tenders.show', $tender))->assertForbidden();
            $this->post('/crm/tenders', $payload)->assertForbidden();
        }

        // QS can look but not change.
        $this->actingAs($this->member($organization, 'qs'));
        $this->get('/crm')->assertOk();
        $this->get(route('crm.tenders.show', $tender))->assertOk();
        $this->get(route('crm.tenders.create'))->assertForbidden();
        $this->post('/crm/tenders', $payload)->assertForbidden();
        $this->post(route('crm.payments.store', $tender), ['kind' => 'emd', 'amount' => '100', 'mode' => 'online'])->assertForbidden();
        $this->get(route('crm.setup'))->assertForbidden();
        $this->get(route('crm.tenders.convert', $tender))->assertForbidden();

        // Accounts can look and record EMD and fees, but cannot add tenders.
        $this->actingAs($this->member($organization, 'accounts'));
        $this->get('/crm')->assertOk();
        $this->post('/crm/tenders', $payload)->assertForbidden();
        $this->post(route('crm.payments.store', $tender), ['kind' => 'emd', 'amount' => '100', 'mode' => 'online'])->assertRedirect();
        $this->assertSame(1, $tender->payments()->count());

        // The project manager runs the whole module.
        $this->actingAs($this->member($organization, 'pm'));
        $this->post('/crm/tenders', $payload)->assertRedirect();
        $this->get(route('crm.setup'))->assertOk();
    }

    public function test_menu_shows_the_module_to_those_who_can_use_it(): void
    {
        $organization = $this->tenant();

        $this->actingAs($this->member($organization, 'pm'))->get('/dashboard')->assertSee('Sales overview')->assertSee('Sales setup');
        $this->actingAs($this->member($organization, 'accounts'))->get('/dashboard')->assertSee('Sales overview')->assertDontSee('Sales setup');
        $this->actingAs($this->member($organization, 'engineer'))->get('/dashboard')->assertDontSee('Sales overview');
    }

    public function test_another_companys_records_cannot_be_reached_by_id(): void
    {
        Storage::fake();
        $other = $this->tenant('Other Infra');
        $otherUser = $this->member($other, 'owner');
        $tender = $this->makeTender($other);
        $payment = $this->makePayment($tender);
        $line = $this->makeBoqLine($tender);
        $lead = $this->makeLead($other);
        $party = $this->makeParty($other);
        $project = $this->makeProject($other);
        $reason = app(ReasonService::class)->options($other->id, 'lost')->first();
        $task = CrmTask::create(['organization_id' => $other->id, 'title' => 'Chase', 'due_on' => today(), 'assigned_to' => $otherUser->id]);
        $template = CrmTemplate::create(['organization_id' => $other->id, 'name' => 'Hi', 'channel' => 'whatsapp', 'body' => 'Hello']);
        $field = CrmCustomField::create(['organization_id' => $other->id, 'entity' => 'lead', 'key' => 'area', 'label' => 'Area', 'type' => 'text']);
        Storage::put('org-'.$other->id.'/doc.pdf', 'pdf');
        $document = CrmTenderDocument::create(['organization_id' => $other->id, 'tender_id' => $tender->id, 'title' => 'NIT', 'kind' => 'nit', 'path' => 'org-'.$other->id.'/doc.pdf', 'original_name' => 'nit.pdf']);

        $this->crmUser();

        $notFound = [
            ['get', route('crm.tenders.show', $tender)],
            ['get', route('crm.tenders.edit', $tender)],
            ['patch', route('crm.tenders.update', $tender), ['title' => 'x', 'authority' => 'y']],
            ['delete', route('crm.tenders.destroy', $tender)],
            ['post', route('crm.tenders.move', $tender), ['stage' => 'bidding']],
            ['post', route('crm.tenders.close', $tender), ['outcome' => 'lost', 'outcome_reason_id' => $reason->id]],
            ['post', route('crm.tenders.reopen', $tender)],
            ['get', route('crm.tenders.convert', $tender)],
            ['post', route('crm.tenders.convert.store', $tender), ['name' => 'P', 'contract_value' => 1]],
            ['post', route('crm.payments.store', $tender), ['kind' => 'emd', 'amount' => '10', 'mode' => 'online']],
            ['patch', route('crm.payments.update', $payment), ['kind' => 'emd', 'amount' => '10', 'mode' => 'online']],
            ['post', route('crm.payments.settle', $payment), ['outcome' => 'refunded']],
            ['post', route('crm.payments.undo', $payment)],
            ['delete', route('crm.payments.destroy', $payment)],
            ['post', route('crm.boq.store', $tender), ['code' => '1', 'description' => 'd', 'unit' => 'cum', 'qty' => 1, 'rate' => 1]],
            ['patch', route('crm.boq.update', $line), ['code' => '1', 'description' => 'd', 'unit' => 'cum', 'qty' => 1, 'rate' => 1]],
            ['delete', route('crm.boq.destroy', $line)],
            ['post', route('crm.documents.store', $tender)],
            ['get', route('crm.documents.download', $document)],
            ['delete', route('crm.documents.destroy', $document)],
            ['get', route('crm.project-file', $project)],
            ['get', route('crm.leads.show', $lead)],
            ['get', route('crm.leads.edit', $lead)],
            ['patch', route('crm.leads.update', $lead), ['name' => 'x', 'source' => 'other']],
            ['delete', route('crm.leads.destroy', $lead)],
            ['post', route('crm.leads.status', $lead), ['status' => 'contacted']],
            ['post', route('crm.leads.convert', $lead), ['type' => 'client']],
            ['get', route('crm.companies.show', $party)],
            ['get', route('crm.companies.edit', $party)],
            ['patch', route('crm.companies.update', $party), ['type' => 'client', 'name' => 'x']],
            ['patch', route('crm.tasks.update', $task), ['title' => 'x', 'due_on' => '2026-12-20']],
            ['post', route('crm.tasks.done', $task)],
            ['post', route('crm.tasks.reopen', $task)],
            ['delete', route('crm.tasks.destroy', $task)],
            ['patch', route('crm.templates.update', $template), ['name' => 'x', 'channel' => 'whatsapp', 'body' => 'y']],
            ['delete', route('crm.templates.destroy', $template)],
            ['patch', route('crm.reasons.update', $reason), ['label' => 'x']],
            ['patch', route('crm.fields.update', $field), ['label' => 'x']],
            ['get', route('crm.messages.compose', ['template' => $template->id, 'subject_type' => 'tender', 'subject_id' => $tender->id])],
        ];

        foreach ($notFound as $request) {
            $this->call($request[0], $request[1], $request[2] ?? [])->assertNotFound();
        }

        $this->assertSame('PWD Pune', $tender->fresh()->authority);
        $this->assertSame('enquiry', $tender->fresh()->stage);
        $this->assertSame(1, $tender->payments()->withoutGlobalScopes()->count());
        $this->assertSame('500000.00', $payment->fresh()->amount);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue(CrmOutcomeReason::withoutGlobalScopes()->whereKey($reason->id)->exists());
    }

    public function test_another_companys_ids_are_refused_in_forms(): void
    {
        $other = $this->tenant('Other Infra');
        $otherUser = $this->member($other, 'owner');
        $otherParty = $this->makeParty($other);
        $otherLead = $this->makeLead($other);
        $otherTender = $this->makeTender($other);
        $otherReason = app(ReasonService::class)->options($other->id, 'lost')->first();

        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);
        $lead = $this->makeLead($user->organization);

        $this->post('/crm/tenders', ['title' => 'T', 'authority' => 'A', 'party_id' => $otherParty->id])->assertSessionHasErrors('party_id');
        $this->post('/crm/tenders', ['title' => 'T', 'authority' => 'A', 'owner_id' => $otherUser->id])->assertSessionHasErrors('owner_id');
        $this->post('/crm/tenders', ['title' => 'T', 'authority' => 'A', 'lead_id' => $otherLead->id])->assertSessionHasErrors('lead_id');
        $this->post('/crm/leads', ['name' => 'L', 'source' => 'other', 'owner_id' => $otherUser->id])->assertSessionHasErrors('owner_id');
        $this->post(route('crm.leads.convert', $lead), ['type' => 'client', 'party_id' => $otherParty->id])->assertSessionHasErrors('party_id');
        $this->post(route('crm.tenders.close', $tender), ['outcome' => 'lost', 'outcome_reason_id' => $otherReason->id])->assertSessionHasErrors('outcome_reason_id');
        $this->post('/crm/tasks', ['title' => 'x', 'due_on' => '2026-12-20', 'subject_type' => 'tender', 'subject_id' => $otherTender->id])->assertSessionHasErrors('subject_id');
        $this->post('/crm/tasks', ['title' => 'x', 'due_on' => '2026-12-20', 'assigned_to' => $otherUser->id])->assertSessionHasErrors('assigned_to');
        $this->post('/crm/activities', ['subject_type' => 'lead', 'subject_id' => $otherLead->id, 'kind' => 'note', 'body' => 'x'])->assertSessionHasErrors('subject_id');

        $this->assertSame(1, CrmTender::query()->count());
        $this->assertSame(0, CrmTask::query()->count());
        $this->assertSame(0, CrmActivity::query()->where('subject_id', $otherLead->id)->count());
        $this->assertSame('enquiry', $tender->fresh()->stage);
    }

    public function test_lists_show_only_the_signed_in_companys_records(): void
    {
        $other = $this->tenant('Other Infra');
        $this->makeTender($other, ['title' => 'Secret bridge']);
        $this->makeLead($other, ['name' => 'Secret lead', 'company_name' => null]);
        $this->makeParty($other, ['name' => 'Secret party']);
        $this->makePayment($this->makeTender($other, ['title' => 'Secret dam']));

        $user = $this->crmUser();
        $this->makeTender($user->organization, ['title' => 'Our tender']);

        foreach (['crm.tenders.index', 'crm.dashboard', 'crm.leads.index', 'crm.companies.index', 'crm.payments.index', 'crm.reports.win-loss'] as $name) {
            $this->get(route($name))->assertOk()->assertDontSee('Secret');
        }

        $this->get(route('crm.tenders.index'))->assertSee('Our tender');
    }

    public function test_the_reminder_command_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');

        $this->assertStringContainsString('crm:send-reminders', $commands);
    }
}
