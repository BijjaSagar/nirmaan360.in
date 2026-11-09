<?php

namespace Tests\Feature\Crm;

use App\Models\BoqItem;
use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\Project;
use App\Services\BoqService;
use App\Services\Crm\TenderConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;
use Tests\TestCase;

class TenderConversionTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
    }

    private function wonTender(object $organization, array $overrides = []): CrmTender
    {
        return $this->makeTender($organization, [
            'title' => 'Mula river bridge', 'stage' => 'won', 'closed_on' => today(), 'location' => 'Pune', 'state' => 'Maharashtra',
            'bid_value' => '48000000.00', 'awarded_value' => '47500000.25',
            ...$overrides,
        ]);
    }

    private function form(array $overrides = []): array
    {
        return ['name' => 'Mula river bridge', 'contract_value' => '47500000.25', ...$overrides];
    }

    public function test_a_won_tender_becomes_a_project_with_its_client_and_boq(): void
    {
        $user = $this->crmUser();
        $client = $this->makeParty($user->organization);
        $tender = $this->wonTender($user->organization, ['party_id' => $client->id]);
        $this->makeBoqLine($tender, ['section' => 'Earthwork', 'code' => '1.1', 'qty' => '250.500', 'rate' => '185.25', 'sort' => 1]);
        $this->makeBoqLine($tender, ['section' => 'Earthwork', 'code' => '1.2', 'description' => 'Backfilling', 'unit' => 'cum', 'qty' => '80.000', 'rate' => '120.00', 'sort' => 2]);
        $this->makeBoqLine($tender, ['section' => 'Superstructure', 'code' => '2.1', 'description' => 'RCC M25', 'unit' => 'cum', 'qty' => '120.000', 'rate' => '7450.00', 'sort' => 3]);

        $this->post(route('crm.tenders.convert.store', $tender), $this->form(['code' => 'MULA-1', 'start_date' => '2027-01-05']))->assertSessionHasNoErrors();

        $project = Project::where('code', 'MULA-1')->firstOrFail();
        $this->assertSame('Mula river bridge', $project->name);
        $this->assertSame('47500000.25', $project->contract_value);
        $this->assertSame($client->id, $project->client_id);
        $this->assertSame('Pune', $project->location);
        $this->assertSame('Maharashtra', $project->state);
        $this->assertSame('2027-01-05', $project->start_date->toDateString());
        $this->assertSame('active', $project->status);
        $this->assertSame($user->organization_id, $project->organization_id);

        $items = BoqItem::where('project_id', $project->id)->orderBy('sort')->get();
        $this->assertCount(3, $items);
        $this->assertSame(['1.1', '1.2', '2.1'], $items->pluck('code')->all());
        $this->assertSame('250.500', $items[0]->qty);
        $this->assertSame('185.25', $items[0]->rate);
        $this->assertSame('RCC M25', $items[2]->description);
        $this->assertSame(['Earthwork', 'Earthwork', 'Superstructure'], $items->map(fn ($item) => $item->section->name)->all());
        $this->assertSame(2, $project->boqSections()->count());

        $tender->refresh();
        $this->assertSame($project->id, $tender->project_id);
        $this->assertSame($user->id, $tender->converted_by);
        $this->assertNotNull($tender->converted_at);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $project->getMorphClass(), 'auditable_id' => $project->id, 'action' => 'created']);
    }

    public function test_the_documents_travel_to_the_project_and_show_under_its_tender_file(): void
    {
        $user = $this->crmUser();
        $tender = $this->wonTender($user->organization);
        $nit = CrmTenderDocument::create(['organization_id' => $user->organization_id, 'tender_id' => $tender->id, 'title' => 'Tender notice', 'kind' => 'nit', 'path' => 'x/nit.pdf', 'original_name' => 'nit.pdf', 'size' => 2048]);
        $drawing = CrmTenderDocument::create(['organization_id' => $user->organization_id, 'tender_id' => $tender->id, 'title' => 'Bridge drawing', 'kind' => 'drawing', 'path' => 'x/dwg.pdf', 'original_name' => 'dwg.pdf', 'size' => 4096]);

        $this->post(route('crm.tenders.convert.store', $tender), $this->form())->assertSessionHasNoErrors();

        $project = Project::firstOrFail();
        $this->assertSame($project->id, $nit->fresh()->project_id);
        $this->assertSame($project->id, $drawing->fresh()->project_id);
        $this->get(route('crm.project-file', $project))->assertOk()->assertSee('Tender notice')->assertSee('Bridge drawing')->assertSee('Mula river bridge');
        $this->get(route('projects.show', $project))->assertOk()->assertSee('Tender file');
    }

    public function test_converting_again_returns_the_same_project_and_adds_nothing(): void
    {
        $user = $this->crmUser();
        $tender = $this->wonTender($user->organization);
        $this->makeBoqLine($tender);

        $this->post(route('crm.tenders.convert.store', $tender), $this->form());
        $first = Project::firstOrFail();
        $response = $this->post(route('crm.tenders.convert.store', $tender), $this->form(['name' => 'Different name']));

        $response->assertRedirect(route('projects.show', $first));
        $this->assertSame(1, Project::count());
        $this->assertSame(1, BoqItem::count());
        $this->assertSame('Mula river bridge', $first->fresh()->name);
    }

    public function test_the_service_is_safe_to_call_repeatedly(): void
    {
        $user = $this->crmUser();
        $tender = $this->wonTender($user->organization);
        $this->makeBoqLine($tender);
        $service = app(TenderConversionService::class);

        $one = $service->convert($tender, $this->form(), $user);
        $two = $service->convert($tender->fresh(), $this->form(), $user);
        $three = $service->convert($tender, $this->form(), $user);

        $this->assertTrue($one['created']);
        $this->assertSame(1, $one['boq_lines']);
        $this->assertFalse($two['created']);
        $this->assertFalse($three['created']);
        $this->assertSame($one['project']->id, $two['project']->id);
        $this->assertSame(1, Project::count());
        $this->assertSame(1, BoqItem::count());
    }

    public function test_only_a_won_tender_can_be_converted(): void
    {
        $user = $this->crmUser();

        foreach (['enquiry', 'bidding', 'submitted', 'lost', 'no_bid'] as $stage) {
            $tender = $this->makeTender($user->organization, ['stage' => $stage]);
            $this->post(route('crm.tenders.convert.store', $tender), $this->form())->assertSessionHasErrors('tender');
            $this->get(route('crm.tenders.convert', $tender))->assertRedirect(route('crm.tenders.show', $tender));
        }

        $this->assertSame(0, Project::count());
    }

    public function test_the_project_code_must_be_free_and_the_value_a_number(): void
    {
        $user = $this->crmUser();
        $this->makeProject($user->organization, ['code' => 'TAKEN']);
        $tender = $this->wonTender($user->organization);

        $this->post(route('crm.tenders.convert.store', $tender), $this->form(['code' => 'TAKEN']))->assertSessionHasErrors('code');
        $this->post(route('crm.tenders.convert.store', $tender), $this->form(['contract_value' => 'lots']))->assertSessionHasErrors('contract_value');
        $this->post(route('crm.tenders.convert.store', $tender), $this->form(['name' => '']))->assertSessionHasErrors('name');
        $this->post(route('crm.tenders.convert.store', $tender), $this->form(['start_date' => '2027-02-01', 'end_date' => '2027-01-01']))->assertSessionHasErrors('end_date');

        $this->assertNull($tender->fresh()->project_id);
    }

    public function test_a_code_is_made_from_the_name_when_none_is_given(): void
    {
        $user = $this->crmUser();
        $tender = $this->wonTender($user->organization);

        $this->post(route('crm.tenders.convert.store', $tender), $this->form(['code' => '']));

        $this->assertSame('MRB', Project::firstOrFail()->code);
    }

    public function test_a_full_plan_stops_the_conversion_and_changes_nothing(): void
    {
        $user = $this->crmUser('owner', 'contractor');
        for ($i = 0; $i < 5; $i++) {
            $this->makeProject($user->organization);
        }
        $tender = $this->wonTender($user->organization);
        $this->makeBoqLine($tender);

        $this->post(route('crm.tenders.convert.store', $tender), $this->form())->assertSessionHasErrors('plan');

        $this->assertSame(5, Project::count());
        $this->assertSame(0, BoqItem::count());
        $this->assertNull($tender->fresh()->project_id);
    }

    public function test_a_failure_part_way_leaves_no_half_made_project(): void
    {
        $user = $this->crmUser();
        $tender = $this->wonTender($user->organization);
        $this->makeBoqLine($tender, ['code' => '1.1', 'sort' => 1]);
        $this->makeBoqLine($tender, ['code' => '1.2', 'sort' => 2]);
        $this->makeTenderDocument($tender);

        // The second BOQ line fails, after the project and the first line were made.
        $this->app->instance(BoqService::class, new class extends BoqService
        {
            private int $calls = 0;

            public function create(Project $project, array $data): BoqItem
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException('Could not save the line.');
                }

                return parent::create($project, $data);
            }
        });

        try {
            app(TenderConversionService::class)->convert($tender, $this->form(), $user);
            $this->fail('The conversion should have failed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Could not save the line.', $exception->getMessage());
        }

        $this->assertSame(0, Project::withoutGlobalScopes()->count());
        $this->assertSame(0, BoqItem::withoutGlobalScopes()->count());
        $this->assertNull($tender->fresh()->project_id);
        $this->assertNull(CrmTenderDocument::query()->first()->project_id);
    }

    public function test_deleting_the_project_lets_the_tender_be_converted_again_without_mixing_boq_lines(): void
    {
        $user = $this->crmUser();
        $tender = $this->wonTender($user->organization);
        $this->makeBoqLine($tender);
        $service = app(TenderConversionService::class);

        $first = $service->convert($tender, $this->form(['code' => 'ONE']), $user)['project'];
        $first->delete();
        $second = $service->convert($tender->fresh(), $this->form(['code' => 'TWO']), $user);

        $this->assertTrue($second['created']);
        $this->assertNotSame($first->id, $second['project']->id);
        $this->assertSame(1, BoqItem::where('project_id', $second['project']->id)->count());
        $this->assertSame(1, BoqItem::where('project_id', $first->id)->count());
        $this->assertSame($second['project']->id, $tender->fresh()->project_id);
    }

    public function test_a_converted_tender_cannot_be_reopened_deleted_or_have_its_boq_changed(): void
    {
        $user = $this->crmUser();
        $tender = $this->wonTender($user->organization);
        $line = $this->makeBoqLine($tender);
        $this->post(route('crm.tenders.convert.store', $tender), $this->form());

        $this->post(route('crm.tenders.reopen', $tender))->assertSessionHasErrors('stage');
        $this->delete(route('crm.tenders.destroy', $tender))->assertSessionHasErrors('tender');
        $this->post(route('crm.boq.store', $tender), ['code' => '9', 'description' => 'x', 'unit' => 'nos', 'qty' => 1, 'rate' => 1])->assertSessionHasErrors('boq');
        $this->patch(route('crm.boq.update', $line), ['code' => '1.1', 'description' => 'x', 'unit' => 'nos', 'qty' => 5, 'rate' => 1])->assertSessionHasErrors('boq');
        $this->delete(route('crm.boq.destroy', $line))->assertSessionHasErrors('boq');

        $this->assertSame('won', $tender->fresh()->stage);
        $this->assertSame(1, $tender->boqItems()->count());
        $this->get(route('crm.tenders.convert', $tender))->assertRedirect(route('projects.show', $tender->fresh()->project_id));
    }

    public function test_the_confirmation_screen_suggests_the_value_and_warns_about_held_emd(): void
    {
        $user = $this->crmUser();
        $tender = $this->wonTender($user->organization, ['awarded_value' => null, 'bid_value' => null, 'estimated_value' => '1000.00']);
        $this->makeBoqLine($tender, ['qty' => '10.000', 'rate' => '33.33']);
        $this->makePayment($tender, ['amount' => '250000.00']);

        $preview = app(TenderConversionService::class)->preview($tender);

        $this->assertSame('333.30', $preview['contract_value']);
        $this->assertSame('333.30', $preview['boq_total']);
        $this->assertSame(1, $preview['boq_lines']);
        $this->assertSame('250000.00', $preview['emd_held']);
        $this->get(route('crm.tenders.convert', $tender))->assertOk()->assertSee('333.30')->assertSee('2,50,000.00');
    }

    public function test_the_award_is_preferred_for_the_contract_value(): void
    {
        $user = $this->crmUser();
        $tender = $this->wonTender($user->organization);
        $this->makeBoqLine($tender, ['qty' => '10.000', 'rate' => '33.33']);

        $this->assertSame('47500000.25', app(TenderConversionService::class)->preview($tender)['contract_value']);
    }

    public function test_converting_needs_the_right_to_create_projects_as_well(): void
    {
        $organization = $this->tenant();
        $tender = $this->wonTender($organization);
        $this->actingAs($this->member($organization, 'pm'));
        config(['buildos.roles.pm.permissions' => array_values(array_diff(config('buildos.roles.pm.permissions'), ['project.*']))]);

        $this->post(route('crm.tenders.convert.store', $tender), $this->form())->assertForbidden();
        $this->assertSame(0, Project::count());
    }

    public function test_the_service_refuses_a_tender_that_is_not_won(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->expectException(ValidationException::class);
        app(TenderConversionService::class)->convert($tender, $this->form(), $user);
    }

    public function test_the_tender_file_page_belongs_to_the_projects_company_and_lists_nothing_for_plain_projects(): void
    {
        $user = $this->crmUser();
        $project = $this->makeProject($user->organization);

        $this->get(route('crm.project-file', $project))->assertOk()->assertSee('No tender documents');
    }
}
