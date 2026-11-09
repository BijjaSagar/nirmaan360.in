<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\AuditLog;
use App\Models\Site;
use App\Services\Assets\AssetService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class AssetRegisterTest extends FieldOpsTestCase
{
    public function test_registering_numbers_the_asset_gives_it_a_private_tag_and_records_where_it_is(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $first = $this->ownedAsset($w->owner, ['project_id' => $w->project->id]);
        $second = $this->ownedAsset($w->owner, ['name' => 'Roller']);

        $this->assertSame('AST-0001', $first->code);
        $this->assertSame('AST-0002', $second->code);
        $this->assertSame(12, strlen($first->tag));
        $this->assertNotSame($first->tag, $second->tag);
        $this->assertStringNotContainsString($first->code, $first->tagUrl(), 'The tag link does not spell out the asset code.');
        $this->assertStringEndsWith('/a/'.$first->tag, $first->tagUrl());
        $this->assertSame('active', $first->status);

        $this->assertSame(1, AssetMovement::where('asset_id', $first->id)->count(), 'Starting location is recorded.');
        $this->assertSame(0, AssetMovement::where('asset_id', $second->id)->count());
        $this->assertTrue(AuditLog::where('auditable_type', Asset::class)->where('action', 'created')->exists());
    }

    public function test_the_same_client_uuid_returns_the_first_asset(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $uuid = (string) Str::uuid();

        $a = $this->ownedAsset($w->owner, ['client_uuid' => $uuid]);
        $b = $this->ownedAsset($w->owner, ['client_uuid' => $uuid, 'name' => 'Something else']);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Asset::count());
    }

    public function test_codes_are_unique_in_a_company_but_free_in_another(): void
    {
        $w = $this->world();
        $other = $this->world('owner', 'business', 'Other Co');

        $this->actingAs($w->owner)->post(route('assets.store'), ['name' => 'A', 'category' => 'crane', 'ownership' => 'owned', 'code' => 'CR-1'])->assertRedirect();
        $this->post(route('assets.store'), ['name' => 'B', 'category' => 'crane', 'ownership' => 'owned', 'code' => 'CR-1'])->assertSessionHasErrors('code');

        Auth::forgetUser();
        $this->actingAs($other->owner)->post(route('assets.store'), ['name' => 'C', 'category' => 'crane', 'ownership' => 'owned', 'code' => 'CR-1'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_hired_assets_need_their_terms(): void
    {
        $w = $this->world();

        $this->actingAs($w->owner)->post(route('assets.store'), ['name' => 'Tipper', 'category' => 'tipper', 'ownership' => 'hired'])
            ->assertSessionHasErrors(['hire_basis', 'hire_rate']);

        $this->post(route('assets.store'), ['name' => 'Tipper', 'category' => 'tipper', 'ownership' => 'hired', 'hire_basis' => 'km', 'hire_rate' => '22.5'])
            ->assertRedirect();

        $asset = Asset::firstOrFail();
        $this->assertSame('22.50', $asset->hire_rate);
        $this->assertNull($asset->purchase_cost);
    }

    public function test_owned_assets_drop_any_hire_terms(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);

        $asset = $this->ownedAsset($w->owner, ['hire_basis' => 'hour', 'hire_rate' => '500', 'min_hours_per_month' => '100', 'purchase_cost' => '2500000']);

        $this->assertNull($asset->hire_basis);
        $this->assertNull($asset->hire_rate);
        $this->assertSame('2500000.00', $asset->purchase_cost);
    }

    public function test_a_vendor_and_a_site_must_belong_to_this_company_and_project(): void
    {
        $w = $this->world();
        $other = $this->world('owner', 'business', 'Other Co');
        $foreignVendor = $this->vendor($other->organization);
        $foreignProject = $other->project;
        $this->actingAs($w->owner);

        $this->post(route('assets.store'), ['name' => 'X', 'category' => 'crane', 'ownership' => 'hired', 'hire_basis' => 'day', 'hire_rate' => 1, 'vendor_id' => $foreignVendor->id])
            ->assertSessionHasErrors('vendor_id');
        $this->post(route('assets.store'), ['name' => 'X', 'category' => 'crane', 'ownership' => 'owned', 'project_id' => $foreignProject->id])
            ->assertSessionHasErrors('project_id');

        $siteOfAnotherProject = Site::create(['organization_id' => $w->organization->id, 'project_id' => $this->projectOf($w->organization)->id, 'name' => 'Yard']);

        $this->post(route('assets.store'), ['name' => 'X', 'category' => 'crane', 'ownership' => 'owned', 'project_id' => $w->project->id, 'site_id' => $siteOfAnotherProject->id])
            ->assertSessionHasErrors('site_id');
        $this->assertSame(0, Asset::count());
    }

    public function test_editing_changes_details_but_not_the_location(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $asset = $this->ownedAsset($w->owner, ['project_id' => $w->project->id]);

        $this->patch(route('assets.update', $asset), ['name' => 'JCB 3DX Plus', 'category' => 'backhoe', 'ownership' => 'owned', 'project_id' => null, 'location_note' => 'Hacked'])->assertRedirect();

        $asset->refresh();
        $this->assertSame('JCB 3DX Plus', $asset->name);
        $this->assertSame($w->project->id, $asset->project_id);
        $this->assertNull($asset->location_note);
    }

    public function test_a_transfer_moves_the_asset_and_keeps_the_history(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $asset = $this->ownedAsset($w->owner, ['project_id' => $w->project->id]);
        $second = $this->projectOf($w->organization, 'Metro Depot');
        $uuid = (string) Str::uuid();

        $this->post(route('assets.transfers.store'), ['asset_id' => $asset->id, 'to_project_id' => $second->id, 'moved_on' => '2026-11-01', 'reason' => 'Needed for piling', 'client_uuid' => $uuid, '_tab' => 'transfers'])
            ->assertRedirect(route('assets.show', ['asset' => $asset->id, 'tab' => 'transfers']));

        $this->post(route('assets.transfers.store'), ['asset_id' => $asset->id, 'to_project_id' => $second->id, 'moved_on' => '2026-11-01', 'client_uuid' => $uuid]);

        $this->assertSame($second->id, $asset->refresh()->project_id);
        $move = AssetMovement::latest('id')->first();
        $this->assertSame($w->project->id, $move->from_project_id);
        $this->assertSame($second->id, $move->to_project_id);
        $this->assertSame(2, AssetMovement::where('asset_id', $asset->id)->count(), 'The first registration plus one move; the repeat did nothing.');

        $this->post(route('assets.transfers.store'), ['asset_id' => $asset->id, 'to_location' => 'Main yard', 'moved_on' => '2026-11-05'])->assertRedirect();
        $asset->refresh();
        $this->assertNull($asset->project_id);
        $this->assertSame('Main yard', $asset->location_note);

        $this->post(route('assets.transfers.store'), ['asset_id' => $asset->id, 'moved_on' => '2026-11-05'])->assertSessionHasErrors(['to_project_id', 'to_location']);
    }

    public function test_bringing_a_retired_asset_back_keeps_it_under_repair_while_a_breakdown_is_open(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $asset = $this->ownedAsset($w->owner);
        $service = app(AssetService::class);

        $service->update($asset, ['status' => 'retired']);
        $this->assertSame('retired', $asset->refresh()->status);

        $service->update($asset, ['status' => 'active']);
        $this->assertSame('active', $asset->refresh()->status);

        $asset->breakdowns()->create(['organization_id' => $w->organization->id, 'reported_at' => now(), 'description' => 'Engine seized', 'status' => 'open']);
        $service->update($asset, ['status' => 'retired']);
        $service->update($asset->refresh(), ['status' => 'active']);

        $this->assertSame('under_repair', $asset->refresh()->status);
    }

    public function test_a_retired_asset_cannot_be_moved_or_logged(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $asset = $this->ownedAsset($w->owner);
        app(AssetService::class)->update($asset, ['status' => 'retired']);

        $this->expectException(ValidationException::class);
        app(AssetService::class)->transfer($asset->refresh(), ['to_location' => 'Yard', 'moved_on' => '2026-11-01'], $w->owner);
    }

    public function test_scanning_a_tag_opens_the_asset_only_for_its_own_company(): void
    {
        $w = $this->world();
        $other = $this->world('owner', 'business', 'Other Co');
        $this->actingAs($w->owner);
        $asset = $this->ownedAsset($w->owner);

        $this->get(route('assets.scan', $asset->tag))->assertRedirect(route('assets.show', $asset));
        $this->get('/a/nosuchtagatall')->assertNotFound();

        Auth::forgetUser();
        $this->actingAs($other->owner)->get(route('assets.scan', $asset->tag))->assertNotFound();
        $this->get(route('assets.show', $asset))->assertNotFound();
        $this->get(route('assets.tag', $asset))->assertNotFound();
        $this->get(route('assets.edit', $asset))->assertNotFound();
        $this->get(route('assets.index'))->assertOk()->assertDontSee($asset->name);
    }

    public function test_an_unsigned_in_visitor_is_sent_to_sign_in_when_scanning(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $asset = $this->ownedAsset($w->owner);
        Auth::forgetUser();
        $this->app['auth']->guard('web')->logout();

        $this->get(route('assets.scan', $asset->tag))->assertRedirect(route('login'));
    }

    public function test_printable_tags_carry_a_qr_and_the_code(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $asset = $this->ownedAsset($w->owner, ['name' => 'Tower Crane']);

        $this->get(route('assets.tag', $asset))->assertOk()->assertSee('<svg', false)->assertSee($asset->code)->assertSee('Tower Crane');
        $this->get(route('assets.tags'))->assertOk()->assertSee($asset->code);
    }

    public function test_register_filters(): void
    {
        $w = $this->world();
        $this->actingAs($w->owner);
        $this->ownedAsset($w->owner, ['name' => 'Alpha Crane', 'category' => 'crane']);
        $this->hiredAsset($w->owner, ['name' => 'Beta Tipper', 'category' => 'tipper', 'registration_no' => 'MH12AB1234']);

        $this->get(route('assets.index', ['q' => 'alpha']))->assertSee('Alpha Crane')->assertDontSee('Beta Tipper');
        $this->get(route('assets.index', ['q' => 'mh12ab']))->assertSee('Beta Tipper')->assertDontSee('Alpha Crane');
        $this->get(route('assets.index', ['ownership' => 'hired']))->assertSee('Beta Tipper')->assertDontSee('Alpha Crane');
        $this->get(route('assets.index', ['category' => 'crane']))->assertSee('Alpha Crane')->assertDontSee('Beta Tipper');
    }
}
