<?php

namespace Tests\Feature\Qhs;

use App\Exceptions\ImmutableRecordException;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Services\Qhs\ChecklistService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class InspectionTest extends FieldOpsTestCase
{
    private function template(object $w, array $items = ['Level checked', 'Cover blocks fixed', 'Cubes cast'], string $name = 'Pour card'): ChecklistTemplate
    {
        return app(ChecklistService::class)->createTemplate(['name' => $name, 'category' => 'pour_card', 'items' => $items], $w->owner);
    }

    private function start(object $w, ChecklistTemplate $template, array $data = []): Inspection
    {
        return app(ChecklistService::class)->start($w->project, ['checklist_template_id' => $template->id, ...$data], $w->owner);
    }

    public function test_the_standard_checklists_are_added_once_per_company(): void
    {
        $w = $this->world();
        $other = $this->world('owner', 'business', 'Other Co');
        $this->actingAs($w->owner);

        $this->post(route('checklists.standard'))->assertRedirect()->assertSessionHas('success');
        $first = ChecklistTemplate::count();
        $this->post(route('checklists.standard'));

        $this->assertSame(count(ChecklistService::STANDARD), $first);
        $this->assertSame($first, ChecklistTemplate::count(), 'The second click adds nothing.');
        $this->assertTrue(ChecklistTemplate::where('name', 'PPE checklist')->where('category', 'ppe')->exists());
        $this->assertTrue(ChecklistTemplate::where('name', 'Pour card')->first()->items !== []);

        Auth::forgetUser();
        $this->actingAs($other->owner);
        $this->assertSame(0, ChecklistTemplate::count(), 'Another company has none until it adds them.');
    }

    public function test_a_template_is_made_from_one_item_per_line(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $this->post(route('checklists.store'), ['name' => 'Curing check', 'category' => 'general', 'items_text' => "Wet hessian placed\n\n  Water ponding on slab  \nCuring record signed\r\n"])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['Wet hessian placed', 'Water ponding on slab', 'Curing record signed'], ChecklistTemplate::firstOrFail()->items);
    }

    public function test_a_template_needs_a_unique_name_a_known_category_and_an_item(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $this->template($w);

        $this->post(route('checklists.store'), ['name' => 'Pour card', 'category' => 'general', 'items_text' => 'x'])->assertSessionHasErrors('name');
        $this->post(route('checklists.store'), ['name' => 'Other', 'category' => 'weird', 'items_text' => 'x'])->assertSessionHasErrors('category');
        $this->post(route('checklists.store'), ['name' => 'Other', 'category' => 'general', 'items_text' => "  \n "])->assertSessionHasErrors('items');
        $this->assertSame(1, ChecklistTemplate::count());
    }

    public function test_editing_a_template_does_not_change_inspections_already_started(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $template = $this->template($w);
        $inspection = $this->start($w, $template);

        $this->patch(route('checklists.update', $template), ['name' => 'Pour card', 'category' => 'pour_card', 'items_text' => 'Only one item now', 'is_active' => 1])->assertRedirect();

        $this->assertSame(['Only one item now'], $template->refresh()->items);
        $this->assertSame(['Level checked', 'Cover blocks fixed', 'Cubes cast'], $inspection->items()->pluck('text')->all());
    }

    public function test_an_inactive_template_cannot_start_an_inspection(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $template = $this->template($w);
        $this->patch(route('checklists.update', $template), ['name' => 'Pour card', 'category' => 'pour_card', 'items_text' => 'x']); // is_active left out: switched off

        $this->post(route('inspections.store', $w->project), ['checklist_template_id' => $template->id])->assertSessionHasErrors('checklist_template_id');
    }

    public function test_starting_copies_the_items_in_order_and_records_the_inspector(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $inspection = $this->start($w, $this->template($w), ['location' => 'Slab L3', 'inspected_on' => '2026-11-02']);

        $this->assertSame('in_progress', $inspection->status);
        $this->assertSame('Pour card', $inspection->title);
        $this->assertSame('pour_card', $inspection->category);
        $this->assertSame($w->owner->id, $inspection->inspector_id);
        $this->assertSame([1, 2, 3], $inspection->items->pluck('sort')->all());
        $this->assertSame(['pending', 'pending', 'pending'], $inspection->items->pluck('result')->all());
    }

    public function test_saving_and_completing_through_the_form(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $inspection = $this->start($w, $this->template($w));
        $ids = $inspection->items->pluck('id');

        $this->patch(route('inspections.update', $inspection), ['items' => [
            ['id' => $ids[0], 'result' => 'pass'],
            ['id' => $ids[1], 'result' => 'na', 'remark' => 'Precast'],
            ['id' => $ids[2], 'result' => 'pending'],
        ]])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('in_progress', $inspection->refresh()->status);

        $this->patch(route('inspections.update', $inspection), ['complete' => 1, 'items' => [['id' => $ids[2], 'result' => 'pending']]])->assertSessionHasErrors('items');

        $this->patch(route('inspections.update', $inspection), ['complete' => 1, 'items' => [['id' => $ids[2], 'result' => 'pass']]])->assertSessionHasNoErrors();

        $inspection->refresh();
        $this->assertSame('passed', $inspection->status);
        $this->assertNotNull($inspection->completed_at);
        $this->assertSame($w->owner->id, $inspection->completed_by);
    }

    public function test_a_failed_item_needs_a_remark_and_makes_the_inspection_failed(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $inspection = $this->start($w, $this->template($w));
        $ids = $inspection->items->pluck('id');

        $this->patch(route('inspections.update', $inspection), ['items' => [['id' => $ids[0], 'result' => 'fail']]])->assertSessionHasErrors('items.0.remark');
        $this->assertSame('pending', InspectionItem::find($ids[0])->result);

        $this->patch(route('inspections.update', $inspection), ['complete' => 1, 'items' => [
            ['id' => $ids[0], 'result' => 'fail', 'remark' => 'Cover only 20 mm'],
            ['id' => $ids[1], 'result' => 'pass'],
            ['id' => $ids[2], 'result' => 'pass'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame('failed', $inspection->refresh()->status);
        $this->get(route('inspections.show', $inspection))->assertOk()->assertSee('Cover only 20 mm')->assertSee('Failed');
        $this->get(route('inspections.index', $w->project))->assertSee('1 / 3');
    }

    public function test_a_completed_inspection_is_locked(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $inspection = $this->start($w, $this->template($w, ['Only point']), ['results' => [['position' => 1, 'result' => 'pass']], 'complete' => true]);
        $itemId = $inspection->items->first()->id;

        $this->assertSame('passed', $inspection->refresh()->status);
        $this->patch(route('inspections.update', $inspection), ['items' => [['id' => $itemId, 'result' => 'fail', 'remark' => 'Changed my mind']]])->assertStatus(409);
        $this->assertSame('pass', InspectionItem::find($itemId)->result);

        try {
            $inspection->update(['title' => 'Renamed']);
            $this->fail('The model must refuse too.');
        } catch (ImmutableRecordException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_results_can_arrive_with_the_start_by_position(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $template = $this->template($w);

        $inspection = $this->start($w, $template, ['results' => [
            ['position' => 1, 'result' => 'pass'],
            ['position' => 3, 'result' => 'fail', 'remark' => 'Only two cubes cast'],
        ]]);

        $this->assertSame(['pass', 'pending', 'fail'], $inspection->items->pluck('result')->all());
        $this->assertSame('in_progress', $inspection->status);

        $this->expectException(ValidationException::class);
        $this->start($w, $template, ['results' => [['position' => 9, 'result' => 'pass']]]);
    }

    public function test_the_same_client_uuid_starts_one_inspection(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $template = $this->template($w);
        $payload = ['checklist_template_id' => $template->id, 'client_uuid' => (string) Str::uuid()];

        $this->post(route('inspections.store', $w->project), $payload)->assertRedirect();
        $this->post(route('inspections.store', $w->project), $payload)->assertRedirect();

        $this->assertSame(1, Inspection::count());
        $this->assertSame(3, InspectionItem::count());
    }

    public function test_the_ppe_filter_and_standard_ppe_checklist(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        app(ChecklistService::class)->installStandard($w->owner);
        $ppe = ChecklistTemplate::where('name', 'PPE checklist')->first();
        $pour = ChecklistTemplate::where('name', 'Pour card')->first();
        $this->start($w, $ppe, ['location' => 'Block A']);
        $this->start($w, $pour, ['location' => 'Slab L3']);

        $this->get(route('inspections.index', [$w->project, 'category' => 'ppe']))->assertOk()->assertSee('PPE checklist')->assertDontSee('Slab L3');
        $this->get(route('inspections.index', [$w->project, 'status' => 'passed']))->assertOk()->assertDontSee('Slab L3');
    }

    public function test_who_can_manage_templates_fill_and_view(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $template = $this->template($w);
        $inspection = $this->start($w, $template);

        foreach ([
            // role => [manage templates, fill inspections]
            'pm' => [true, true], 'engineer' => [false, true], 'qs' => [false, false], 'viewer' => [false, false], 'store' => [false, false],
        ] as $role => [$manage, $fill]) {
            Auth::forgetUser();
            $this->actingAs($this->member($w->organization, $role));

            $this->get(route('checklists.index'))->assertOk();
            $this->get(route('inspections.index', $w->project))->assertOk();
            $this->get(route('inspections.show', $inspection))->assertOk();
            $this->post(route('checklists.store'), ['name' => "T $role", 'category' => 'general', 'items_text' => 'x'])->assertStatus($manage ? 302 : 403);
            $this->post(route('checklists.standard'))->assertStatus($manage ? 302 : 403);
            $this->post(route('inspections.store', $w->project), ['checklist_template_id' => $template->id])->assertStatus($fill ? 302 : 403);
        }

        Auth::forgetUser();
        $this->actingAs($this->member($w->organization, 'accounts'))->get(route('checklists.index'))->assertForbidden();
    }

    public function test_another_companys_checklists_and_inspections_are_out_of_reach(): void
    {
        $mine = $this->world();
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $this->actingAs($theirs->owner);
        $theirTemplate = $this->template($theirs, ['Level checked', 'Cover blocks fixed', 'Cubes cast'], 'Rival secret checklist');
        $theirInspection = $this->start($theirs, $theirTemplate);
        $itemId = $theirInspection->items->first()->id;

        Auth::forgetUser();
        $this->actingAs($mine->owner);
        $myTemplate = $this->template($mine, ['x'], 'Mine');

        $this->get(route('inspections.show', $theirInspection))->assertNotFound();
        $this->patch(route('inspections.update', $theirInspection), ['items' => [['id' => $itemId, 'result' => 'pass']]])->assertNotFound();
        $this->patch(route('checklists.update', $theirTemplate), ['name' => 'Hijacked', 'category' => 'general', 'items_text' => 'x'])->assertNotFound();
        $this->get(route('inspections.index', $theirs->project))->assertNotFound();
        $this->post(route('inspections.store', $mine->project), ['checklist_template_id' => $theirTemplate->id])->assertSessionHasErrors('checklist_template_id');

        // An item of another inspection cannot be slipped into my own inspection's save.
        $mine_inspection = $this->start($mine, $myTemplate);
        $this->patch(route('inspections.update', $mine_inspection), ['items' => [['id' => $itemId, 'result' => 'pass']]])->assertSessionHasErrors('items.0.id');
        $this->assertSame('pending', InspectionItem::withoutGlobalScopes()->find($itemId)->result);
        $this->get(route('checklists.index'))->assertOk()->assertSee('Mine')->assertDontSee('Rival secret checklist');
    }
}
