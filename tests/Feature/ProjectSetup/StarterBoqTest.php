<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Services\ProjectSetup\BoqCategories;
use App\Services\ProjectSetup\StarterBoq;

class StarterBoqTest extends ProjectSetupTestCase
{
    public function test_the_starter_has_the_twelve_standard_items_in_order_with_nothing_priced(): void
    {
        $this->actingAs($this->pm)->post(route('project-setup.boq-setup.starter', $this->project))
            ->assertRedirect(route('boq.index', $this->project))->assertSessionHas('success');

        $items = BoqItem::where('project_id', $this->project->id)->orderBy('sort')->get();

        $this->assertCount(12, $items);
        $this->assertSame(
            ['Excavation', 'Filling', 'PCC (plain cement concrete)', 'Foundation concreting', 'Steel reinforcement', 'RCC: footings, columns, beams, slabs and chajjas', 'Brick work', 'Plaster', 'Flooring and tiles', 'Plumbing (all stages)', 'Electrical (all stages)', 'Painting and finishing'],
            $items->pluck('description')->all(),
        );
        $this->assertSame(['excavation', 'filling', 'pcc', 'foundation', 'steel', 'rcc', 'brick_work', 'plaster', 'flooring', 'plumbing', 'electrical', 'finishing'], $items->pluck('category')->all());
        $this->assertSame(range(1, 12), $items->pluck('sort')->all());
        $this->assertSame(array_map('strval', range(1, 12)), $items->pluck('code')->all());

        // Quantity and rate are the company's to fill in.
        $this->assertSame(['0.000'], $items->pluck('qty')->unique()->all());
        $this->assertSame(['0.00'], $items->pluck('rate')->unique()->all());
    }

    public function test_the_starter_is_one_batch_in_the_history_and_leaves_the_boq_worth_nothing(): void
    {
        $this->actingAs($this->pm)->post(route('project-setup.boq-setup.starter', $this->project));

        $revisions = BoqRevision::withoutGlobalScopes()->get();

        $this->assertCount(12, $revisions);
        $this->assertSame(['added'], $revisions->pluck('action')->unique()->all());
        $this->assertSame(['starter'], $revisions->pluck('source')->unique()->all());
        $this->assertCount(1, $revisions->pluck('batch_uuid')->unique());
        $this->assertSame('0.00', $revisions->last()->valuation_after);
    }

    public function test_a_thumb_rule_project_gets_a_short_starter_priced_per_square_foot(): void
    {
        $this->project->update(['boq_mode' => 'thumb_rule']);

        $this->actingAs($this->pm)->post(route('project-setup.boq-setup.starter', $this->project));

        $items = BoqItem::where('project_id', $this->project->id)->orderBy('sort')->get();

        $this->assertCount(6, $items);
        $this->assertSame(['sqft'], $items->pluck('unit')->unique()->all());
        $this->assertSame(['0.000'], $items->pluck('qty')->unique()->all());
    }

    public function test_the_starter_is_added_to_an_empty_boq_only_and_never_twice(): void
    {
        $this->actingAs($this->pm)->post(route('project-setup.boq-setup.starter', $this->project))->assertSessionHasNoErrors();
        $this->actingAs($this->pm)->post(route('project-setup.boq-setup.starter', $this->project))->assertSessionHasErrors('boq');

        $this->assertSame(12, BoqItem::where('project_id', $this->project->id)->count());

        $other = $this->makeProject($this->org, ['code' => 'OWN']);
        $this->line('1.000', '1.00', [], $other);
        $this->actingAs($this->pm)->post(route('project-setup.boq-setup.starter', $other))->assertSessionHasErrors('boq');
        $this->assertSame(1, BoqItem::where('project_id', $other->id)->count());
    }

    public function test_the_boq_style_is_chosen_per_project(): void
    {
        $other = $this->makeProject($this->org, ['code' => 'OTH']);

        $this->actingAs($this->pm)->patch(route('project-setup.boq-setup.mode', $this->project), ['boq_mode' => 'thumb_rule'])->assertSessionHasNoErrors();

        $this->assertSame('thumb_rule', $this->project->fresh()->boq_mode);
        $this->assertSame('detailed', $other->fresh()->boq_mode);

        $this->actingAs($this->pm)->patch(route('project-setup.boq-setup.mode', $this->project), ['boq_mode' => 'whatever'])->assertSessionHasErrors('boq_mode');

        $this->actingAs($this->pm)->get(route('project-setup.boq-setup', $this->project))->assertOk()
            ->assertSee('Thumb rule, overall lines')->assertSee('Structure: excavation to RCC and steel');
    }

    public function test_changing_the_style_leaves_existing_lines_alone(): void
    {
        $item = $this->line('10.000', '100.00');

        $this->actingAs($this->pm)->patch(route('project-setup.boq-setup.mode', $this->project), ['boq_mode' => 'thumb_rule']);

        $this->assertSame('10.000', $item->fresh()->qty);
        $this->assertSame(1, BoqItem::where('project_id', $this->project->id)->count());
    }

    public function test_the_setup_screen_and_the_empty_boq_offer_the_starter(): void
    {
        $this->actingAs($this->pm)->get(route('project-setup.boq-setup', $this->project))->assertOk()
            ->assertSee('Start from the standard 12 items')->assertSee('Painting and finishing');
        $this->actingAs($this->pm)->get(route('boq.index', $this->project))->assertOk()->assertSee('Start from the standard 12 items');

        $this->line();

        $this->actingAs($this->pm)->get(route('project-setup.boq-setup', $this->project))->assertOk()
            ->assertDontSee('Start from the standard 12 items')->assertSee('already has 1 items');
    }

