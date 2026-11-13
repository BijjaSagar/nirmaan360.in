<?php

namespace Tests\Feature\Procurement;

use App\Models\BoqItem;
use App\Models\CustomRole;
use App\Models\Material;
use App\Models\StockLevel;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use App\Services\Procurement\MaterialCatalogService;
use App\Services\Procurement\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\ReadsApiDocs;
use Tests\TestCase;

/** The reconciliation page for the field app: wastage against each BOQ line's norm, quantities only. */
class ReconciliationApiTest extends TestCase
{
    use BuildsProcurement, ReadsApiDocs, RefreshDatabase;

    private BoqItem $boq;

    private Material $bagged;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        // A rate no quantity could equal, so a leak of it is easy to see.
        $this->boq = $this->makeBoqItem($this->project, '100.000', '7313.19');
        $this->bagged = $this->makeMaterial($this->organization, 'CEM-B', 'Cement in bags', $this->bag);
    }

    private function url(?string $basis = null): string
    {
        return '/api/v1/projects/'.$this->project->id.'/material-reconciliation'.($basis ? '?basis='.$basis : '');
    }

    private function signIn(string $role, ?User $user = null): User
    {
        $user ??= $this->member($this->organization, $role);
        Sanctum::actingAs($user);

        return $user;
    }

    /** 50 cum measured at 8.4 bags/cum and 2% wastage: 420 theoretical, 428.4 allowed. 450 issued. */
    private function workedExample(): void
    {
        $this->draftEntry($this->project, $this->boq, $this->owner);
        app(MaterialCatalogService::class)->saveNorm($this->project, [
            'boq_item_id' => $this->boq->id, 'material_id' => $this->bagged->id, 'qty_per_unit' => '8.4', 'wastage_pct' => '2',
        ]);
        $this->stockIn($this->bagged, '1000');
        $this->issue($this->bagged, '450', ['boq_item_id' => $this->boq->id, 'activity' => null]);
    }

    public function test_a_site_engineer_sees_wastage_against_the_norm_with_its_allowance(): void
    {
        $this->workedExample();
        $this->signIn('engineer');

        $response = $this->getJson($this->url())->assertOk();
        $response->assertJsonPath('data.basis', 'measured')
            ->assertJsonPath('data.bases', ['measured', 'approved', 'dpr'])
            ->assertJsonPath('data.counts.wastage', 1)
            ->assertJsonPath('data.rows.0.boq_item_id', $this->boq->id)
            ->assertJsonPath('data.rows.0.boq_code', $this->boq->code)
            ->assertJsonPath('data.rows.0.material_name', 'Cement in bags')
            ->assertJsonPath('data.rows.0.unit', 'bag')
            ->assertJsonPath('data.rows.0.executed', '50.000')
            ->assertJsonPath('data.rows.0.theoretical', '420.000')
            ->assertJsonPath('data.rows.0.allowed', '428.400')
            ->assertJsonPath('data.rows.0.actual', '450.000')
            ->assertJsonPath('data.rows.0.variance', '30.000')
            ->assertJsonPath('data.rows.0.variance_pct', '7.14')
            ->assertJsonPath('data.rows.0.wastage_pct', '2.00')
            ->assertJsonPath('data.rows.0.status', 'wastage')
            ->assertJsonMissingPath('data.rows.0.rate')
            ->assertDontSee('7313');

        // Nothing is approved yet, so on that basis there is no work to set the issue against.
        $this->getJson($this->url('approved'))->assertOk()
            ->assertJsonPath('data.basis', 'approved')
            ->assertJsonPath('data.rows.0.executed', '0.000')
            ->assertJsonPath('data.rows.0.status', 'no_work');

        $this->getJson($this->url('nonsense'))->assertOk()->assertJsonPath('data.basis', 'measured');
    }

    public function test_the_rows_are_the_reconciliation_services_own_with_nothing_worked_out_again(): void
    {
        // The route may not calculate wastage itself: every figure and every status is ReconciliationService's,
        // so whatever that service is built on (one shared allowance calculation) is what the phone shows.
        $this->workedExample();
        $other = $this->makeBoqItem($this->project, '10.000', '7313.19');
        $this->issue($this->bagged, '5', ['boq_item_id' => $other->id, 'activity' => null]);
        $this->signIn('engineer');

        foreach (array_keys(ReconciliationService::BASES) as $basis) {
            $rows = app(ReconciliationService::class)->consumption($this->project, $basis);
            $data = $this->getJson($this->url($basis))->assertOk()->json('data');

            $this->assertSame($rows, $data['rows']);
            $this->assertEquals(array_count_values(array_column($rows, 'status')), array_filter($data['counts']));
        }
    }

    public function test_the_phone_and_the_web_page_draw_the_wastage_line_in_the_same_place(): void
    {
        // A second line used within its allowance: 10 cum at 8.4 bags is 84 theoretical, 85.68 allowed. 85 issued
        // is over the norm but inside the 2% (WastageAllowance), so it is "ok" on the web page and on the phone.
        $this->workedExample();
        $within = $this->makeBoqItem($this->project, '10.000', '7313.19');
        $this->draftEntry($this->project, $within, $this->owner, ['breadth' => '1']);
        app(MaterialCatalogService::class)->saveNorm($this->project, [
            'boq_item_id' => $within->id, 'material_id' => $this->bagged->id, 'qty_per_unit' => '8.4', 'wastage_pct' => '2',
        ]);
        $this->issue($this->bagged, '85', ['boq_item_id' => $within->id, 'activity' => null]);
        $engineer = $this->signIn('engineer');
        $this->withoutVite();

        foreach (array_keys(ReconciliationService::BASES) as $basis) {
            $web = $this->actingAs($engineer)->get(route('procurement.reconciliation.index', [$this->project, 'basis' => $basis]))->assertOk();
            $phone = $this->getJson($this->url($basis))->assertOk()->json('data');

            $this->assertSame($web->viewData('rows'), $phone['rows'], "basis {$basis}");
            $this->assertCount(count($web->viewData('wastage')), array_filter($phone['rows'], fn (array $row) => $row['status'] === 'wastage'));
        }

        $row = collect($this->getJson($this->url())->json('data.rows'))->firstWhere('boq_item_id', $within->id);
        $this->assertSame(['84.000', '85.680', '85.000', 'ok'], [$row['theoretical'], $row['allowed'], $row['actual'], $row['status']]);
    }

    public function test_issues_with_no_boq_line_lines_with_no_norm_and_stock_below_reorder_are_listed(): void
    {
        $this->stockIn($this->cement, '500');
        $this->issue($this->cement, '40', ['activity' => 'Site office plinth']);
        $other = $this->makeBoqItem($this->project, '10.000', '7313.19');
        $this->issue($this->cement, '25', ['boq_item_id' => $other->id, 'activity' => null]);
        StockLevel::updateOrCreate(['store_id' => $this->store->id, 'material_id' => $this->cement->id], ['organization_id' => $this->organization->id, 'reorder_level' => '1000']);

        $this->signIn('store');
        $data = $this->getJson($this->url())->assertOk()->json('data');

        $this->assertSame(1, $data['counts']['no_norm']);
        $this->assertSame('no_norm', $data['rows'][0]['status']);
        $this->assertSame('25.000', $data['rows'][0]['actual']);
        $this->assertNull($data['rows'][0]['allowed']);

        $this->assertSame([['material_id' => $this->cement->id, 'material_name' => 'OPC 53 cement', 'unit' => 'kg', 'activity' => 'Site office plinth', 'actual' => '40.000']], $data['unallocated']);

        $this->assertCount(1, $data['below_reorder']);
        $this->assertSame($this->store->id, $data['below_reorder'][0]['store_id']);
        $this->assertSame('435.000', $data['below_reorder'][0]['balance']);
        $this->assertSame('565.000', $data['below_reorder'][0]['shortfall']);
    }

    public function test_every_role_with_procurement_view_reads_it_and_no_one_else(): void
    {
        $this->workedExample();

        foreach (['pm', 'qs', 'engineer', 'store', 'accounts', 'viewer'] as $role) {
            $this->signIn($role);
            $this->getJson($this->url())->assertOk()->assertDontSee('7313');
        }

        $role = CustomRole::create(['organization_id' => $this->organization->id, 'name' => 'Gate keeper', 'permissions' => ['material_control.view'], 'created_by' => $this->owner->id]);
        $gate = $this->member($this->organization, 'viewer');
        $gate->forceFill(['custom_role_id' => $role->id])->save();
        $this->signIn('viewer', $gate->refresh());
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_it_stays_inside_the_company_the_project_limit_and_the_plan(): void
    {
        $this->workedExample();

        $this->signIn('owner', $this->member($this->tenant('Other Co'), 'owner'));
        $this->getJson($this->url())->assertNotFound();

        $other = $this->makeProject($this->organization, ['name' => 'Other site', 'code' => 'O'.Str::upper(Str::random(3))]);
        $limited = $this->member($this->organization, 'engineer');
        app(TeamAccessService::class)->applyProjects($limited, true, [$other->id]);
        $this->signIn('engineer', $limited->refresh());
        $this->getJson($this->url())->assertNotFound();
        $this->getJson('/api/v1/projects/'.$other->id.'/material-reconciliation')->assertOk()->assertJsonPath('data.rows', []);

        $this->organization->update(['plan' => 'free']);
        $this->signIn('pm');
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_the_api_doc_names_every_key_the_reply_sends(): void
    {
        $this->workedExample();
        $this->stockIn($this->cement, '500');
        $this->issue($this->cement, '40', ['activity' => 'Site office plinth']);
        StockLevel::updateOrCreate(['store_id' => $this->store->id, 'material_id' => $this->cement->id], ['organization_id' => $this->organization->id, 'reorder_level' => '1000']);
        $this->signIn('engineer');

        $data = $this->getJson($this->url())->assertOk()->json('data');
        $this->assertNotEmpty($data['rows']);
        $this->assertNotEmpty($data['unallocated']);
        $this->assertNotEmpty($data['below_reorder']);

        $this->assertDocumentsKeys($this->apiDocSection('Material loop from site'), $data, 'data');
    }
}
