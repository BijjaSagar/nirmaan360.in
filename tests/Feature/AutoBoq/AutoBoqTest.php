<?php

namespace Tests\Feature\AutoBoq;

use App\Models\AutoBoqAssumption;
use App\Models\AutoBoqInput;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\RateLibraryItem;
use App\Services\Access\TeamAccessService;
use App\Services\AutoBoq\Assumptions;
use App\Services\AutoBoq\AutoBoqCalculator;
use App\Services\AutoBoq\AutoBoqService;
use App\Services\BoqService;
use App\Services\Drawings\TakeoffService;
use Tests\Feature\Drawings\BuildsDrawings;

class AutoBoqTest extends AutoBoqTestCase
{
    use BuildsDrawings;

    public function test_the_screen_opens_empty_and_says_what_it_does_and_does_not_do(): void
    {
        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()
            ->assertSee('It does not read the drawing itself.')
            ->assertSee('No lines yet')
            ->assertSee('Steel in slabs')
            ->assertSee('Typical values. Your engineer should change them')
            ->assertSee(route('auto-boq.assumptions'), false);
    }

    public function test_saving_the_dimensions_previews_the_lines_and_writes_nothing_to_the_boq(): void
    {
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions())
            ->assertRedirect(route('auto-boq.show', $this->project))->assertSessionHasNoErrors();

