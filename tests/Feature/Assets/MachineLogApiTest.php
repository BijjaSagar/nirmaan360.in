<?php

namespace Tests\Feature\Assets;

use App\Models\MachineLog;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class MachineLogApiTest extends FieldOpsTestCase
{
    private const API = '/api/v1';

    public function test_a_log_posted_twice_with_the_same_client_uuid_is_saved_once(): void
    {
        $w = $this->world('engineer');
        $asset = $this->ownedAsset($w->owner, ['project_id' => $w->project->id]);
        Sanctum::actingAs($w->user);
        $payload = ['client_uuid' => (string) Str::uuid(), 'asset_id' => $asset->id, 'log_date' => '2026-11-03', 'opening_reading' => 100, 'closing_reading' => 108.5, 'operator' => 'Ramesh'];

        $this->postJson(self::API.'/machine-logs', $payload)->assertCreated()
            ->assertJsonPath('data.hours', '8.500')
            ->assertJsonPath('data.project_id', $w->project->id)
            ->assertJsonPath('data.client_uuid', $payload['client_uuid'])
            ->assertJsonPath('data.billed', false);

        $this->postJson(self::API.'/machine-logs', $payload)->assertOk();

        $this->assertSame(1, MachineLog::count());
    }

    public function test_bad_input_is_a_422(): void
    {
        $w = $this->world('engineer');
        $asset = $this->ownedAsset($w->owner);
        Sanctum::actingAs($w->user);

        $this->postJson(self::API.'/machine-logs', ['asset_id' => $asset->id])->assertUnprocessable()->assertJsonValidationErrors('log_date');
        $this->postJson(self::API.'/machine-logs', ['asset_id' => $asset->id, 'log_date' => '2026-11-03'])->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->postJson(self::API.'/machine-logs', ['asset_id' => $asset->id, 'log_date' => '2026-11-03', 'hours' => 25])->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->postJson(self::API.'/machine-logs', ['asset_id' => $asset->id, 'log_date' => '2999-01-01', 'hours' => 4])->assertUnprocessable()->assertJsonValidationErrors('log_date');
        $this->postJson(self::API.'/machine-logs', ['asset_id' => $asset->id, 'log_date' => '2026-11-03', 'hours' => 4, 'client_uuid' => 'not-a-uuid'])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }

    public function test_an_asset_or_project_of_another_company_is_refused(): void
    {
        $w = $this->world('engineer');
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $theirAsset = $this->ownedAsset($theirs->owner);
        $myAsset = $this->ownedAsset($w->owner);
        Sanctum::actingAs($w->user);

        $this->postJson(self::API.'/machine-logs', ['asset_id' => $theirAsset->id, 'log_date' => '2026-11-03', 'hours' => 4])->assertUnprocessable()->assertJsonValidationErrors('asset_id');
        $this->postJson(self::API.'/machine-logs', ['asset_id' => $myAsset->id, 'project_id' => $theirs->project->id, 'log_date' => '2026-11-03', 'hours' => 4])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->assertSame(0, MachineLog::withoutGlobalScopes()->count());
    }

    public function test_listing_is_limited_to_the_company_and_can_be_filtered(): void
    {
        $w = $this->world('engineer');
        $theirs = $this->world('owner', 'business', 'Rival Builders');
        $a = $this->ownedAsset($w->owner);
        $b = $this->ownedAsset($w->owner, ['name' => 'Roller']);
        $theirAsset = $this->ownedAsset($theirs->owner);

        $this->actingAs($w->owner);
        foreach ([[$a, '2026-11-01'], [$a, '2026-11-05'], [$b, '2026-11-05']] as [$asset, $date]) {
            $this->postJson(self::API.'/machine-logs', ['asset_id' => $asset->id, 'log_date' => $date, 'hours' => 4])->assertSuccessful();
        }
        $this->actingAs($theirs->owner)->post(route('machine-logs.store'), ['asset_id' => $theirAsset->id, 'log_date' => '2026-11-05', 'hours' => 9]);

        Sanctum::actingAs($w->user);
        $this->getJson(self::API.'/machine-logs')->assertOk()->assertJsonPath('total', 3);
        $this->getJson(self::API.'/machine-logs?asset_id='.$a->id)->assertOk()->assertJsonPath('total', 2);
        $this->getJson(self::API.'/machine-logs?from=2026-11-04&to=2026-11-30')->assertOk()->assertJsonPath('total', 2);
        $this->getJson(self::API.'/machine-logs?asset_id='.$theirAsset->id)->assertOk()->assertJsonPath('total', 0);

        $this->getJson(self::API.'/assets')->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.code', $a->code);
    }

    public function test_permissions_and_plan(): void
    {
        $w = $this->world('viewer');
        $asset = $this->ownedAsset($w->owner);

        $this->getJson(self::API.'/machine-logs')->assertUnauthorized();

        Sanctum::actingAs($w->user);
        $this->getJson(self::API.'/machine-logs')->assertOk();
        $this->getJson(self::API.'/assets')->assertOk();
        $this->postJson(self::API.'/machine-logs', ['asset_id' => $asset->id, 'log_date' => '2026-11-03', 'hours' => 4])->assertForbidden();

        $small = $this->world('owner', 'contractor', 'Small Co');
        Sanctum::actingAs($small->owner);
        $this->getJson(self::API.'/machine-logs')->assertForbidden();
        $this->getJson(self::API.'/assets')->assertForbidden();
        $this->postJson(self::API.'/machine-logs', ['asset_id' => 1, 'log_date' => '2026-11-03', 'hours' => 4])->assertForbidden();
    }

    public function test_a_day_covered_by_a_hire_bill_comes_back_as_a_422(): void
    {
        $w = $this->world('owner');
        $asset = $this->hiredAsset($w->owner, ['hire_basis' => 'hour', 'hire_rate' => 100]);
        Sanctum::actingAs($w->owner);

        $this->postJson(self::API.'/machine-logs', ['asset_id' => $asset->id, 'log_date' => '2026-09-01', 'hours' => 4])->assertCreated();
        $this->actingAs($w->owner)->post(route('hire-bills.store'), ['asset_id' => $asset->id, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30'])->assertRedirect();

        $this->postJson(self::API.'/machine-logs', ['asset_id' => $asset->id, 'log_date' => '2026-09-10', 'hours' => 4])->assertUnprocessable()->assertJsonValidationErrors('log_date');
    }
}
