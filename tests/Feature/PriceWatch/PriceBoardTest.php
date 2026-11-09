<?php

namespace Tests\Feature\PriceWatch;

use App\Models\AuditLog;
use App\Models\MaterialPrice;
use App\Models\PriceWatchMaterial;
use App\Models\PriceWatchSharedPrice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PriceBoardTest extends PriceWatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
    }

    public function test_the_default_list_is_added_once_and_a_companys_own_material_is_left_as_it_is(): void
    {
        // The company already follows cement, per kg and in lower case, before the board is ever opened.
        $own = $this->watch('cement', 'kg');

        $this->actingAs($this->pm)->get(route('price-watch.index'))->assertOk()->assertSee('No live price feed.')->assertSee('Water tank')->assertSee('Copper wire');

        $materials = PriceWatchMaterial::where('organization_id', $this->org->id)->get();
        $this->assertCount(8, $materials);
        $this->assertSame(['cement', 'kg', 'cement'], [$own->fresh()->name, $own->fresh()->unit, $own->fresh()->key]);
        $this->assertSame(['aac_blocks', 'aggregate', 'bricks', 'cement', 'copper_wire', 'sand', 'steel', 'water_tank'], $materials->pluck('key')->sort()->values()->all());

        // Switching one off is respected: a second visit adds nothing and switches nothing back on.
        $bricks = $materials->firstWhere('key', 'bricks');
        $bricks->update(['is_active' => false]);
        $this->actingAs($this->pm)->get(route('price-watch.index'))->assertOk()->assertSee('Switched off');
        $this->assertSame(8, PriceWatchMaterial::where('organization_id', $this->org->id)->count());
        $this->assertFalse($bricks->fresh()->is_active);
    }

    public function test_a_price_is_appended_with_its_date_and_source_and_the_board_shows_the_change(): void
    {
        $cement = $this->watch('Cement', 'bag', 'cement');
        $uuid = (string) Str::uuid();
        $post = fn (array $data) => $this->actingAs($this->pm)->post(route('price-watch.prices.store'), ['price_watch_material_id' => $cement->id, ...$data]);

        $post(['rate' => '380', 'priced_on' => '2026-11-01', 'source' => 'Birla dealer', 'client_uuid' => $uuid])->assertRedirect()->assertSessionHas('success');
        // The same form sent twice, as a phone on a weak network would.
        $post(['rate' => '380', 'priced_on' => '2026-11-01', 'source' => 'Birla dealer', 'client_uuid' => $uuid])->assertSessionHasNoErrors();
        $this->assertSame(1, MaterialPrice::count());

        // The same rate on the same day is already the latest; a new day is a new row even at the same rate.
        $post(['rate' => '380', 'priced_on' => '2026-11-01'])->assertSessionHas('success', 'That price is already the latest for Cement.');
        $this->assertSame(1, MaterialPrice::count());
        $post(['rate' => '399', 'priced_on' => '2026-11-09', 'source' => 'Market enquiry'])->assertSessionHas('success', 'Price saved for Cement as on 09 Nov 2026.');

        $price = MaterialPrice::latest('id')->first();
        $this->assertSame(['399.00', 'bag', '2026-11-09', 'Market enquiry', 'manual', $this->pm->id], [$price->rate, $price->unit, $price->priced_on->toDateString(), $price->source, $price->source_kind, $price->noted_by]);
        $this->assertTrue(AuditLog::where('auditable_type', $price->getMorphClass())->where('auditable_id', $price->id)->where('action', 'created')->exists());

        $this->actingAs($this->pm)->get(route('price-watch.index'))->assertOk()
            ->assertSee('₹399.00', false)->assertSee('09 Nov 2026')->assertSee('Market enquiry')->assertSee('₹380.00', false)->assertSee('+5%')->assertSee('<polyline', false);

        $this->actingAs($this->pm)->get(route('price-watch.materials.show', $cement))->assertOk()
            ->assertSee('Dated prices')->assertSee('Birla dealer')->assertSee('Market enquiry')->assertSee($this->pm->name);
    }

    public function test_a_price_older_than_the_limit_is_shown_as_out_of_date(): void
    {
        $cement = $this->watch('Cement', 'bag', 'cement');
        $this->price($cement, '380', '2026-10-10');

        $stamp = 'border-laterite text-laterite">Out of date';
        $this->actingAs($this->pm)->get(route('price-watch.index'))->assertOk()->assertSee($stamp, false);
        $this->actingAs($this->pm)->get(route('price-watch.materials.show', $cement))->assertOk()->assertSee($stamp, false)->assertSee('older than 30 days');

        // Exactly 30 days old is still current.
        $this->price($cement, '380', '2026-10-11');
        $this->actingAs($this->pm)->get(route('price-watch.index'))->assertOk()->assertDontSee($stamp, false);
        $this->actingAs($this->pm)->get(route('price-watch.materials.show', $cement))->assertOk()->assertDontSee($stamp, false);
    }

    public function test_prices_are_checked_and_a_wrong_entry_can_be_removed(): void
    {
        $cement = $this->watch('Cement', 'bag', 'cement');
        $post = fn (array $data) => $this->actingAs($this->pm)->post(route('price-watch.prices.store'), ['price_watch_material_id' => $cement->id, ...$data]);

        $post(['rate' => '-1', 'priced_on' => '2026-11-01'])->assertSessionHasErrors('rate');
        $post(['rate' => '1.234', 'priced_on' => '2026-11-01'])->assertSessionHasErrors('rate');
        $post(['rate' => '10', 'priced_on' => '2999-01-01'])->assertSessionHasErrors('priced_on');
        $post(['rate' => '10'])->assertSessionHasErrors('priced_on');
        $this->actingAs($this->pm)->post(route('price-watch.prices.store'), ['price_watch_material_id' => 999999, 'rate' => '10', 'priced_on' => '2026-11-01'])->assertSessionHasErrors('price_watch_material_id');
        $this->assertSame(0, MaterialPrice::count());

        $price = $this->price($cement, '380', '2026-11-01');
        $this->actingAs($this->pm)->delete(route('price-watch.prices.destroy', $price))->assertRedirect();
        $this->assertSame(0, MaterialPrice::count());
    }

    public function test_a_material_keeps_its_unit_once_priced_and_names_are_not_repeated(): void
    {
        $cement = $this->watch('Cement', 'bag', 'cement');
        $this->price($cement, '380', '2026-11-01');

        $this->actingAs($this->pm)->post(route('price-watch.materials.store'), ['name' => 'cement', 'unit' => 'kg'])->assertSessionHasErrors('name');
        $this->actingAs($this->pm)->patch(route('price-watch.materials.update', $cement), ['name' => 'Cement', 'unit' => 'kg'])->assertSessionHasErrors('unit');
        $this->actingAs($this->pm)->patch(route('price-watch.materials.update', $cement), ['name' => 'OPC 53 cement', 'unit' => 'bag', 'is_active' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(['OPC 53 cement', 'bag', false], [$cement->fresh()->name, $cement->fresh()->unit, $cement->fresh()->is_active]);

        $uuid = (string) Str::uuid();
        $this->actingAs($this->pm)->post(route('price-watch.materials.store'), ['name' => 'Vitrified tiles', 'unit' => 'sqm', 'client_uuid' => $uuid])->assertRedirect(route('price-watch.index'));
        $this->actingAs($this->pm)->post(route('price-watch.materials.store'), ['name' => 'Vitrified tiles', 'unit' => 'sqm', 'client_uuid' => $uuid])->assertSessionHasNoErrors();
        $this->assertSame(1, PriceWatchMaterial::where('name', 'Vitrified tiles')->count());
    }

    public function test_site_roles_see_nothing_and_only_managers_write(): void
    {
        $cement = $this->watch('Cement', 'bag', 'cement');
        $price = $this->price($cement, '380', '2026-11-01');
        $estimator = $this->holding(['boq.view', 'money.view', 'project.view']);

        foreach ([$this->engineer, $this->store, $this->viewer] as $user) {
            $this->actingAs($user);
            $this->get(route('price-watch.index'))->assertForbidden();
            $this->get(route('price-watch.materials.show', $cement))->assertForbidden();
            $this->get(route('price-watch.template'))->assertForbidden();
            $this->post(route('price-watch.prices.store'), ['price_watch_material_id' => $cement->id, 'rate' => '1', 'priced_on' => '2026-11-01'])->assertForbidden();
            $this->post(route('price-watch.materials.store'), ['name' => 'X', 'unit' => 'kg'])->assertForbidden();
            $this->post(route('price-watch.adopt'))->assertForbidden();
            $this->delete(route('price-watch.prices.destroy', $price))->assertForbidden();
        }

        // Seeing rates is not the same as writing them.
        foreach ([$this->qs, $estimator] as $user) {
            $this->actingAs($user);
            $this->get(route('price-watch.index'))->assertOk()->assertSee('₹380.00', false)->assertDontSee('Enter a price');
            $this->post(route('price-watch.prices.store'), ['price_watch_material_id' => $cement->id, 'rate' => '1', 'priced_on' => '2026-11-01'])->assertForbidden();
            $this->patch(route('price-watch.materials.update', $cement), ['name' => 'X', 'unit' => 'bag'])->assertForbidden();
        }

        foreach ([$this->owner, $this->pm, $this->accounts] as $user) {
            $this->actingAs($user)->get(route('price-watch.index'))->assertOk()->assertSee('Enter a price');
        }

        $this->assertSame(1, MaterialPrice::count());
        $this->assertSame('Cement', $cement->fresh()->name);
    }

    public function test_one_companys_board_is_invisible_to_another(): void
    {
        $cement = $this->watch('Cement', 'bag', 'cement');
        $price = $this->price($cement, '83614700', '2026-11-01');
        [, $rivalOwner] = $this->rival();

        $this->actingAs($rivalOwner);
        $this->get(route('price-watch.index'))->assertOk()->assertDontSee('83,61,47');
        $this->get(route('price-watch.materials.show', $cement))->assertNotFound();
        $this->patch(route('price-watch.materials.update', $cement), ['name' => 'Taken', 'unit' => 'bag'])->assertNotFound();
        $this->delete(route('price-watch.prices.destroy', $price))->assertNotFound();
        $this->post(route('price-watch.prices.store'), ['price_watch_material_id' => $cement->id, 'rate' => '1', 'priced_on' => '2026-11-01'])->assertSessionHasErrors('price_watch_material_id');

        $this->assertSame('Cement', $cement->fresh()->name);
        $this->assertSame(1, MaterialPrice::withoutGlobalScopes()->where('organization_id', $this->org->id)->count());
    }

    public function test_the_shared_list_can_be_taken_without_touching_a_companys_own_entries(): void
    {
        $cement = $this->watch('Cement', 'bag', 'cement');
        $this->price($cement, '380', '2026-11-01');
        $sand = $this->watch('Sand', 'brass', 'sand');
        $this->price($sand, '4500', '2026-11-08');
        $bricks = $this->watch('Bricks', 'nos', 'bricks');
        $bricks->update(['is_active' => false]);
        $steel = $this->watch('Steel', 'kg', 'steel');

        // Nothing to take while the list is empty.
        $this->actingAs($this->pm)->post(route('price-watch.adopt'))->assertSessionHasErrors('adopt');

        foreach ([
            ['key' => 'cement', 'name' => 'Cement', 'unit' => 'bag', 'rate' => '395.00', 'priced_on' => '2026-11-05', 'source' => 'Pune market'],
            ['key' => 'sand', 'name' => 'Sand', 'unit' => 'brass', 'rate' => '4700.00', 'priced_on' => '2026-11-05', 'source' => 'Pune market'],
            ['key' => 'bricks', 'name' => 'Bricks', 'unit' => 'nos', 'rate' => '9.00', 'priced_on' => '2026-11-05', 'source' => null],
            ['key' => 'steel', 'name' => 'Steel', 'unit' => 'MT', 'rate' => '62000.00', 'priced_on' => '2026-11-05', 'source' => null],
            ['key' => 'tiles', 'name' => 'Vitrified tiles', 'unit' => 'sqm', 'rate' => '55.00', 'priced_on' => '2026-11-05', 'source' => 'Morbi'],
        ] as $row) {
            PriceWatchSharedPrice::create($row);
        }

        $this->actingAs($this->pm)->post(route('price-watch.adopt'))->assertSessionHas('success', '2 prices taken from Shared list.');

        // Cement took the newer shared price; sand's own price is newer and stays current; bricks is switched off;
        // steel is priced per kg here and per MT there; tiles was unknown and joins the board.
        $this->assertSame(['395.00', 'shared', 'Pune market'], [$cement->prices()->first()->rate, $cement->prices()->first()->source_kind, $cement->prices()->first()->source]);
        $this->assertSame(['4500.00', 1], [$sand->prices()->first()->rate, $sand->prices()->count()]);
        $this->assertSame(0, $bricks->prices()->count());
        $this->assertSame(0, $steel->prices()->count());
        $tiles = PriceWatchMaterial::where('key', 'tiles')->sole();
        $this->assertSame(['Vitrified tiles', 'sqm', '55.00'], [$tiles->name, $tiles->unit, $tiles->prices()->first()->rate]);

        // Taking it again adds nothing.
        $this->actingAs($this->pm)->post(route('price-watch.adopt'))->assertSessionHas('success', 'Nothing new: your list already has everything the shared list knows.');
        $this->assertSame(4, MaterialPrice::count());

        $this->actingAs($this->pm)->get(route('price-watch.materials.show', $cement))->assertOk()->assertSee('from the shared list');
    }

    public function test_the_menu_shows_the_board_to_money_viewers_only(): void
    {
        $this->actingAs($this->pm)->get(route('dashboard'))->assertOk()->assertSee(route('price-watch.index'), false);
        $this->actingAs($this->engineer)->get(route('dashboard'))->assertOk()->assertDontSee(route('price-watch.index'), false);
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()->assertSee(route('price-watch.project', $this->project), false);
        $this->actingAs($this->qs)->get(route('projects.show', $this->project))->assertOk()->assertDontSee(route('price-watch.project', $this->project), false);
    }

    public function test_the_module_is_on_paid_plans_and_the_board_reads_in_hindi_and_marathi(): void
    {
        foreach (['contractor', 'business', 'enterprise'] as $plan) {
            $this->assertTrue($this->tenant("Co {$plan}", $plan)->allowsFeature('price_watch'), $plan);
        }

        $free = $this->tenant('Free Co', 'free');
        $this->assertFalse($free->allowsFeature('price_watch'));
        $this->actingAs($this->member($free, 'owner'))->get(route('price-watch.index'))->assertForbidden();

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get(route('price-watch.index'))->assertOk()->assertSee('सामग्री के भाव')->assertSee('पानी की टंकी');
        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get(route('price-watch.index'))->assertOk()->assertSee('साहित्याचे भाव')->assertSee('पाण्याची टाकी');
    }
}