    public function test_the_boq_screen_marks_items_still_to_fill_in_and_shows_category_and_details(): void
    {
        $this->actingAs($this->pm)->post(route('project-setup.boq-setup.starter', $this->project));
        $filled = $this->line('10.000', '100.00', ['category' => 'rcc', 'details' => 'M25 grade, 150 mm slab, with centering']);

        $this->actingAs($this->pm)->get(route('boq.index', $this->project))->assertOk()
            ->assertSee('To fill in')->assertSee('Brick work')->assertSee('M25 grade, 150 mm slab, with centering')
            ->assertSee(route('project-setup.history', [$this->project, 'item' => $filled->id]), false);
    }

    public function test_a_line_is_added_with_a_category_and_long_details(): void
    {
        $details = trim(str_repeat('Reinforced cement concrete of M25 grade with 20 mm aggregate. ', 40));

        $this->actingAs($this->pm)->post(route('boq.store', $this->project), [
            'code' => '6', 'description' => 'RCC', 'unit' => 'cum', 'qty' => '185', 'rate' => '7450', 'category' => 'rcc', 'details' => $details,
        ])->assertSessionHasNoErrors();

        $item = BoqItem::where('code', '6')->firstOrFail();
        $this->assertSame('rcc', $item->category);
        $this->assertSame($details, $item->details);

        $this->actingAs($this->pm)->post(route('boq.store', $this->project), [
            'code' => '7', 'description' => 'x', 'unit' => 'cum', 'qty' => '1', 'rate' => '1', 'category' => 'gold_plating',
        ])->assertSessionHasErrors('category');
        $this->actingAs($this->pm)->post(route('boq.store', $this->project), [
            'code' => '8', 'description' => 'x', 'unit' => 'cum', 'qty' => '1', 'rate' => '1', 'details' => str_repeat('a', 10001),
        ])->assertSessionHasErrors('details');
    }

    public function test_a_category_and_details_can_be_revised_and_are_kept_in_the_history(): void
    {
        $item = $this->line('10.000', '100.00', ['code' => 'C1']);

        $this->actingAs($this->pm)->patch(route('boq.update', $item), [
            'code' => 'C1', 'description' => 'RCC M25 in slab', 'unit' => 'cum', 'qty' => '10', 'rate' => '100',
            'category' => 'rcc', 'details' => 'Slab on grade', 'reason' => 'Classified for the budget screen',
        ])->assertSessionHasNoErrors();

        $revision = BoqRevision::withoutGlobalScopes()->where('boq_item_id', $item->id)->latest('id')->firstOrFail();
        $this->assertSame([null, 'rcc'], $revision->changes['category']);
        $this->assertSame([null, 'Slab on grade'], $revision->changes['details']);
    }

    public function test_the_categories_cover_the_standard_heads_and_are_read_from_spreadsheet_words(): void
    {
        $this->assertSame('brick_work', BoqCategories::fromText('Brick Work'));
        $this->assertSame('brick_work', BoqCategories::fromText('brick-work'));
        $this->assertSame('rcc', BoqCategories::fromText(' RCC '));
        $this->assertSame('flooring', BoqCategories::fromText('Flooring and tiles'));
        $this->assertNull(BoqCategories::fromText('Gold plating'));
        $this->assertSame(['rcc', 'brick_work'], BoqCategories::WATCHED);
        $this->assertSame(12, count(StarterBoq::DETAILED));
        $this->assertEmpty(array_diff(array_column(StarterBoq::DETAILED, 2), BoqCategories::keys()));
        $this->assertEmpty(array_diff(array_column(StarterBoq::THUMB_RULE, 2), BoqCategories::keys()));
    }

    public function test_only_boq_managers_on_a_plan_with_site_control_can_use_the_setup_actions(): void
    {
        $item = $this->line('1.000', '1.00', ['category' => 'rcc']);

        foreach ([$this->engineer, $this->holding(['boq.view', 'project.view'])] as $user) {
            $this->actingAs($user)->post(route('project-setup.boq-setup.starter', $this->project))->assertForbidden();
            $this->actingAs($user)->patch(route('project-setup.boq-setup.mode', $this->project), ['boq_mode' => 'thumb_rule'])->assertForbidden();
            $this->actingAs($user)->post(route('project-setup.boq-setup.concrete', $item), ['mode' => 'rmc', 'reason' => 'x'])->assertForbidden();
            $this->actingAs($user)->get(route('project-setup.boq-setup', $this->project))->assertOk();
        }

        $free = $this->tenant('Free Co', 'free');
        $owner = $this->member($free, 'owner');
        $project = $this->makeProject($free);
        $this->actingAs($owner)->post(route('project-setup.boq-setup.starter', $project))->assertForbidden();
        $this->actingAs($owner)->get(route('project-setup.boq-setup', $project))->assertForbidden();
        $this->actingAs($owner)->get(route('project-setup.history', $project))->assertForbidden();
    }

    public function test_one_company_cannot_start_or_restyle_anothers_boq(): void
    {
        [, $rivalOwner] = $this->rival();

        $this->actingAs($rivalOwner)->post(route('project-setup.boq-setup.starter', $this->project))->assertNotFound();
        $this->actingAs($rivalOwner)->patch(route('project-setup.boq-setup.mode', $this->project), ['boq_mode' => 'thumb_rule'])->assertNotFound();
        $this->actingAs($rivalOwner)->get(route('project-setup.boq-setup', $this->project))->assertNotFound();

        $this->assertSame(0, BoqItem::where('project_id', $this->project->id)->count());
        $this->assertSame('detailed', $this->project->fresh()->boq_mode);
    }
}
