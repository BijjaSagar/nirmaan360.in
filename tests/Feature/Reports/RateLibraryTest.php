<?php

namespace Tests\Feature\Reports;

use App\Models\RateLibraryItem;
use App\Services\Reports\RateLibraryService;
use Illuminate\Support\Str;

class RateLibraryTest extends ReportsTestCase
{
    private function payload(array $overrides = []): array
    {
        return ['item' => 'RCC M25 in slab', 'unit' => 'cum', 'category' => 'Concrete', 'rate' => '7450', ...$overrides];
    }

    public function test_a_rate_is_added_with_its_own_rate(): void
    {
        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload())->assertSessionHas('success');

        $item = RateLibraryItem::first();
        $this->assertSame('7450.00', $item->rate);
        $this->assertSame('Concrete', $item->category);
        $this->assertNull($item->material_rate);
        $this->assertFalse($item->hasSplit());
        $this->assertSame($this->org->id, $item->organization_id);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $item->getMorphClass(), 'auditable_id' => $item->id, 'action' => 'created']);
    }

    public function test_the_rate_is_the_total_of_the_split_when_any_part_is_given(): void
    {
        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload([
            'rate' => '1', // ignored: the parts decide
            'material_rate' => '4500.10', 'labour_rate' => '1200.20', 'plant_rate' => '300', 'overhead_rate' => '450.05', 'profit_rate' => '999.99',
        ]))->assertSessionHas('success');

        $item = RateLibraryItem::first();
        // 4500.10 + 1200.20 + 300.00 + 450.05 + 999.99 = 7450.34, added without float drift.
        $this->assertSame('7450.34', $item->rate);
        $this->assertTrue($item->hasSplit());
    }

    public function test_a_partial_split_still_sets_the_rate_and_missing_parts_stay_blank(): void
    {
        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload(['rate' => '', 'material_rate' => '10.10', 'labour_rate' => '20.20']))->assertSessionHas('success');

        $item = RateLibraryItem::first();
        $this->assertSame('30.30', $item->rate);
        $this->assertNull($item->plant_rate);
    }

    public function test_a_rate_or_a_split_is_needed(): void
    {
        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload(['rate' => '']))->assertSessionHasErrors('rate');
        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload(['rate' => '-5']))->assertSessionHasErrors('rate');
        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload(['unit' => '']))->assertSessionHasErrors('unit');
        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload(['item' => '']))->assertSessionHasErrors('item');
        $this->assertSame(0, RateLibraryItem::count());
    }

    public function test_the_largest_allowed_parts_still_fit_the_stored_rate(): void
    {
        $parts = ['material_rate' => '999999999999', 'labour_rate' => '999999999999', 'plant_rate' => '999999999999', 'overhead_rate' => '999999999999', 'profit_rate' => '999999999999'];

        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload($parts))->assertSessionHas('success');
        $this->assertSame('4999999999995.00', RateLibraryItem::first()->rate);

        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload(['material_rate' => '1000000000000']))->assertSessionHasErrors('material_rate');
    }

    public function test_adding_twice_with_the_same_client_uuid_gives_one_row(): void
    {
        $uuid = (string) Str::uuid();

        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload(['client_uuid' => $uuid]));
        $this->actingAs($this->pm)->post(route('ratelib.store'), $this->payload(['client_uuid' => $uuid]))->assertSessionHas('success');

        $this->assertSame(1, RateLibraryItem::count());
    }

    public function test_editing_recomputes_the_rate_and_removing_the_split_goes_back_to_a_plain_rate(): void
    {
        $item = app(RateLibraryService::class)->create($this->pm, $this->payload(['rate' => '', 'material_rate' => '100', 'labour_rate' => '50']));
        $this->assertSame('150.00', $item->rate);

        $this->actingAs($this->pm)->patch(route('ratelib.update', $item), $this->payload(['rate' => '', 'material_rate' => '100', 'labour_rate' => '75.50']))->assertSessionHas('success');
        $this->assertSame('175.50', $item->fresh()->rate);

        $this->actingAs($this->pm)->patch(route('ratelib.update', $item), $this->payload(['rate' => '200', 'material_rate' => '', 'labour_rate' => '']))->assertSessionHas('success');
        $item->refresh();
        $this->assertSame('200.00', $item->rate);
        $this->assertNull($item->material_rate);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $item->getMorphClass(), 'auditable_id' => $item->id, 'action' => 'updated']);
    }

    public function test_a_rate_can_be_removed(): void
    {
        $item = app(RateLibraryService::class)->create($this->pm, $this->payload());

        $this->actingAs($this->pm)->delete(route('ratelib.destroy', $item))->assertSessionHas('success');

        $this->assertSoftDeleted($item);
        $this->actingAs($this->pm)->get(route('ratelib.index'))->assertOk()->assertDontSee('RCC M25 in slab');
    }

    public function test_the_list_filters_by_name_and_category(): void
    {
        $service = app(RateLibraryService::class);
        $service->create($this->pm, $this->payload(['item' => 'Brickwork in CM 1:6', 'category' => 'Masonry', 'unit' => 'cum']));
        $service->create($this->pm, $this->payload(['item' => 'Plaster 12mm', 'category' => 'Finishing', 'unit' => 'sqm']));

        $this->actingAs($this->pm)->get(route('ratelib.index'))->assertOk()->assertSee('Brickwork')->assertSee('Plaster');
        $this->actingAs($this->pm)->get(route('ratelib.index', ['q' => 'brick']))->assertOk()->assertSee('Brickwork')->assertDontSee('Plaster 12mm');
        $this->actingAs($this->pm)->get(route('ratelib.index', ['category' => 'Finishing']))->assertOk()->assertSee('Plaster 12mm')->assertDontSee('Brickwork');
        $this->actingAs($this->pm)->get(route('ratelib.index', ['q' => 'masonry']))->assertOk()->assertSee('Brickwork');
    }

    public function test_search_returns_matches_for_the_picker_and_treats_wildcards_as_text(): void
    {
        $service = app(RateLibraryService::class);
        $service->create($this->pm, $this->payload(['item' => 'Earthwork excavation', 'unit' => 'cum', 'rate' => '185']));
        $service->create($this->pm, $this->payload(['item' => '100% pure cement', 'unit' => 'bag', 'rate' => '380']));
        $service->create($this->pm, $this->payload(['item' => 'Sand', 'unit' => 'cum', 'rate' => '1500']));

        $found = $this->actingAs($this->pm)->getJson(route('ratelib.search', ['q' => 'EARTH']))->assertOk()->json();
        $this->assertCount(1, $found);
        $this->assertSame(['item' => 'Earthwork excavation', 'unit' => 'cum', 'rate' => '185.00', 'category' => 'Concrete'], collect($found[0])->only(['item', 'unit', 'rate', 'category'])->all());

        // A % in the search is a literal character, not "anything".
        $this->assertCount(1, $this->actingAs($this->pm)->getJson(route('ratelib.search', ['q' => '100%']))->assertOk()->json());
        $this->assertCount(0, $this->actingAs($this->pm)->getJson(route('ratelib.search', ['q' => '%zzz']))->assertOk()->json());
        $this->assertCount(3, $this->actingAs($this->pm)->getJson(route('ratelib.search'))->assertOk()->json());
    }

    public function test_the_picker_shows_on_the_boq_form_and_not_where_it_cannot_work(): void
    {
        $project = $this->makeProject($this->org);

        $this->actingAs($this->pm)->get(route('boq.index', $project))->assertOk()->assertSee('Fill from the rate library');
        // The engineer may view the BOQ but has no rate permission.
        $this->actingAs($this->member($this->org, 'engineer'))->get(route('boq.index', $project))->assertOk()->assertDontSee('Fill from the rate library');

        $free = $this->tenant('Small Co', 'free');
        $this->actingAs($this->member($free, 'owner'))->get(route('boq.index', $this->makeProject($free)))->assertOk()->assertDontSee('Fill from the rate library');
    }

    public function test_permissions_by_role_and_plan(): void
    {
        $item = app(RateLibraryService::class)->create($this->pm, $this->payload());

        // Project manager and estimator manage rates.
        $this->actingAs($this->member($this->org, 'qs'))->post(route('ratelib.store'), $this->payload(['item' => 'By QS']))->assertSessionHas('success');
        // Nobody else in the stock roles can even look.
        foreach (['engineer', 'accounts', 'store', 'viewer'] as $role) {
            $user = $this->member($this->org, $role);
            $this->actingAs($user)->get(route('ratelib.index'))->assertForbidden();
            $this->actingAs($user)->getJson(route('ratelib.search'))->assertForbidden();
            $this->actingAs($user)->post(route('ratelib.store'), $this->payload())->assertForbidden();
            $this->actingAs($user)->patch(route('ratelib.update', $item), $this->payload())->assertForbidden();
            $this->actingAs($user)->delete(route('ratelib.destroy', $item))->assertForbidden();
        }

        $this->assertSame(2, RateLibraryItem::count());

        // The free plan does not include the module, even for its owner.
        $free = $this->tenant('Small Co', 'free');
        $this->actingAs($this->member($free, 'owner'))->get(route('ratelib.index'))->assertForbidden();
        $this->actingAs($this->member($free, 'owner'))->post(route('ratelib.store'), $this->payload())->assertForbidden();
    }

    public function test_one_company_cannot_see_or_change_anothers_rates(): void
    {
        $other = $this->tenant('Other Co', 'business');
        $theirs = app(RateLibraryService::class)->create($this->member($other, 'pm'), $this->payload(['item' => 'Their secret rate']));

        $this->actingAs($this->pm)->get(route('ratelib.index'))->assertOk()->assertDontSee('Their secret rate');
        $this->assertCount(0, $this->actingAs($this->pm)->getJson(route('ratelib.search', ['q' => 'secret']))->json());
        $this->actingAs($this->pm)->patch(route('ratelib.update', $theirs), $this->payload(['item' => 'Hijacked']))->assertNotFound();
        $this->actingAs($this->pm)->delete(route('ratelib.destroy', $theirs))->assertNotFound();

        $this->assertSame('Their secret rate', RateLibraryItem::withoutGlobalScopes()->find($theirs->id)->item);
        $this->assertNull(RateLibraryItem::withoutGlobalScopes()->find($theirs->id)->deleted_at);
    }

    public function test_the_same_client_uuid_in_two_companies_does_not_collide(): void
    {
        $uuid = (string) Str::uuid();
        $other = $this->tenant('Other Co', 'business');

        $mine = app(RateLibraryService::class)->create($this->pm, $this->payload(['client_uuid' => $uuid]));
        $theirs = app(RateLibraryService::class)->create($this->member($other, 'pm'), $this->payload(['client_uuid' => $uuid]));

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame($other->id, $theirs->organization_id);
    }
}
