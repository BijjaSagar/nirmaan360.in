<?php

namespace Tests\Feature\Api;

use App\Models\MeasurementEntry;
use App\Services\ApprovalService;
use App\Services\MeasurementService;

class MeasurementApiTest extends ApiTestCase
{
    public function test_list_is_paginated_filters_by_status_and_shows_the_return_remark(): void
    {
        $site = $this->site();
        $qs = $this->member($site->organization, 'qs');
        $this->draftEntry($site->project, $site->item, $site->user);
        $returned = $this->draftEntry($site->project, $site->item, $site->user, ['location' => 'S2']);
        app(ApprovalService::class)->act(app(MeasurementService::class)->submit($returned, $site->user), $qs, 'return', 'Check depth');

        $response = $this->getJson(self::API."/projects/{$site->project->id}/measurements?status=RETURNED")->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('total', 1)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('data.0.id', $returned->id)
            ->assertJsonPath('data.0.status', 'RETURNED')
            ->assertJsonPath('data.0.return_remark', 'Check depth')
            ->assertJsonPath('data.0.measured_on', '2026-11-01')
            ->assertJsonPath('data.0.nos', '2.000')
            ->assertJsonPath('data.0.deviation', false);
    }

    public function test_writes_use_the_shared_shape_and_clear_the_client_stamp(): void
    {
        $site = $this->site();
        $entry = $this->draftEntry($site->project, $site->item, $site->user, ['client_updated_at' => now()]);

        $this->patchJson(self::API."/measurements/{$entry->id}", $this->measurementData($site->item, ['nos' => 3]))->assertOk()
            ->assertJsonPath('data.qty', '75.000')
            ->assertJsonPath('data.client_updated_at', null);

        $this->postJson(self::API."/measurements/{$entry->id}/submit")->assertOk()
            ->assertJsonPath('data.status', 'IN_REVIEW');
    }

    public function test_routes_need_their_permission(): void
    {
        $site = $this->site();
        $entry = $this->draftEntry($site->project, $site->item, $site->user);
        $this->signIn($this->member($site->organization, 'store'));

        $this->getJson(self::API."/projects/{$site->project->id}/measurements")->assertForbidden();
        $this->postJson(self::API."/projects/{$site->project->id}/measurements", $this->measurementData($site->item))->assertForbidden();
        $this->patchJson(self::API."/measurements/{$entry->id}", $this->measurementData($site->item))->assertForbidden();
        $this->postJson(self::API."/measurements/{$entry->id}/submit")->assertForbidden();
    }

    public function test_routes_respect_the_plan_feature(): void
    {
        $site = $this->site('owner');
        config(['buildos.plans.business.features' => ['site']]);

        $this->getJson(self::API."/projects/{$site->project->id}/measurements")->assertForbidden();
    }

    public function test_another_companys_measurements_are_a_404(): void
    {
        $site = $this->site();
        $entry = $this->draftEntry($site->project, $site->item, $site->user);
        $this->signInElsewhere();

        $this->getJson(self::API."/projects/{$site->project->id}/measurements")->assertNotFound();
        $this->postJson(self::API."/projects/{$site->project->id}/measurements", $this->measurementData($site->item))->assertNotFound();
        $this->patchJson(self::API."/measurements/{$entry->id}", $this->measurementData($site->item))->assertNotFound();
        $this->postJson(self::API."/measurements/{$entry->id}/submit")->assertNotFound();
        $this->assertSame(1, MeasurementEntry::withoutGlobalScopes()->count());
    }
}
