<?php

namespace Tests\Feature\SiteControl;

use App\Http\Presenters\ApprovalPresenter;
use App\Http\Presenters\BoqItemPresenter;
use App\Http\Presenters\UserPresenter;
use App\Models\Approval;
use App\Models\Asset;
use App\Models\BoqItem;
use App\Models\CustomRole;
use App\Models\FuelIssue;
use App\Models\Ncr;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\RaBill;
use App\Models\User;
use App\Models\Worker;
use App\Services\Access\CustomRoleService;
use App\Services\Access\PermissionCatalog;
use App\Services\ApprovalService;
use App\Services\Assets\PlantLogService;
use App\Services\Reports\BoqExcelService;
use App\Services\Reports\MeasurementSheetService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** The rules behind the route walk, one at a time: who sees what, and what a site hand cannot set. */
class MoneyRulesTest extends TestCase
{
    use BuildsMoneyWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildMoneyWorld();
    }

    public function test_the_boq_presenter_keeps_the_rate_key_and_nulls_it_for_site_staff(): void
    {
        $item = BoqItem::withLedger()->with('section')->findOrFail($this->rows[BoqItem::class]->id);

        Auth::setUser($this->users['engineer']);
        $row = BoqItemPresenter::present($item);
        $this->assertArrayHasKey('rate', $row);
        $this->assertNull($row['rate']);
        $this->assertSame('100.000', $row['qty']);

        Auth::setUser($this->users['accounts']);
        $this->assertSame(self::MONEY['boq_rate'], BoqItemPresenter::present($item)['rate']);
    }

    public function test_the_approval_presenter_and_titles_drop_amounts_for_site_staff(): void
    {
        $viewer = $this->users['viewer'];
        $owner = $this->users['owner'];
        $approvals = Approval::where('doc_type', 'ra_bill')->get();

        Auth::setUser($viewer);
        $forViewer = ApprovalPresenter::collection($approvals, $viewer)[0];
        $this->assertStringNotContainsString('₹', $forViewer['title']);
        $this->assertSame(['gross_amount', 'deduction_total', 'gst_amount', 'net_payable'], array_values(array_filter(array_keys($forViewer['document']), fn ($key) => $forViewer['document'][$key] === null && str_contains($key, 'amount') || $key === 'net_payable' || $key === 'deduction_total')));
        $this->assertNull($forViewer['document']['net_payable']);

        Auth::setUser($owner);
        $forOwner = ApprovalPresenter::collection($approvals, $owner)[0];
        $this->assertStringContainsString(inr(self::MONEY['bill_net']), $forOwner['title']);
        $this->assertSame(self::MONEY['bill_net'], $forOwner['document']['net_payable']);

        $bill = $this->rows[RaBill::class];
        $this->assertSame('RA Bill '.$bill->number, $bill->approvalTitle());
        $this->assertSame('RA Bill '.$bill->number, $bill->approvalTitleFor($viewer));
    }

    public function test_approval_notifications_carry_the_amount_only_to_people_who_may_see_money(): void
    {
        // Level one of an RA bill is the estimator. One estimator holds money.view; a custom role at the same level does not.
        $estimator = $this->users['qs'];
        $siteRole = CustomRole::create(['organization_id' => $this->organization->id, 'name' => 'Checker', 'approval_role' => 'qs', 'permissions' => ['ra_bill.view', 'ra_bill.approve'], 'created_by' => $this->users['owner']->id]);
        $checker = $this->member($this->organization, 'qs');
        $checker->forceFill(['custom_role_id' => $siteRole->id])->save();
        $this->assertFalse($checker->fresh()->can('money.view'));

        $bill = RaBill::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'type' => 'client', 'bill_no' => 2,
            'number' => $this->project->code.'/RA/2', 'period_from' => '2026-12-01', 'period_to' => '2026-12-31', 'status' => 'DRAFT',
            'gross_amount' => '54321000.00', 'net_payable' => '54321000.00',
        ]);
        app(ApprovalService::class)->submit($bill, $this->users['owner']);

        $titles = fn (User $user) => $user->fresh()->notifications()->get()->map(fn ($notification) => $notification->data['title'])->filter(fn ($title) => str_contains($title, '/RA/2'))->values()->all();

        $this->assertNotEmpty($titles($estimator));
        $this->assertStringContainsString(inr('54321000.00'), $titles($estimator)[0]);
        $this->assertNotEmpty($titles($checker));
        $this->assertStringNotContainsString('₹', $titles($checker)[0]);
        $this->assertStringNotContainsString('54321000', str_replace(',', '', $titles($checker)[0]));
    }

    public function test_site_staff_see_only_the_petty_cash_wallet_they_hold(): void
    {
        $engineer = $this->users['engineer'];

        $this->actingAs($engineer)->get(route('petty.index', $this->project))->assertOk()
            ->assertSee('Engineer imprest')->assertDontSee('Owner cash')
            ->assertSee(inr(self::MONEY['own_wallet']))->assertDontSee(inr(self::MONEY['other_wallet']));

        Sanctum::actingAs($engineer);
        $wallets = $this->getJson('/api/v1/projects/'.$this->project->id.'/petty-cash')->assertOk()->json('data');
        $this->assertCount(1, $wallets);
        $this->assertSame('Engineer imprest', $wallets[0]['name']);
        $this->assertSame(self::MONEY['own_wallet'], $wallets[0]['balance']);

        $pull = $this->getJson('/api/v1/sync/pull')->assertOk()->json();
        $this->assertSame(['Engineer imprest'], array_column($pull['wallets'], 'name'));
        $this->assertCount(1, $pull['petty_cash_entries']);
        $this->assertSame(self::MONEY['own_wallet'], $pull['petty_cash_entries'][0]['amount']);
        $this->assertNull($pull['boq_items'][0]['rate']);
        $this->assertArrayHasKey('rate', $pull['boq_items'][0]);
        $this->assertFalse($pull['user']['can']['money.view']);

        // A wallet a site hand opens without naming a holder is theirs, so it does not vanish from their screen.
        $created = $this->postJson('/api/v1/projects/'.$this->project->id.'/wallets', ['name' => 'Cash in hand'])->assertCreated()->json('data');
        $this->assertSame($engineer->id, PettyCashWallet::findOrFail($created['id'])->user_id);

        // Another person's receipt is refused; their own is simply not uploaded yet.
        $this->getJson('/api/v1/petty-entries/'.$this->rows[PettyCashEntry::class]->id.'/bill')->assertForbidden();
        $this->actingAs($engineer)->get(route('petty.bill', $this->rows[PettyCashEntry::class]))->assertForbidden();

        // The owner still sees everything: both fixture wallets and the one just opened.
        Sanctum::actingAs($this->users['owner']);
        $this->assertCount(3, $this->getJson('/api/v1/projects/'.$this->project->id.'/petty-cash')->json('data'));
    }

    public function test_site_staff_cannot_set_wages_rates_or_rework_cost(): void
    {
        $engineer = $this->users['engineer'];
        $this->actingAs($engineer);

        $this->post('/workers', ['name' => 'Suresh Kale', 'trade' => 'Helper', 'type' => 'own', 'daily_wage' => '999999.00'])->assertRedirect();
        $this->assertSame('0.00', Worker::where('name', 'Suresh Kale')->firstOrFail()->daily_wage);

        $worker = $this->rows[Worker::class];
        $this->patch('/workers/'.$worker->id, ['name' => $worker->name, 'trade' => 'Mason', 'type' => 'own', 'daily_wage' => '1.00', 'is_active' => 1])->assertRedirect();
        $this->assertSame(self::MONEY['wage'], $worker->fresh()->daily_wage);

        $ncr = $this->rows[Ncr::class];
        $this->patch(route('ncrs.update', $ncr), ['rework_cost' => '1.00', 'root_cause' => 'Poor vibration'])->assertRedirect();
        $this->assertSame(self::MONEY['rework'], $ncr->fresh()->rework_cost);
        $this->assertSame('Poor vibration', $ncr->fresh()->root_cause);

        Sanctum::actingAs($engineer);
        $this->postJson('/api/v1/workers', ['name' => 'Via app', 'trade' => 'Helper', 'type' => 'own', 'daily_wage' => '777.00'])->assertCreated();
        $this->assertSame('0.00', Worker::where('name', 'Via app')->firstOrFail()->daily_wage);

        // The owner sets wages as before.
        $this->actingAs($this->users['owner'])->post('/workers', ['name' => 'Priced worker', 'trade' => 'Helper', 'type' => 'own', 'daily_wage' => '850.00'])->assertRedirect();
        $this->assertSame('850.00', Worker::where('name', 'Priced worker')->firstOrFail()->daily_wage);
    }

    public function test_fuel_issued_by_site_staff_takes_the_last_rate_on_the_asset(): void
    {
        $asset = $this->rows[Asset::class];
        $engineer = $this->users['engineer'];
        $this->actingAs($engineer);

        $issue = app(PlantLogService::class)->issueFuel(['asset_id' => $asset->id, 'issue_date' => '2026-11-09', 'litres' => '20.000', 'rate' => '1.00'], $engineer);

        $this->assertSame(self::MONEY['fuel_rate'], $issue->rate, 'The rate a site hand posts is ignored in favour of the asset\'s last rate.');
        $this->assertSame(bcmul('20', self::MONEY['fuel_rate'], 2), $issue->amount);
        $this->assertSame(['nullable', 'numeric', 'min:0', 'max:100000'], app(PlantLogService::class)->fuelRules($engineer)['rate']);
        $this->assertSame(['required', 'numeric', 'min:0', 'max:100000'], app(PlantLogService::class)->fuelRules($this->users['pm'])['rate']);

        FuelIssue::query()->delete();
        $first = app(PlantLogService::class)->issueFuel(['asset_id' => $asset->id, 'issue_date' => '2026-11-09', 'litres' => '5.000'], $engineer);
        $this->assertSame('0.00', $first->rate, 'With no earlier issue there is no rate to carry.');
    }

    public function test_boq_files_and_the_measurement_sheet_leave_money_out_for_site_staff(): void
    {
        $engineer = $this->users['engineer'];
        $owner = $this->users['owner'];

        $csv = $this->actingAs($engineer)->get(route('boq.export', $this->project))->assertOk()->streamedContent();
        $this->assertStringStartsWith('section,code,description,unit,qty', $csv);
        $this->assertStringNotContainsString('rate', strtok($csv, "\n"));
        $this->assertStringNotContainsString($this->needle('boq_rate'), $this->digits($csv));
        $this->assertStringContainsString($this->needle('boq_rate'), $this->digits($this->actingAs($owner)->get(route('boq.export', $this->project))->streamedContent()));

        $path = tempnam(sys_get_temp_dir(), 'boq');
        file_put_contents($path, app(BoqExcelService::class)->export($this->project, withMoney: false));
        $header = IOFactory::load($path)->getActiveSheet()->toArray()[0];
        unlink($path);
        // Category and details are not money, so they stay; the rate and amount columns go.
        $this->assertSame(['section', 'code', 'description', 'unit', 'qty', 'category', 'details'], array_values(array_filter($header, fn ($cell) => $cell !== null)));

        $sheet = app(MeasurementSheetService::class)->build($this->project, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'), withMoney: false);
        $this->assertFalse($sheet['with_money']);
        $this->assertNull($sheet['total_amount']);
        $this->assertNotEmpty($sheet['items']);
        $this->assertNull($sheet['items'][0]['rate']);
        $this->assertNull($sheet['items'][0]['amount']);
        $this->assertSame('10.000', $sheet['items'][0]['period_qty']);

        $priced = app(MeasurementSheetService::class)->build($this->project, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
        $this->assertSame(self::MONEY['boq_rate'], $priced['items'][0]['rate']);
    }

    public function test_a_custom_role_without_money_cannot_price_a_boq_line(): void
    {
        $role = CustomRole::create(['organization_id' => $this->organization->id, 'name' => 'Site quantity clerk', 'permissions' => ['project.view', 'boq.view', 'boq.manage'], 'created_by' => $this->users['owner']->id]);
        $clerk = $this->member($this->organization, 'engineer');
        $clerk->forceFill(['custom_role_id' => $role->id])->save();
        $clerk = $clerk->fresh();

        $this->actingAs($clerk);
        $this->post(route('boq.store', $this->project), ['code' => '9.9', 'description' => 'Plinth protection', 'unit' => 'sqm', 'qty' => '40', 'rate' => '5000'])->assertRedirect();
        $this->assertSame('0.00', BoqItem::where('code', '9.9')->firstOrFail()->rate, 'A line added without the money permission waits to be priced.');

        $item = $this->rows[BoqItem::class];
        $this->patch(route('boq.update', $item), ['code' => $item->code, 'description' => $item->description, 'unit' => $item->unit, 'qty' => '100', 'rate' => '1'])->assertRedirect();
        $this->assertSame(self::MONEY['boq_rate'], $item->fresh()->rate);

        $this->get(route('boq.index', $this->project))->assertOk()->assertDontSee($this->needle('boq_rate'))->assertDontSee('Rate (₹)');
    }

    public function test_money_permissions_are_in_the_catalogue_and_stay_within_the_granters_own_reach(): void
    {
        $catalog = app(PermissionCatalog::class);
        $this->assertContains('money.view', $catalog->all());
        $this->assertContains('money.overview', $catalog->all());
        $this->assertSame('Money figures', $catalog->moduleLabel('money'));
        $this->assertSame(['money.overview', 'money.view'], $catalog->grantableBy($this->users['owner'])['money']);
        $this->assertSame(['money.overview', 'money.view'], $catalog->grantableBy($this->users['pm'])['money']);
        $this->assertArrayNotHasKey('money', $catalog->grantableBy($this->users['engineer']));

        // A manager who sees amounts but not totals can hand out the first and not the second.
        $managerRole = CustomRole::create(['organization_id' => $this->organization->id, 'name' => 'Site manager', 'permissions' => ['user.*', 'project.view', 'money.view'], 'created_by' => $this->users['owner']->id]);
        $manager = $this->member($this->organization, 'pm');
        $manager->forceFill(['custom_role_id' => $managerRole->id])->save();
        $manager = $manager->fresh();

        $this->assertSame(['money.view'], $catalog->grantableBy($manager)['money']);
        $this->assertTrue($manager->can('money.view'));
        $this->assertFalse($manager->can('money.overview'));

        $service = app(CustomRoleService::class);
        $this->assertSame(['project.view', 'money.view'], $service->create($manager, ['name' => 'Priced viewer', 'permissions' => ['project.view', 'money.view']])->permissions);

        try {
            $service->create($manager, ['name' => 'Budget viewer', 'permissions' => ['project.view', 'money.overview']]);
            $this->fail('A role with money.overview was created by someone who does not hold it.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('permissions', $exception->errors());
        }

        $this->actingAs($manager)->get(route('team.roles.create'))->assertOk()->assertSee('Money figures')->assertSee('value="money.view"', false)->assertDontSee('value="money.overview"', false);
        $this->actingAs($this->users['owner'])->get(route('team.roles.create'))->assertOk()->assertSee('value="money.overview"', false);
    }

    public function test_the_field_app_is_told_which_money_permissions_the_user_holds(): void
    {
        $this->assertFalse(UserPresenter::present($this->users['store'])['can']['money.view']);
        $this->assertTrue(UserPresenter::present($this->users['accounts'])['can']['money.view']);
        $this->assertFalse(UserPresenter::present($this->users['accounts'])['can']['money.overview']);
        $this->assertTrue(UserPresenter::present($this->users['pm'])['can']['money.overview']);

        $this->assertTrue(Money::visible($this->users['qs']));
        $this->assertFalse(Money::overview($this->users['qs']));
        $this->assertNull(Money::orNull('1.00', $this->users['viewer']));
        $this->assertSame('1.00', Money::orNull('1.00', $this->users['owner']));
    }
}
