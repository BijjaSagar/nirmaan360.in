<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\BoqItem;
use App\Models\BoqMaterialNorm;
use App\Models\BoqRevision;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\MaterialUnitConversion;
use App\Services\ProjectSetup\ConcreteMix;

class ConcreteMixTest extends ProjectSetupTestCase
{
    private array $unit = [];

    private array $material = [];

    private BoqItem $slab;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['bag', 'cum', 'brass', 'kg'] as $code) {
            $this->unit[$code] = MaterialUnit::create(['organization_id' => $this->org->id, 'code' => $code]);
        }

        $this->material['cement'] = $this->makeMaterial('CEM', 'Cement OPC 53', 'bag');
        $this->material['aggregate'] = $this->makeMaterial('AGG', '20 mm aggregate', 'cum');
        $this->material['sand'] = $this->makeMaterial('SND', 'River sand', 'cum');
        $this->material['steel'] = $this->makeMaterial('STL', 'TMT steel', 'kg');

        foreach (['aggregate' => '2.830000', 'sand' => '2.830000'] as $key => $factor) {
            MaterialUnitConversion::create(['organization_id' => $this->org->id, 'material_id' => $this->material[$key]->id, 'unit_id' => $this->unit['brass']->id, 'factor' => $factor]);
        }

        $this->slab = $this->line('37.000', '7450.00', ['code' => 'S1', 'category' => 'rcc', 'description' => 'RCC M25 in slab']);
    }

    private function makeMaterial(string $code, string $name, string $baseUnit, $organization = null): Material
    {
        return Material::create([
            'organization_id' => ($organization ?? $this->org)->id,
            'code' => $code, 'name' => $name, 'base_unit_id' => $this->unit[$baseUnit]->id, 'gst_pct' => '18',
        ]);
    }

    private function mix(array $overrides = []): array
    {
        return [
            'mode' => 'ingredients',
            'cement_material_id' => $this->material['cement']->id, 'cement_qty' => '100', 'cement_unit_id' => $this->unit['bag']->id,
            'coarse_aggregate_material_id' => $this->material['aggregate']->id, 'coarse_aggregate_qty' => '5', 'coarse_aggregate_unit_id' => $this->unit['brass']->id,
            'sand_material_id' => $this->material['sand']->id, 'sand_qty' => '4', 'sand_unit_id' => $this->unit['brass']->id,
            'reason' => 'We will mix on site for this slab',
            ...$overrides,
        ];
    }

    private function save(array $overrides = [], $item = null)
    {
        return $this->actingAs($this->pm)->post(route('project-setup.boq-setup.concrete', $item ?? $this->slab), $this->mix($overrides));
    }

    public function test_a_line_is_ready_mix_by_the_cubic_metre_until_ingredients_are_entered(): void
    {
        $this->assertSame(['mode' => 'rmc', 'rows' => []], app(ConcreteMix::class)->current($this->slab));
    }

    public function test_ingredients_are_stored_per_unit_in_the_existing_norms_table(): void
    {
        $this->save()->assertSessionHasNoErrors();

        $norms = BoqMaterialNorm::where('boq_item_id', $this->slab->id)->get()->keyBy('mix_role');

        $this->assertCount(3, $norms);
        // 100 bags over 37 cum.
        $this->assertSame('2.702703', $norms['cement']->qty_per_unit);
        $this->assertSame($this->material['cement']->id, $norms['cement']->material_id);
        // 5 brass × 2.83 cum a brass = 14.15 cum, over 37 cum.
        $this->assertSame('0.382432', $norms['coarse_aggregate']->qty_per_unit);
        // 4 brass × 2.83 = 11.32 cum, over 37 cum.
        $this->assertSame('0.305946', $norms['sand']->qty_per_unit);
        $this->assertSame($this->project->id, $norms['sand']->project_id);
        $this->assertSame($this->org->id, $norms['sand']->organization_id);
    }

    public function test_the_totals_come_back_in_the_materials_own_unit(): void
    {
        $this->save();

        $mix = app(ConcreteMix::class)->current($this->slab->refresh());

        $this->assertSame('ingredients', $mix['mode']);
        $this->assertSame(['100.000', 'bag'], [$mix['rows']['cement']['qty'], $mix['rows']['cement']['unit']]);
        $this->assertSame(['11.320', 'cum'], [$mix['rows']['sand']['qty'], $mix['rows']['sand']['unit']]);
        $this->assertSame('Cement OPC 53', $mix['rows']['cement']['material']);

        $this->actingAs($this->pm)->get(route('project-setup.boq-setup', $this->project))->assertOk()
            ->assertSee('Mixed on site')->assertSee('RCC M25 in slab')->assertSee('Cement OPC 53')->assertSee('River sand');
    }

    public function test_the_ingredients_follow_the_item_when_its_quantity_is_revised(): void
    {
        $this->save();

        $this->actingAs($this->pm)->patch(route('boq.update', $this->slab), [
            'code' => 'S1', 'description' => 'RCC M25 in slab', 'unit' => 'cum', 'qty' => '74', 'rate' => '7450', 'reason' => 'Doubled',
        ])->assertSessionHasNoErrors();

        $this->assertSame('200.000', app(ConcreteMix::class)->current($this->slab->refresh())['rows']['cement']['qty']);
    }

    public function test_the_stock_check_reads_the_same_norms(): void
    {
        $this->save();

        $this->actingAs($this->pm)->get(route('procurement.reconciliation.index', $this->project))->assertOk()
            ->assertSee('Cement OPC 53')->assertSee('2.702703');
    }

    public function test_going_back_to_ready_mix_removes_only_the_mix_and_keeps_other_norms(): void
    {
        BoqMaterialNorm::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'boq_item_id' => $this->slab->id, 'material_id' => $this->material['steel']->id, 'qty_per_unit' => '90.000000', 'wastage_pct' => '3']);
        $this->save();
        $this->assertSame(4, BoqMaterialNorm::where('boq_item_id', $this->slab->id)->count());

        $this->save(['mode' => 'rmc', 'reason' => 'Ready-mix after all'])->assertSessionHasNoErrors();

        $left = BoqMaterialNorm::where('boq_item_id', $this->slab->id)->get();
        $this->assertCount(1, $left);
        $this->assertSame($this->material['steel']->id, $left->sole()->material_id);
        $this->assertSame('rmc', app(ConcreteMix::class)->current($this->slab)['mode']);
    }

    public function test_changing_an_ingredient_replaces_it_and_keeps_its_wastage(): void
    {
        $this->save();
        BoqMaterialNorm::where('mix_role', 'cement')->update(['wastage_pct' => '2.50']);
        $other = $this->makeMaterial('CEM2', 'Cement PPC', 'bag');

        $this->save(['cement_material_id' => $other->id, 'cement_qty' => '110', 'reason' => 'Switched cement'])->assertSessionHasNoErrors();

        $cement = BoqMaterialNorm::where('boq_item_id', $this->slab->id)->where('mix_role', 'cement')->sole();
        $this->assertSame($other->id, $cement->material_id);
        $this->assertSame('2.972973', $cement->qty_per_unit);
        $this->assertSame(3, BoqMaterialNorm::where('boq_item_id', $this->slab->id)->count());

        // The same material again keeps what was typed against it.
        $this->save(['cement_material_id' => $other->id, 'cement_qty' => '111', 'reason' => 'More'])->assertSessionHasNoErrors();
        BoqMaterialNorm::where('mix_role', 'sand')->update(['wastage_pct' => '4.00']);
        $this->save(['reason' => 'Again'])->assertSessionHasNoErrors();
        $this->assertSame('4.00', BoqMaterialNorm::where('mix_role', 'sand')->sole()->wastage_pct);
    }

    public function test_a_unit_with_no_conversion_is_refused_with_a_clear_message(): void
    {
        // Cement has no brass conversion.
        $this->save(['cement_unit_id' => $this->unit['brass']->id])->assertSessionHasErrors('cement_unit_id');

        $this->assertSame(0, BoqMaterialNorm::count());
    }

    public function test_every_ingredient_is_needed_and_must_be_a_different_material(): void
    {
        $this->save(['sand_material_id' => '', 'sand_qty' => ''])->assertSessionHasErrors(['sand_material_id', 'sand_qty']);
        $this->save(['sand_material_id' => $this->material['aggregate']->id])->assertSessionHasErrors('cement_material_id');
        $this->save(['cement_qty' => '0'])->assertSessionHasErrors('cement_qty');
        $this->save(['reason' => ''])->assertSessionHasErrors('reason');
        $this->save(['mode' => 'magic'])->assertSessionHasErrors('mode');

        $this->assertSame(0, BoqMaterialNorm::count());
    }

    public function test_ingredients_need_a_quantity_on_the_item_first(): void
    {
        $empty = $this->line('0.000', '0.00', ['category' => 'pcc']);

        $this->save([], $empty)->assertSessionHasErrors('mode');

        $this->assertSame(0, BoqMaterialNorm::count());
    }

    public function test_ready_mix_needs_no_ingredients(): void
    {
        $this->save(['mode' => 'rmc', 'cement_material_id' => '', 'cement_qty' => '', 'sand_material_id' => '', 'sand_qty' => ''])->assertSessionHasNoErrors();

        $this->assertSame(0, BoqMaterialNorm::count());
    }

    public function test_only_concrete_items_can_be_entered_this_way(): void
    {
        $brick = $this->line('10.000', '100.00', ['category' => 'brick_work']);
        $plain = $this->line('10.000', '100.00');

        $this->save([], $brick)->assertNotFound();
        $this->save([], $plain)->assertNotFound();

        foreach (['pcc', 'foundation', 'rcc'] as $category) {
            $this->save([], $this->line('10.000', '100.00', ['category' => $category]))->assertSessionHasNoErrors();
        }
    }

    public function test_the_change_goes_into_the_history_with_the_reason_and_the_boq_value_does_not_move(): void
    {
        BoqRevision::withoutGlobalScopes()->delete();

        $this->save()->assertSessionHasNoErrors();

        $revision = BoqRevision::withoutGlobalScopes()->sole();

        $this->assertSame('edited', $revision->action);
        $this->assertSame('concrete', $revision->source);
        $this->assertSame('We will mix on site for this slab', $revision->reason);
        $this->assertSame($this->pm->id, $revision->user_id);
        $this->assertSame('Ready-mix (RMC), by cubic metre', $revision->changes['concrete_mix'][0]);
        $this->assertStringStartsWith('Mixed on site: Cement: Cement OPC 53, 100.000 bag', $revision->changes['concrete_mix'][1]);
        $this->assertSame($revision->valuation_before, $revision->valuation_after);
        $this->assertSame('275650.00', $revision->valuation_after);

        // Saving the same thing again says nothing new.
        $this->save(['reason' => 'Same again']);
        $this->assertSame(1, BoqRevision::withoutGlobalScopes()->count());

        $this->actingAs($this->pm)->get(route('project-setup.history', $this->project))->assertOk()->assertSee('We will mix on site for this slab')->assertSee('Concrete');
    }

    public function test_a_material_of_another_company_is_refused(): void
    {
        [$rivalOrg] = $this->rival();
        $this->unit['rival'] = MaterialUnit::create(['organization_id' => $rivalOrg->id, 'code' => 'bag']);
        $foreign = Material::create(['organization_id' => $rivalOrg->id, 'code' => 'CEM', 'name' => 'Rival cement', 'base_unit_id' => $this->unit['rival']->id, 'gst_pct' => '18']);

        $this->save(['cement_material_id' => $foreign->id])->assertSessionHasErrors('cement_material_id');
        $this->save(['cement_unit_id' => $this->unit['rival']->id])->assertSessionHasErrors('cement_unit_id');

        $this->assertSame(0, BoqMaterialNorm::count());
    }

    public function test_another_company_cannot_change_the_mix(): void
    {
        [, $rivalOwner] = $this->rival();

        $this->actingAs($rivalOwner)->post(route('project-setup.boq-setup.concrete', $this->slab), $this->mix())->assertNotFound();

        $this->assertSame(0, BoqMaterialNorm::count());
    }

    public function test_the_setup_screen_tells_a_company_with_no_materials_what_to_do(): void
    {
        Material::query()->delete();

        $this->actingAs($this->pm)->get(route('project-setup.boq-setup', $this->project))->assertOk()
            ->assertSee('first add cement, aggregate and sand to the materials list')->assertSee(route('procurement.materials.index'), false);
    }

    public function test_someone_who_may_only_view_sees_how_it_is_entered_but_no_form(): void
    {
        $this->save();

        $this->actingAs($this->engineer)->get(route('project-setup.boq-setup', $this->project))->assertOk()
            ->assertSee('Cement OPC 53')->assertDontSee('Save concrete');
    }
}
