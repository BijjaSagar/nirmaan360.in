<?php

namespace Tests\Feature\MaterialControl;

use App\Models\BoqItem;
use App\Models\BoqMaterialNorm;
use App\Models\Material;
use App\Notifications\ProcurementAlert;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\Procurement\ReconciliationService;
use App\Services\Procurement\WastageAllowance;
use App\Services\SiteAlerts\Sources\MaterialSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The "over used" flag, the reconciliation page and the wastage alert draw the line in one place:
 * theoretical x (1 + the norm's allowed wastage %). The worked example is 50 cum of RCC at 8.4 bags/cum
 * with 2% allowed: 420 bags theoretical, 428.4 allowed.
 */
class WastageAllowanceTest extends TestCase
{
    use BuildsMaterialControl, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpControl();
        Notification::fake();
    }

    /** 50 cum done on site and in the measurement book, so the check and the reconciliation read the same work. */
    private function slab(Material $cement, string $wastagePct = '2'): BoqItem
    {
        $slab = $this->boqFor($cement, '100', '8.4');
        BoqMaterialNorm::where('boq_item_id', $slab->id)->update(['wastage_pct' => $wastagePct]);
        $this->workDone($slab, '50');
        $this->draftEntry($this->project, $slab, $this->owner);

        return $slab;
    }

    private function cement(): Material
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $this->deliver($cement, '600');

        return $cement;
    }

    private function reconciliationFor(BoqItem $item): array
    {
        return collect(app(ReconciliationService::class)->consumption($this->project))->firstWhere('boq_item_id', $item->id);
    }

    private function overBoqAlerts(): array
    {
        return array_values(array_filter(
            iterator_to_array(app(MaterialSource::class)->findings($this->project, today()), false),
            fn ($finding) => $finding->type === MaterialSource::OVER_BOQ,
        ));
    }

    private function wastageAlertsSent(): int
    {
        return Notification::sent($this->owner, ProcurementAlert::class)->filter(fn (ProcurementAlert $alert) => str_starts_with($alert->title, 'Wastage alert'))->count();
    }

    public function test_the_shared_calculation_gives_the_worked_example(): void
    {
        $theoretical = WastageAllowance::theoretical('50', '8.4');

        $this->assertSame('420.000', (string) $theoretical);
        $this->assertSame('428.400', (string) WastageAllowance::allowed($theoretical, '2'));
        $this->assertSame('8.400', (string) WastageAllowance::allowance($theoretical, '2'));
        $this->assertSame('420.000', (string) WastageAllowance::allowed($theoretical, null));
        $this->assertSame('0.000', (string) WastageAllowance::theoretical('-5', '8.4'));

        $this->assertSame('ok', WastageAllowance::status($theoretical, '428.4', '428.4'));
        $this->assertSame('wastage', WastageAllowance::status($theoretical, '428.4', '428.401'));
        $this->assertSame('under', WastageAllowance::status($theoretical, '428.4', '419'));
        $this->assertSame('no_work', WastageAllowance::status('0', '0', '1'));
        $this->assertSame('ok', WastageAllowance::status('0', '0', '0'));
    }

    public function test_use_within_the_allowed_wastage_raises_no_flag_and_agrees_with_the_reconciliation(): void
    {
        $cement = $this->cement();
        $slab = $this->slab($cement);
        $this->useOn($slab, $cement, '428');

        $row = $this->rowFor($cement);
        $this->assertSame('420.000', $row['estimate']);
        $this->assertSame('428.000', $row['used']);
        // The wastage figure still reads against the BOQ; only the flag waits for the allowance.
        $this->assertSame('1.90', $row['wastage_pct']);
        $this->assertNull($this->flagOf($row, MaterialCheckService::OVER_USED));
        $this->assertNull($this->flagOf($row, MaterialCheckService::UNDER_USED));
        $this->assertSame(0, $row['open_flag_count']);

        $reconciliation = $this->reconciliationFor($slab);
        $this->assertSame('428.400', $reconciliation['allowed']);
        $this->assertSame('ok', $reconciliation['status']);

        $this->assertSame([], $this->overBoqAlerts());
        $this->assertSame(0, $this->wastageAlertsSent());
    }

    public function test_exactly_the_allowed_quantity_is_still_within_it(): void
    {
        $cement = $this->cement();
        $slab = $this->slab($cement);
        $this->useOn($slab, $cement, '428.4');

        $this->assertNull($this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_USED));
        $this->assertSame('ok', $this->reconciliationFor($slab)['status']);
        $this->assertSame([], $this->overBoqAlerts());
    }

    public function test_use_beyond_the_allowance_is_flagged_by_all_three_and_the_gap_reads_against_the_boq(): void
    {
        $cement = $this->cement();
        $slab = $this->slab($cement);
        $this->useOn($slab, $cement, '450');

        $flag = $this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_USED);
        $this->assertNotNull($flag);
        $this->assertSame('30.000', $flag['gap']);
        $this->assertTrue($flag['open']);
        $this->assertStringContainsString('30 bag', $flag['message']);

        $this->assertSame('wastage', $this->reconciliationFor($slab)['status']);
        $this->assertCount(1, $this->overBoqAlerts());
        $this->assertSame(1, $this->wastageAlertsSent());
    }

    public function test_a_norm_with_no_allowance_flags_any_use_above_the_boq_as_before(): void
    {
        $cement = $this->cement();
        $slab = $this->slab($cement, '0');
        $this->useOn($slab, $cement, '421');

        $this->assertSame('1.000', $this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_USED)['gap']);
        $this->assertSame('wastage', $this->reconciliationFor($slab)['status']);
    }

    public function test_a_material_with_no_norm_keeps_todays_flag_on_any_use(): void
    {
        $cement = $this->cement();
        $slab = $this->slab($cement);
        $sand = $this->bulk('SND', 'Sand', 'cum');
        $this->deliver($sand, '10');
        $this->useOn($slab, $sand, '2');

        $flag = $this->flagOf($this->rowFor($sand), MaterialCheckService::OVER_USED);
        $this->assertSame('2.000', $flag['gap']);
        $this->assertStringContainsString('no BOQ estimate', $flag['message']);
        // The reconciliation has no allowance to judge it by either.
        $this->assertSame('no_norm', collect(app(ReconciliationService::class)->consumption($this->project))->firstWhere('material_id', $sand->id)['status']);
    }

    public function test_allowances_add_up_across_the_boq_lines_that_use_a_material(): void
    {
        $cement = $this->cement();
        $slab = $this->slab($cement, '2');
        $beam = $this->boqFor($cement, '100', '10');
        BoqMaterialNorm::where('boq_item_id', $beam->id)->update(['wastage_pct' => '5']);
        $this->workDone($beam, '10');

        // Theoretical 420 + 100 = 520, allowed 428.4 + 105 = 533.4.
        $this->useOn($slab, $cement, '420');
        $this->useOn($beam, $cement, '113.4');
        $this->assertNull($this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_USED));

        $this->useOn($beam, $cement, '0.1');
        $this->assertSame('13.500', $this->flagOf($this->rowFor($cement), MaterialCheckService::OVER_USED)['gap']);
    }

    public function test_the_app_reads_the_check_in_the_same_shape(): void
    {
        $cement = $this->cement();
        $slab = $this->slab($cement);
        $this->useOn($slab, $cement, '450');

        $rowKeys = ['material_id', 'material', 'unit', 'estimate', 'full_need', 'received', 'used', 'extra_work', 'should_be_in_stock', 'stock_on_site', 'last_counted', 'variance', 'unaccounted', 'wastage_pct', 'short_delivered', 'flags', 'open_flag_count'];
        $flagKeys = ['type', 'gap', 'message', 'open', 'reason', 'reason_by', 'reason_at'];

        foreach (['engineer' => $rowKeys, 'owner' => [...$rowKeys, 'rate', 'wastage_value']] as $role => $expected) {
            Sanctum::actingAs($role === 'owner' ? $this->owner : $this->member($this->organization, $role));
            $data = $this->getJson('/api/v1/projects/'.$this->project->id.'/material-control/check')->assertOk()->json('data');

            $this->assertSame(['rows', 'open_flags', 'wastage_pct', 'wastage_basis', 'left_out'], array_keys($data));
            $this->assertSame($expected, array_keys($data['rows'][0]));
            $this->assertSame($flagKeys, array_keys($data['rows'][0]['flags'][0]));
            $this->assertSame('over_used', $data['rows'][0]['flags'][0]['type']);
        }
    }
}
