<?php

namespace Tests\Feature\Crm;

use App\Models\CrmActivity;
use App\Models\CrmCompanyProfile;
use App\Models\CrmCustomField;
use App\Models\CrmLead;
use App\Models\CrmTender;
use App\Models\Party;
use App\Services\Crm\CustomFieldService;
use App\Services\Crm\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\BuildsTenant;
use Tests\TestCase;

class LeadsAndCompaniesTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_lead_is_saved_with_tags_value_and_owner(): void
    {
        $user = $this->crmUser();

        $this->post('/crm/leads', [
            'name' => 'Suresh Patil', 'company_name' => 'Patil Housing', 'phone' => '98220 12345', 'email' => 'suresh@example.test',
            'source' => 'meta_ads', 'expected_value' => '2500000.50', 'tags' => 'Housing, Pune,housing', 'location' => 'Pune',
        ])->assertSessionHasNoErrors();

        $lead = CrmLead::firstOrFail();
        $this->assertSame('new', $lead->status);
        $this->assertSame('meta_ads', $lead->source);
        $this->assertSame('2500000.50', $lead->expected_value);
        $this->assertSame(['housing', 'pune'], $lead->tags);
        $this->assertSame($user->id, $lead->owner_id);
        $this->assertSame($user->organization_id, $lead->organization_id);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $lead->getMorphClass(), 'auditable_id' => $lead->id, 'action' => 'created']);
    }

    public function test_posting_a_lead_twice_with_the_same_id_saves_it_once(): void
    {
        $this->crmUser();
        $payload = ['name' => 'Suresh', 'source' => 'website', 'client_uuid' => (string) Str::uuid()];

        $this->post('/crm/leads', $payload);
        $this->post('/crm/leads', $payload);

        $this->assertSame(1, CrmLead::count());
    }

    public function test_a_lead_needs_a_name_and_a_known_source_and_a_valid_email(): void
    {
        $this->crmUser();

        $this->post('/crm/leads', ['name' => '', 'source' => 'other'])->assertSessionHasErrors('name');
        $this->post('/crm/leads', ['name' => 'x', 'source' => 'carrier pigeon'])->assertSessionHasErrors('source');
        $this->post('/crm/leads', ['name' => 'x', 'source' => 'other', 'email' => 'nope'])->assertSessionHasErrors('email');
        $this->assertSame(0, CrmLead::count());
    }

    public function test_a_lead_moves_through_its_statuses_and_a_lost_lead_keeps_the_reason(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization);

        $this->post(route('crm.leads.status', $lead), ['status' => 'contacted']);
        $this->post(route('crm.leads.status', $lead), ['status' => 'lost', 'lost_note' => 'Went with a local firm']);

        $lead->refresh();
        $this->assertSame('lost', $lead->status);
        $this->assertSame('Went with a local firm', $lead->lost_note);
        $this->assertSame(2, CrmActivity::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)->where('kind', 'stage')->count());

        $this->post(route('crm.leads.status', $lead), ['status' => 'qualified']);
        $this->assertNull($lead->fresh()->lost_note);
        $this->post(route('crm.leads.status', $lead), ['status' => 'converted'])->assertSessionHasErrors('status');
    }

    public function test_converting_a_lead_makes_a_company_in_the_contacts_list(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization, ['tags' => ['housing'], 'location' => 'Pune']);

        $this->post(route('crm.leads.convert', $lead), ['type' => 'client'])->assertRedirect();

        $party = Party::firstOrFail();
        $this->assertSame('Kulkarni Developers', $party->name);
        $this->assertSame('Ramesh Kulkarni', $party->contact_person);
        $this->assertSame('9876543210', $party->phone);
        $this->assertSame('ramesh@example.test', $party->email);
        $this->assertSame('client', $party->type);
        $this->assertSame($user->organization_id, $party->organization_id);
        $this->assertSame(['housing'], CrmCompanyProfile::where('party_id', $party->id)->firstOrFail()->tags);

        $lead->refresh();
        $this->assertSame('converted', $lead->status);
        $this->assertSame($party->id, $lead->party_id);
        $this->assertNotNull($lead->converted_at);
        $this->get(route('parties.index'))->assertOk()->assertSee('Kulkarni Developers');
    }

    public function test_converting_a_lead_twice_does_not_make_a_second_company(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization);

        $this->post(route('crm.leads.convert', $lead), ['type' => 'client']);
        $this->post(route('crm.leads.convert', $lead), ['type' => 'vendor']);

        $this->assertSame(1, Party::count());
        $this->assertSame('client', Party::firstOrFail()->type);
    }

    public function test_a_lead_can_be_linked_to_a_company_that_already_exists(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization, ['tags' => ['hot']]);
        $existing = $this->makeParty($user->organization, ['name' => 'Kulkarni Group']);

        $this->post(route('crm.leads.convert', $lead), ['type' => 'client', 'party_id' => $existing->id]);

        $this->assertSame(1, Party::count());
        $this->assertSame($existing->id, $lead->fresh()->party_id);
        $this->assertSame(['hot'], CrmCompanyProfile::where('party_id', $existing->id)->firstOrFail()->tags);
    }

    public function test_a_tender_can_start_from_a_lead(): void
    {
        $user = $this->crmUser();
        $party = $this->makeParty($user->organization);
        $lead = $this->makeLead($user->organization, ['party_id' => $party->id, 'status' => 'converted']);

        $this->get(route('crm.tenders.create', ['lead' => $lead->id]))->assertOk()->assertSee('Kulkarni Developers');
        $this->post('/crm/tenders', ['title' => 'Housing phase 1', 'authority' => 'Kulkarni Developers', 'lead_id' => $lead->id]);

        $tender = CrmTender::firstOrFail();
        $this->assertSame($lead->id, $tender->lead_id);
        $this->assertSame($party->id, $tender->party_id);
    }

    public function test_leads_filter_by_status_source_tag_and_search(): void
    {
        $user = $this->crmUser();
        $this->makeLead($user->organization, ['name' => 'Alpha', 'company_name' => null, 'source' => 'website', 'tags' => ['hot']]);
        $this->makeLead($user->organization, ['name' => 'Bravo', 'company_name' => null, 'source' => 'referral', 'status' => 'qualified', 'phone' => '9000011111']);

        $this->get(route('crm.leads.index', ['source' => 'website']))->assertSee('Alpha')->assertDontSee('Bravo');
        $this->get(route('crm.leads.index', ['status' => 'qualified']))->assertSee('Bravo')->assertDontSee('Alpha');
        $this->get(route('crm.leads.index', ['tag' => 'HOT']))->assertSee('Alpha')->assertDontSee('Bravo');
        $this->get(route('crm.leads.index', ['q' => '90000']))->assertSee('Bravo')->assertDontSee('Alpha');
        $this->get(route('crm.leads.index', ['q' => '100%']))->assertDontSee('Alpha')->assertDontSee('Bravo');
    }

    public function test_a_company_keeps_its_contact_details_in_the_shared_contacts_and_its_crm_extras_beside_them(): void
    {
        $user = $this->crmUser();

        $this->post('/crm/companies', [
            'type' => 'consultant', 'name' => 'Sahyadri Consultants', 'contact_person' => 'Mrs. Joshi', 'phone' => '9811122233',
            'gstin' => '27ABCDE1234F1Z5', 'tags' => 'Structural, PMC', 'owner_id' => $user->id,
        ])->assertSessionHasNoErrors();

        $party = Party::firstOrFail();
        $this->assertSame('consultant', $party->type);
        $profile = CrmCompanyProfile::where('party_id', $party->id)->firstOrFail();
        $this->assertSame(['structural', 'pmc'], $profile->tags);
        $this->assertSame($user->id, $profile->owner_id);

        $this->patch(route('crm.companies.update', $party), ['type' => 'consultant', 'name' => 'Sahyadri Consultants LLP', 'tags' => 'PMC'])->assertSessionHasNoErrors();
        $this->assertSame('Sahyadri Consultants LLP', $party->fresh()->name);
        $this->assertSame(['pmc'], $profile->fresh()->tags);
        $this->assertSame(1, CrmCompanyProfile::count());
        $this->get(route('crm.companies.show', $party))->assertOk()->assertSee('Sahyadri Consultants LLP');
    }

    public function test_companies_filter_by_type_tag_and_search_across_all_party_types(): void
    {
        $user = $this->crmUser();
        $client = $this->makeParty($user->organization, ['name' => 'Zilla Parishad']);
        $this->makeParty($user->organization, ['name' => 'Steel Traders', 'type' => 'vendor', 'contact_person' => null]);
        $this->post(route('crm.companies.update', $client), ['_method' => 'PATCH', 'type' => 'client', 'name' => 'Zilla Parishad', 'tags' => 'govt']);

        $this->get(route('crm.companies.index', ['type' => 'vendor']))->assertSee('Steel Traders')->assertDontSee('Zilla Parishad');
        $this->get(route('crm.companies.index', ['tag' => 'govt']))->assertSee('Zilla Parishad')->assertDontSee('Steel Traders');
        $this->get(route('crm.companies.index', ['q' => 'steel']))->assertSee('Steel Traders')->assertDontSee('Zilla Parishad');
    }

    public function test_a_company_needs_a_known_type_and_a_valid_gstin(): void
    {
        $this->crmUser();

        $this->post('/crm/companies', ['type' => 'alien', 'name' => 'x'])->assertSessionHasErrors('type');
        $this->post('/crm/companies', ['type' => 'client', 'name' => 'x', 'gstin' => 'SHORT'])->assertSessionHasErrors('gstin');
        $this->assertSame(0, Party::count());
    }

    public function test_tags_are_cleaned_and_limited(): void
    {
        $this->assertSame(['a', 'b c'], TagService::parse("A, b   C ,a,\n"));
        $this->assertSame([], TagService::parse(null));
        $this->assertSame([str_repeat('x', 30)], TagService::parse(str_repeat('X', 50)));
        $this->assertCount(15, TagService::parse(implode(',', range(1, 40))));
        $this->assertSame(['x', 'y'], TagService::parse(['X', ' y ', 'x']));
    }

    public function test_tag_suggestions_come_from_the_whole_company_and_no_one_elses(): void
    {
        $user = $this->crmUser();
        $other = $this->tenant('Other Infra');
        $this->makeLead($user->organization, ['tags' => ['hot', 'pune']]);
        $this->makeTender($user->organization, ['tags' => ['nhai', 'hot']]);
        $this->makeLead($other, ['tags' => ['secret']]);

        $this->assertSame(['hot', 'nhai', 'pune'], app(TagService::class)->suggestions($user->organization_id));
    }

    public function test_custom_fields_are_defined_filled_and_checked(): void
    {
        $user = $this->crmUser();

        $this->post(route('crm.fields.store'), ['entity' => 'lead', 'label' => 'Site area (sq ft)', 'type' => 'number'])->assertSessionHasNoErrors();
        $this->post(route('crm.fields.store'), ['entity' => 'lead', 'label' => 'Budget approved', 'type' => 'select', 'options' => 'Yes, No , Pending', 'required' => '1'])->assertSessionHasNoErrors();
        $this->post(route('crm.fields.store'), ['entity' => 'lead', 'label' => 'Visit date', 'type' => 'date'])->assertSessionHasNoErrors();

        $fields = CrmCustomField::orderBy('id')->get();
        $this->assertSame(['site_area_sq_ft', 'budget_approved', 'visit_date'], $fields->pluck('key')->all());
        $this->assertSame(['Yes', 'No', 'Pending'], $fields[1]->options);

        // The required choice is missing, and a wrong choice, number and date are refused.
        $this->post('/crm/leads', ['name' => 'A', 'source' => 'other'])->assertSessionHasErrors('custom.budget_approved');
        $this->post('/crm/leads', ['name' => 'A', 'source' => 'other', 'custom' => ['budget_approved' => 'Maybe']])->assertSessionHasErrors('custom.budget_approved');
        $this->post('/crm/leads', ['name' => 'A', 'source' => 'other', 'custom' => ['budget_approved' => 'Yes', 'site_area_sq_ft' => 'big']])->assertSessionHasErrors('custom.site_area_sq_ft');
        $this->post('/crm/leads', ['name' => 'A', 'source' => 'other', 'custom' => ['budget_approved' => 'Yes', 'visit_date' => 'tomorrow-ish']])->assertSessionHasErrors('custom.visit_date');
        $this->assertSame(0, CrmLead::count());

        $this->post('/crm/leads', ['name' => 'A', 'source' => 'other', 'custom' => ['budget_approved' => 'Yes', 'site_area_sq_ft' => '12500.50', 'visit_date' => '2026-12-15', 'unknown' => 'dropped']])->assertSessionHasNoErrors();

        $lead = CrmLead::firstOrFail();
        $this->assertEquals(['budget_approved' => 'Yes', 'site_area_sq_ft' => '12500.5', 'visit_date' => '2026-12-15'], $lead->custom_fields);
        $this->get(route('crm.leads.show', $lead))->assertSee('Site area (sq ft)')->assertSee('12500.5')->assertSee('15 Dec 2026');
    }

    public function test_custom_fields_work_on_companies_and_tenders_and_a_switched_off_field_keeps_its_value(): void
    {
        $user = $this->crmUser();
        $service = app(CustomFieldService::class);
        $tenderField = $service->create($user->organization_id, ['entity' => 'tender', 'label' => 'Funding', 'type' => 'text']);
        $service->create($user->organization_id, ['entity' => 'company', 'label' => 'Rating', 'type' => 'select', 'options' => 'A, B']);

        $this->post('/crm/tenders', ['title' => 'T', 'authority' => 'A', 'custom' => ['funding' => 'World Bank']])->assertSessionHasNoErrors();
        $this->post('/crm/companies', ['type' => 'client', 'name' => 'Co', 'custom' => ['rating' => 'A']])->assertSessionHasNoErrors();

        $tender = CrmTender::firstOrFail();
        $this->assertSame(['funding' => 'World Bank'], $tender->custom_fields);
        $this->assertSame(['rating' => 'A'], CrmCompanyProfile::firstOrFail()->custom_fields);

        $this->patch(route('crm.fields.update', $tenderField), ['label' => 'Funding', 'active' => '0']);
        $this->patch(route('crm.tenders.update', $tender), ['title' => 'T2', 'authority' => 'A', 'custom' => ['funding' => 'ignored']]);

        $this->assertSame(['funding' => 'World Bank'], $tender->fresh()->custom_fields);
        $this->assertSame('T2', $tender->fresh()->title);
    }

    public function test_a_choice_field_needs_choices_and_each_company_can_have_a_limited_number_of_fields(): void
    {
        $this->crmUser();

        $this->post(route('crm.fields.store'), ['entity' => 'lead', 'label' => 'Grade', 'type' => 'select', 'options' => ' , '])->assertSessionHasErrors('options');
        $this->post(route('crm.fields.store'), ['entity' => 'lead', 'label' => 'Grade', 'type' => 'colour'])->assertSessionHasErrors('type');
        $this->post(route('crm.fields.store'), ['entity' => 'invoice', 'label' => 'Grade', 'type' => 'text'])->assertSessionHasErrors('entity');

        for ($i = 1; $i <= 20; $i++) {
            $this->post(route('crm.fields.store'), ['entity' => 'lead', 'label' => 'Field '.$i, 'type' => 'text']);
        }
        $this->post(route('crm.fields.store'), ['entity' => 'lead', 'label' => 'One too many', 'type' => 'text'])->assertSessionHasErrors('label');
        $this->assertSame(20, CrmCustomField::where('entity', 'lead')->count());
    }

    public function test_two_fields_with_the_same_label_get_different_keys(): void
    {
        $user = $this->crmUser();
        $service = app(CustomFieldService::class);

        $one = $service->create($user->organization_id, ['entity' => 'lead', 'label' => 'Area', 'type' => 'text']);
        $two = $service->create($user->organization_id, ['entity' => 'lead', 'label' => 'Area', 'type' => 'text']);
        $marathi = $service->create($user->organization_id, ['entity' => 'lead', 'label' => 'क्षेत्रफळ', 'type' => 'text']);

        $this->assertSame('area', $one->key);
        $this->assertSame('area_2', $two->key);
        $this->assertNotSame('', $marathi->key);
    }

    public function test_notes_on_the_timeline_are_saved_for_a_lead_a_tender_and_a_company(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization);
        $tender = $this->makeTender($user->organization);
        $party = $this->makeParty($user->organization);

        foreach ([['lead', $lead], ['tender', $tender], ['company', $party]] as [$type, $record]) {
            $this->post('/crm/activities', ['subject_type' => $type, 'subject_id' => $record->id, 'kind' => 'call', 'body' => 'Spoke about '.$type])->assertSessionHasNoErrors();
        }

        $this->assertSame(3, CrmActivity::where('kind', 'call')->count());
        $this->get(route('crm.leads.show', $lead))->assertSee('Spoke about lead');
        $this->post('/crm/activities', ['subject_type' => 'lead', 'subject_id' => $lead->id, 'kind' => 'stage', 'body' => 'forged'])->assertSessionHasErrors('kind');
    }
}
