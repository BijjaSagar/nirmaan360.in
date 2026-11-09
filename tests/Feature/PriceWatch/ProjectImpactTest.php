<?php

namespace Tests\Feature\PriceWatch;

use App\Models\MaterialPrice;
use App\Models\PriceWatchMaterial;
use App\Models\PriceWatchProjectRate;
use App\Models\Project;
use App\Services\PriceWatch\ProjectPriceImpact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Feature\MaterialControl\BuildsMaterialControl;
use Tests\TestCase;

/**
 * The impact maths of section 10, on the procurement world: cement counted in kg with 1 bag = 50 kg,
 * a BOQ line needing 350 kg a cum over 100 cum, and deliveries against it.
 */
class ProjectImpactTest extends TestCase
{
    use BuildsMaterialControl, RefreshDatabase;

    private PriceWatchMaterial $cementBags;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpControl();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->project->update(['budget_amount' => '1000000.00', 'budget_margin_pct' => '5.00']);

        // Priced per bag, while the catalogue counts cement in kg.
        $this->cementBags = $this->watch('Cement', 'bag', 'cement', $this->cement->id);
    }

    private function watch(string $name, string $unit, ?string $key = null, ?int $materialId = null): PriceWatchMaterial
    {
        return PriceWatchMaterial::create(['organization_id' => $this->organization->id, 'key' => $key, 'name' => $name, 'unit' => $unit, 'material_id' => $materialId, 'sort' => 10]);
    }

    private function price(PriceWatchMaterial $material, string $rate, string $pricedOn): MaterialPrice
    {
        return MaterialPrice::create(['organization_id' => $this->organization->id, 'price_watch_material_id' => $material->id, 'rate' => $rate, 'unit' => $material->unit, 'priced_on' => $pricedOn, 'source' => 'Dealer quote']);
    }

    private function boqRate(PriceWatchMaterial $material, ?string $rate, ?string $qty = null): void
    {
        PriceWatchProjectRate::updateOrCreate(['project_id' => $this->project->id, 'price_watch_material_id' => $material->id], ['organization_id' => $this->organization->id, 'boq_rate' => $rate, 'boq_qty' => $qty]);
    }

    private function row(PriceWatchMaterial $material, ?Project $project = null): array
    {
        return app(ProjectPriceImpact::class)->rows(($project ?? $this->project)->fresh())->firstWhere('id', $material->id);
    }

    private function summary(): array
    {
        return app(ProjectPriceImpact::class)->summary($this->project->fresh());
    }

    /** 100 cum at 350 kg a cum is 35,000 kg, or 700 bags. */
    private function slabNeedingCement(): void
    {
        $this->boqFor($this->cement, '100', '350');
    }

    public function test_impact_is_the_rate_difference_over_the_quantity_still_to_buy_in_the_price_unit(): void
    {
        $this->slabNeedingCement();
        // 5,000 kg delivered is 100 bags, so 600 bags are still to buy.
        $this->deliver($this->cement, '5000', null, ['unit_id' => $this->kg->id]);
        $this->boqRate($this->cementBags, '380');
        $this->price($this->cementBags, '400', '2026-11-09');

        $row = $this->row($this->cementBags);

        $this->assertSame(['700.000', 'norms', '100.000', '600.000'], [$row['need'], $row['need_basis'], $row['received'], $row['remaining']]);
        $this->assertSame(['380.00', '400.00', '20.00', '5.26'], [$row['boq_rate'], $row['current']['rate'], $row['difference'], $row['above_boq_pct']]);
        // (400 − 380) × 600.
        $this->assertSame('12000.00', $row['impact']);
        $this->assertFalse($row['stale']);
        $this->assertNull($row['why']);
    }

    public function test_the_same_price_as_the_boq_rate_is_a_zero_impact_not_a_missing_one(): void
    {
        $this->slabNeedingCement();
        $this->boqRate($this->cementBags, '380');
        $this->price($this->cementBags, '380', '2026-11-09');

        $row = $this->row($this->cementBags);

        $this->assertSame(['0.00', '0.00', '0.00'], [$row['impact'], $row['difference'], $row['above_boq_pct']]);
        $this->assertNull($row['why']);
    }

    public function test_a_fall_in_price_is_a_negative_impact(): void
    {
        $this->slabNeedingCement();
        $this->boqRate($this->cementBags, '380');
        $this->price($this->cementBags, '370', '2026-11-09');

        $row = $this->row($this->cementBags);

        $this->assertSame(['-10.00', '-2.63', '-7000.00'], [$row['difference'], $row['above_boq_pct'], $row['impact']]);
    }

    public function test_with_no_price_or_no_boq_rate_there_is_no_impact_and_the_row_says_which_is_missing(): void
    {
        $this->slabNeedingCement();
        $this->boqRate($this->cementBags, '380');

        $row = $this->row($this->cementBags);
        $this->assertNull($row['impact']);
        $this->assertNull($row['current']);
        $this->assertSame(ProjectPriceImpact::NO_PRICE, $row['why']);

        $this->boqRate($this->cementBags, null);
        $this->price($this->cementBags, '400', '2026-11-09');

        $row = $this->row($this->cementBags);
        $this->assertNull($row['impact']);
        $this->assertNull($row['boq_rate']);
        $this->assertNull($row['above_boq_pct']);
        $this->assertSame(ProjectPriceImpact::NO_BOQ_RATE, $row['why']);
    }

    public function test_an_out_of_date_price_still_shows_its_impact_but_is_flagged_and_left_out_of_the_fresh_total(): void
    {
        $this->slabNeedingCement();
        $this->boqRate($this->cementBags, '380');
        // 31 days before today, with a 30-day limit.
        $this->price($this->cementBags, '400', '2026-10-10');

        $row = $this->row($this->cementBags);
        $this->assertTrue($row['stale']);
        $this->assertSame('14000.00', $row['impact']);

        $summary = $this->summary();
        $this->assertSame(['14000.00', '0.00', 1, 1], [$summary['impact'], $summary['fresh_impact'], $summary['stale'], $summary['with_impact']]);

        // Exactly 30 days old is still fresh.
        $this->price($this->cementBags, '400', '2026-10-11');
        $this->assertFalse($this->row($this->cementBags)['stale']);

        // The limit is adjustable.
        config(['modules.price_watch.stale_after_days' => 7]);
        $this->assertTrue($this->row($this->cementBags)['stale']);
    }

    public function test_the_quantity_still_to_buy_never_goes_below_zero(): void
    {
        $this->slabNeedingCement();
        // 40,000 kg delivered against 35,000 kg needed.
        $this->deliver($this->cement, '40000', null, ['unit_id' => $this->kg->id]);
        $this->boqRate($this->cementBags, '380');
        $this->price($this->cementBags, '400', '2026-11-09');

        $row = $this->row($this->cementBags);

        $this->assertSame(['700.000', '800.000', '0.000', '0.00'], [$row['need'], $row['received'], $row['remaining'], $row['impact']]);
    }

    public function test_the_water_tank_example_a_typed_boq_quantity_when_the_boq_has_no_norm(): void
    {
        // BOQ rate ₹6 a litre over a 5,000 litre tank, as in the discussion. Nothing in the catalogue, nothing delivered.
        $tank = $this->watch('Water tank', 'litre', 'water_tank');
        $this->boqRate($tank, '6', '5000');
        $this->price($tank, '6.50', '2026-11-09');

        $row = $this->row($tank);

        $this->assertSame(['5000.000', 'typed', '0.000', '5000.000'], [$row['need'], $row['need_basis'], $row['received'], $row['remaining']]);
        $this->assertSame(['0.50', '8.33', '2500.00'], [$row['difference'], $row['above_boq_pct'], $row['impact']]);

        // Without the quantity there is nothing to multiply.
        $this->boqRate($tank, '6', null);
        $row = $this->row($tank);
        $this->assertNull($row['impact']);
        $this->assertSame(ProjectPriceImpact::NO_QTY, $row['why']);
    }

    public function test_norms_win_over_a_typed_quantity_and_a_price_unit_with_no_conversion_gives_no_quantity(): void
    {
        $this->slabNeedingCement();
        // A typed quantity is ignored while the norms give one.
        $this->boqRate($this->cementBags, '380', '50');
        $this->price($this->cementBags, '400', '2026-11-09');
        $this->assertSame(['700.000', 'norms'], [$this->row($this->cementBags)['need'], $this->row($this->cementBags)['need_basis']]);

        // Steel priced per tonne, but the catalogue only knows kg and MT.
        $this->boqFor($this->steel, '10', '120');
        $perTonne = $this->watch('Steel', 'tonne', 'steel', $this->steel->id);
        $this->boqRate($perTonne, '60000');
        $this->price($perTonne, '63000', '2026-11-09');

        $row = $this->row($perTonne);
        $this->assertNull($row['need']);
        $this->assertNull($row['impact']);
        $this->assertSame(ProjectPriceImpact::UNIT_MISMATCH, $row['why']);

        // Priced per MT, which the catalogue converts: 1,200 kg is 1.2 MT.
        $perMt = $this->watch('Steel per MT', 'MT', null, $this->steel->id);
        $this->boqRate($perMt, '60000');
        $this->price($perMt, '63000', '2026-11-09');
        $row = $this->row($perMt);
        $this->assertSame(['1.200', '1.200', '3600.00'], [$row['need'], $row['remaining'], $row['impact']]);
    }

    public function test_the_summary_adds_the_impacts_and_sets_them_against_the_budget(): void
    {
        $this->slabNeedingCement();
        $this->deliver($this->cement, '5000', null, ['unit_id' => $this->kg->id]);
        $this->boqRate($this->cementBags, '380');
        $this->price($this->cementBags, '400', '2026-11-09');
        $tank = $this->watch('Water tank', 'litre', 'water_tank');
        $this->boqRate($tank, '6', '5000');
        $this->price($tank, '6.50', '2026-11-09');
        $unpriced = $this->watch('Sand', 'brass', 'sand');
        $this->boqRate($unpriced, '4500', '20');

        $summary = $this->summary();

        $this->assertSame('14500.00', $summary['impact']);
        $this->assertSame('14500.00', $summary['fresh_impact']);
        $this->assertSame(['1.45', 'budget', '1000000.00', '1050000.00'], [$summary['impact_pct'], $summary['basis'], $summary['budget'], $summary['ceiling']]);
        $this->assertSame([3, 2, 3, 2, 0, 2], [$summary['materials'], $summary['priced'], $summary['with_boq_rate'], $summary['with_impact'], $summary['stale'], $summary['rising']]);
        $this->assertFalse($summary['beyond_margin']);

        // Without a budget the BOQ value is the yardstick: the slab line is 100 cum at ₹1,000.
        $this->project->update(['budget_amount' => null]);
        $summary = $this->summary();
        $this->assertSame(['boq', '100000.00', '14.50'], [$summary['basis'], $summary['boq'], $summary['impact_pct']]);
        $this->assertNull($summary['budget']);

        // A budget of 1,05,000 allows 1,10,250; the BOQ plus the impact is 1,14,500.
        $this->project->update(['budget_amount' => '105000.00']);
        $this->assertTrue($this->summary()['beyond_margin']);
    }

    public function test_boq_rates_are_saved_per_project_and_only_for_the_companys_own_materials(): void
    {
        $service = app(ProjectPriceImpact::class);
        $tank = $this->watch('Water tank', 'litre', 'water_tank');

        $this->assertSame(2, $service->saveRates($this->project, [$this->cementBags->id => '380', $tank->id => '6'], [$tank->id => '5000'], $this->owner));
        $this->assertSame(0, $service->saveRates($this->project, [$this->cementBags->id => '380.00', $tank->id => '6'], [$tank->id => '5000.000'], $this->owner));
        $this->assertSame(1, $service->saveRates($this->project, [$this->cementBags->id => ''], [], $this->owner));
        $this->assertNull(PriceWatchProjectRate::where('price_watch_material_id', $this->cementBags->id)->sole()->boq_rate);

        // The other project is untouched.
        $elsewhere = $this->makeProject($this->organization, ['code' => 'ELSE']);
        $this->assertNull($this->row($tank, $elsewhere)['boq_rate']);

        $other = $this->tenant('Rival Builders');
        $foreign = PriceWatchMaterial::withoutGlobalScopes()->create(['organization_id' => $other->id, 'name' => 'Cement', 'unit' => 'bag']);

        $this->expectException(ValidationException::class);
        $service->saveRates($this->project, [$foreign->id => '1'], [], $this->owner);
    }
}