        $input = AutoBoqInput::where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame('100.000', $input->plinth_area);
        $this->assertSame(2, $input->floors);
        $this->assertSame('1.500', $input->footings[0]['length']);
        $this->assertSame('4', $input->footings[0]['nos']);
        $this->assertNull($input->columns[1]['height']);
        $this->assertSame('internal', $input->walls[1]['kind']);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => (new AutoBoqInput)->getMorphClass(), 'auditable_id' => $input->id, 'action' => 'created']);

        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()
            ->assertSee('RCC M25 in footings')->assertSee('5.010')
            ->assertSee('F1: 4 × 1.5 × 1.5 × 0.45 = 4.050')
            ->assertSee('New line')->assertSee('Not applied yet')
            ->assertSee('Needs the electrical layout.')
            ->assertSee('Needs its own drawing: fill in by hand');

        $this->assertSame(0, BoqItem::withoutGlobalScopes()->count());
        $this->assertSame(0, BoqRevision::withoutGlobalScopes()->count());

        // A figure out of range is refused with the field named.
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions(['floors' => 0, 'footings' => [0 => ['nos' => '-1']]]))
            ->assertSessionHasErrors(['floors', 'footings.0.nos']);
    }

    public function test_applying_writes_the_ticked_lines_in_one_history_batch_with_rates_at_zero(): void
    {
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions());
        $codes = array_values(array_diff(AutoBoqCalculator::codes(), ['AB-SAN', 'AB-WP']));

        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => $codes, 'reason' => 'Structural drawings R0'])
            ->assertRedirect(route('boq.index', $this->project))->assertSessionHasNoErrors()->assertSessionHas('success');

        $items = BoqItem::where('project_id', $this->project->id)->orderBy('sort')->get()->keyBy('code');
        $this->assertSame($codes, $items->keys()->all());

        $footing = $items['AB-RCC-FTG'];
        $this->assertSame('5.010', $footing->qty);
        $this->assertSame('cum', $footing->unit);
        $this->assertSame('rcc', $footing->category);
        $this->assertSame('0.00', $footing->rate);
        $this->assertSame('RCC M25 in footings', $footing->description);
        $this->assertStringContainsString('F1: 4 × 1.5 × 1.5 × 0.45 = 4.050', $footing->details);
        $this->assertStringContainsString('Total 5.010 cum', $footing->details);
        $this->assertSame('5.055', $items['AB-STL']->qty);
        $this->assertSame('MT', $items['AB-STL']->unit);
        $this->assertSame('0.000', $items['AB-EL-PT']->qty);
        $this->assertSame('Needs the electrical layout. Enter the quantity by hand.', $items['AB-EL-PT']->details);
        $this->assertSame(['0.00'], $items->pluck('rate')->unique()->all());

        $revisions = BoqRevision::withoutGlobalScopes()->get();
        $this->assertCount(count($codes), $revisions);
        $this->assertSame(['added'], $revisions->pluck('action')->unique()->all());
        $this->assertSame(['auto_boq'], $revisions->pluck('source')->unique()->all());
        $this->assertSame(['Structural drawings R0'], $revisions->pluck('reason')->unique()->all());
        $this->assertCount(1, $revisions->pluck('batch_uuid')->unique());
        $this->assertSame('0.00', $revisions->last()->valuation_after);

        $input = AutoBoqInput::where('project_id', $this->project->id)->firstOrFail();
        $this->assertEqualsCanonicalizing(['AB-SAN', 'AB-WP'], $input->excluded);
        $this->assertNotNull($input->applied_at);
        $this->assertSame($this->pm->id, $input->applied_by);

        // The lines left out stay unticked next time, and the applied ones read as unchanged.
        $preview = app(AutoBoqService::class)->preview($this->project);
        $this->assertFalse($preview['AB-SAN']['selected']);
        $this->assertTrue($preview['AB-EXC']['selected']);
        $this->assertSame('same', $preview['AB-EXC']['status']);
        $this->assertSame('new', $preview['AB-WP']['status']);
        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()->assertSee('No change')->assertSee('Last applied');
    }

    public function test_running_again_updates_lines_by_code_and_keeps_what_the_engineer_changed(): void
    {
        $codes = AutoBoqCalculator::codes();
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions());
        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => $codes]);

        // The engineer renames and prices a line by hand.
        $footing = BoqItem::where('project_id', $this->project->id)->where('code', 'AB-RCC-FTG')->firstOrFail();
        app(BoqService::class)->update($footing, ['description' => 'RCC M30 in footings', 'rate' => '7450.00'], 'Priced');

        // Bigger footings, then run again.
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions(['footings' => [0 => ['nos' => '8']]]));
        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()->assertSee('Quantity changes')->assertSee('9.060');
        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => $codes])->assertSessionHasNoErrors();

        $this->assertSame(count($codes), BoqItem::where('project_id', $this->project->id)->count());

        $footing->refresh();
        $this->assertSame('9.060', $footing->qty);
        $this->assertSame('RCC M30 in footings', $footing->description);
        $this->assertSame('7450.00', $footing->rate);
        $this->assertStringContainsString('F1: 8 × 1.5 × 1.5 × 0.45 = 8.100', $footing->details);

        $latest = BoqRevision::withoutGlobalScopes()->where('boq_item_id', $footing->id)->latest('id')->firstOrFail();
        $this->assertSame('edited', $latest->action);
        $this->assertSame('auto_boq', $latest->source);
        $this->assertSame(['5.010', '9.060'], $latest->changes['qty']);
        $this->assertArrayNotHasKey('description', $latest->changes);

        // A line whose figure did not move is not written again.
        $electrical = BoqItem::where('project_id', $this->project->id)->where('code', 'AB-EL-PT')->firstOrFail();
        $this->assertSame(1, BoqRevision::withoutGlobalScopes()->where('boq_item_id', $electrical->id)->count());
    }

    public function test_a_line_never_goes_below_its_approved_quantity(): void
    {
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions());
        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => AutoBoqCalculator::codes()]);

        $footing = BoqItem::where('project_id', $this->project->id)->where('code', 'AB-RCC-FTG')->firstOrFail();
        $this->approveEntry($this->draftEntry($this->project, $footing, $this->pm, ['nos' => '1', 'length' => '4', 'breadth' => '1', 'depth' => '1']), $this->pm, $this->owner);

        // Fewer footings: 1.493 cum, below the 4 cum already approved.
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions(['footings' => [0 => ['nos' => '1'], 1 => ['nos' => '1']]]));

        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()->assertSee('Below approved quantity')->assertSee('4.000');
        $this->assertTrue(app(AutoBoqService::class)->preview($this->project)['AB-RCC-FTG']['below']);
        $this->assertFalse(app(AutoBoqService::class)->preview($this->project)['AB-RCC-FTG']['selected']);

        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-RCC-FTG', 'AB-EXC']])->assertSessionHasErrors('codes');

        // Nothing at all was written, not even the other line.
        $this->assertSame('5.010', $footing->fresh()->qty);
        $this->assertSame('35.100', BoqItem::where('project_id', $this->project->id)->where('code', 'AB-EXC')->value('qty'));

        // Without the blocked line the rest applies.
        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-EXC']])->assertSessionHasNoErrors();
        $this->assertSame('10.935', BoqItem::where('project_id', $this->project->id)->where('code', 'AB-EXC')->value('qty'));
        $this->assertSame('5.010', $footing->fresh()->qty);
    }

    public function test_a_line_kept_in_another_unit_is_not_overwritten(): void
    {
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions());
        BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'code' => 'AB-STL', 'description' => 'Steel', 'unit' => 'kg', 'qty' => '1000.000', 'rate' => '0.00']);

        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()->assertSee('Unit differs in the BOQ');
        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-STL']])->assertSessionHasErrors('codes');

        $this->assertSame('1000.000', BoqItem::where('code', 'AB-STL')->value('qty'));
    }

    public function test_takeoff_layer_totals_fill_the_inputs_and_refresh_on_save(): void
    {
        $sheet = $this->scaledSheet($this->project);
        $plinth = $this->makeLayer($this->project, 'Plinth');
        $beams = $this->makeLayer($this->project, 'Plinth beams');
        $columns = $this->makeLayer($this->project, 'Columns');
        $this->actingAs($this->pm);
        $takeoff = app(TakeoffService::class);
        $takeoff->create($sheet, ['type' => 'area', 'takeoff_layer_id' => $plinth->id, 'points' => [[0, 0], [100, 0], [100, 50], [0, 50]]], $this->pm);
        $takeoff->create($sheet, ['type' => 'length', 'takeoff_layer_id' => $beams->id, 'points' => [[0, 0], [200, 0]]], $this->pm);
        $takeoff->create($sheet, ['type' => 'count', 'takeoff_layer_id' => $columns->id, 'points' => [[1, 1], [2, 2], [3, 3]]], $this->pm);

        $this->get(route('auto-boq.show', $this->project))->assertOk()
            ->assertSee('Plinth · 50.000 sqm')->assertSee('Plinth beams · 20.000 rmt')->assertSee('Columns · 3.000 nos');

        $this->put(route('auto-boq.save', $this->project), $this->dimensions([
            'plinth_area' => '1', 'plinth_area_layer' => $plinth->id,
            'beams' => [0 => ['length' => '1', 'length_layer' => $beams->id]],
            'columns' => [0 => ['nos' => '1', 'nos_layer' => $columns->id]],
        ]))->assertSessionHasNoErrors();

        $input = AutoBoqInput::where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame('50.000', $input->plinth_area);
        $this->assertSame(['plinth_area' => $plinth->id], $input->layers);
        $this->assertSame('20.000', $input->beams[0]['length']);
        $this->assertSame($beams->id, $input->beams[0]['length_layer']);
        $this->assertSame('3.000', $input->columns[0]['nos']);
        $this->assertSame('5.010', app(AutoBoqService::class)->preview($this->project)['AB-RCC-FTG']['line']->qty);

        // Re-measured drawings: saving again picks up the new total.
        $takeoff->create($sheet, ['type' => 'area', 'takeoff_layer_id' => $plinth->id, 'points' => [[0, 0], [50, 0], [50, 50], [0, 50]]], $this->pm);
        $this->put(route('auto-boq.save', $this->project), $this->dimensions(['plinth_area_layer' => $plinth->id]))->assertSessionHasNoErrors();
        $this->assertSame('75.000', $input->fresh()->plinth_area);

        // A layer with no measurement in that unit, or from another project, is refused.
        $this->put(route('auto-boq.save', $this->project), $this->dimensions(['plinth_area_layer' => $beams->id]))->assertSessionHasErrors('plinth_area_layer');
        $other = $this->makeProject($this->org, ['code' => 'OTH']);
        $this->put(route('auto-boq.save', $this->project), $this->dimensions(['plinth_area_layer' => $this->makeLayer($other, 'Elsewhere')->id]))->assertSessionHasErrors('plinth_area_layer');
        $this->assertSame('75.000', $input->fresh()->plinth_area);
    }

    public function test_rates_come_from_the_rate_library_and_only_for_people_who_see_money(): void
    {
        [$rivalOrg] = $this->rival();
        $rate = RateLibraryItem::create(['organization_id' => $this->org->id, 'item' => 'RCC M25 footing', 'unit' => 'cum', 'rate' => '7450.00', 'category' => 'RCC']);
        $foreign = RateLibraryItem::create(['organization_id' => $rivalOrg->id, 'item' => 'Excavation', 'unit' => 'cum', 'rate' => '350.00']);

        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions());
        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()->assertSee('Rate from library')->assertSee('₹7,450.00');

        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-RCC-FTG', 'AB-EXC'], 'rates' => ['AB-EXC' => $foreign->id]])
            ->assertSessionHasErrors('rates.AB-EXC');
        $this->assertSame(0, BoqItem::withoutGlobalScopes()->count());

        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-RCC-FTG', 'AB-EXC'], 'rates' => ['AB-RCC-FTG' => $rate->id, 'AB-EXC' => '']])
            ->assertSessionHasNoErrors();

        $footing = BoqItem::where('code', 'AB-RCC-FTG')->firstOrFail();
        $this->assertSame('7450.00', $footing->rate);
        $this->assertSame('0.00', BoqItem::where('code', 'AB-EXC')->value('rate'));
        $this->assertSame([null, '7450.00'], BoqRevision::withoutGlobalScopes()->where('boq_item_id', $footing->id)->firstOrFail()->changes['rate']);

        // Someone who manages the BOQ but may not see money gets no rate picker and cannot post one.
        $noMoney = $this->holding(['boq.manage', 'boq.view', 'project.view']);
        $this->actingAs($noMoney)->get(route('auto-boq.show', $this->project))->assertOk()
            ->assertSee('Lines worked out')->assertDontSee('Rate from library')->assertDontSee('7,450.00')->assertDontSee('₹');
        $this->actingAs($noMoney)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-RCC-COL'], 'rates' => ['AB-RCC-COL' => $rate->id]])->assertForbidden();
        $this->actingAs($noMoney)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-RCC-COL']])->assertSessionHasNoErrors();
        $this->assertSame('0.00', BoqItem::where('code', 'AB-RCC-COL')->value('rate'));
    }

    public function test_only_boq_managers_on_a_plan_with_site_control_can_use_it(): void
    {
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions());

        foreach ([$this->engineer, $this->member($this->org, 'viewer'), $this->member($this->org, 'store'), $this->holding(['boq.view', 'project.view'])] as $user) {
            $this->actingAs($user)->get(route('auto-boq.show', $this->project))->assertForbidden();
            $this->actingAs($user)->put(route('auto-boq.save', $this->project), $this->dimensions())->assertForbidden();
            $this->actingAs($user)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-EXC']])->assertForbidden();
            $this->actingAs($user)->get(route('auto-boq.assumptions'))->assertForbidden();
            $this->actingAs($user)->put(route('auto-boq.assumptions.save'), ['values' => Assumptions::defaults()])->assertForbidden();
        }

        $this->actingAs($this->qs)->get(route('auto-boq.show', $this->project))->assertOk();
        $this->assertSame(0, BoqItem::withoutGlobalScopes()->count());

        $free = $this->tenant('Free Co', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free);
        $this->actingAs($owner)->get(route('auto-boq.show', $project))->assertForbidden();
        $this->actingAs($owner)->get(route('auto-boq.assumptions'))->assertForbidden();
    }

    public function test_another_company_cannot_reach_the_dimensions_and_keeps_its_own_assumptions(): void
    {
        [, $rivalOwner] = $this->rival();
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions());
        $this->actingAs($this->pm)->put(route('auto-boq.assumptions.save'), ['values' => ['steel_footing' => '100'] + Assumptions::defaults()])->assertSessionHasNoErrors();

        $this->actingAs($rivalOwner)->get(route('auto-boq.show', $this->project))->assertNotFound();
        $this->actingAs($rivalOwner)->put(route('auto-boq.save', $this->project), $this->dimensions(['plinth_area' => '999']))->assertNotFound();
        $this->actingAs($rivalOwner)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-EXC']])->assertNotFound();
        $this->actingAs($rivalOwner)->get(route('auto-boq.assumptions'))->assertOk()
            ->assertSee('name="values[steel_footing]" type="number" min="0" max="99999" step="0.001" value="80.000"', false)->assertDontSee('Set by you');

        $this->assertSame('100.000', AutoBoqInput::withoutGlobalScopes()->where('project_id', $this->project->id)->value('plinth_area'));
        $this->assertSame(0, BoqItem::withoutGlobalScopes()->count());
        $this->assertSame(['100.000'], AutoBoqAssumption::withoutGlobalScopes()->where('key', 'steel_footing')->pluck('value')->all());
    }

    public function test_someone_limited_to_other_projects_gets_a_404(): void
    {
        $other = $this->makeProject($this->org, ['code' => 'OTH']);
        $limited = $this->member($this->org, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$other->id]);

        $this->actingAs($limited->refresh())->get(route('auto-boq.show', $this->project))->assertNotFound();
        $this->actingAs($limited)->put(route('auto-boq.save', $this->project), $this->dimensions())->assertNotFound();
        $this->actingAs($limited)->get(route('auto-boq.show', $other))->assertOk();
        $this->assertSame(0, AutoBoqInput::withoutGlobalScopes()->count());
    }

    public function test_assumptions_are_per_company_and_change_the_lines(): void
    {
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions());
        $this->assertSame('5.055', app(AutoBoqService::class)->preview($this->project)['AB-STL']['line']->qty);

        $this->actingAs($this->pm)->get(route('auto-boq.assumptions'))->assertOk()->assertSee('Steel in slabs')->assertDontSee('Set by you');

        $values = ['steel_slab' => '100', 'excavation_margin' => '0'] + Assumptions::defaults();
        $this->actingAs($this->pm)->put(route('auto-boq.assumptions.save'), ['values' => $values])
            ->assertRedirect(route('auto-boq.assumptions'))->assertSessionHas('success');

        $this->assertCount(count(Assumptions::keys()), AutoBoqAssumption::where('organization_id', $this->org->id)->get());
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => (new AutoBoqAssumption)->getMorphClass(), 'action' => 'created']);
        $this->actingAs($this->pm)->get(route('auto-boq.assumptions'))->assertOk()->assertSee('Set by you')
            ->assertSee('name="values[steel_slab]" type="number" min="0" max="99999" step="0.001" value="100.000"', false);

        $preview = app(AutoBoqService::class)->preview($this->project);
        $this->assertSame('5.280', $preview['AB-STL']['line']->qty);
        $this->assertSame('17.100', $preview['AB-EXC']['line']->qty);
        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()->assertSee('17.100');

        $this->actingAs($this->pm)->put(route('auto-boq.assumptions.save'), ['values' => ['steel_slab' => '-1'] + Assumptions::defaults()])->assertSessionHasErrors('values.steel_slab');
        $this->actingAs($this->pm)->put(route('auto-boq.assumptions.save'), ['values' => ['steel_slab' => '90']])->assertSessionHasErrors('values.carpet_share');
        $this->assertSame('100.000', AutoBoqAssumption::where('key', 'steel_slab')->value('value'));
    }

    public function test_the_menu_shows_the_screens_and_they_read_in_hindi_and_marathi(): void
    {
        $this->actingAs($this->pm)->get(route('boq.index', $this->project))->assertOk()
            ->assertSee('Auto BOQ')->assertSee(route('auto-boq.show', $this->project), false);
        $this->actingAs($this->pm)->get(route('dashboard'))->assertOk()->assertSee(route('auto-boq.assumptions'), false);
        $this->actingAs($this->engineer)->get(route('boq.index', $this->project))->assertOk()->assertDontSee(route('auto-boq.show', $this->project), false);

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()->assertSee('मापों से ऑटो BOQ');
        $this->actingAs($this->pm)->get(route('auto-boq.assumptions'))->assertOk()->assertSee('BOQ मान्यताएँ');

        // Lines are written in the language of the person who applies them.
        $this->actingAs($this->pm)->put(route('auto-boq.save', $this->project), $this->dimensions());
        $this->actingAs($this->pm)->post(route('auto-boq.apply', $this->project), ['codes' => ['AB-RCC-FTG']])->assertSessionHasNoErrors();
        $this->assertSame('फुटिंग में RCC M25', BoqItem::where('code', 'AB-RCC-FTG')->value('description'));

        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get(route('auto-boq.show', $this->project))->assertOk()->assertSee('मापांवरून ऑटो BOQ')->assertSee('बदल नाही');
        $this->actingAs($this->pm)->get(route('auto-boq.assumptions'))->assertOk()->assertSee('BOQ गृहीतके');
    }
}
