<?php

namespace Tests\Feature\Api;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Support\Str;

class IssueApiTest extends ApiTestCase
{
    private function raise(Project $project, User $user, array $overrides = []): Issue
    {
        return app(IssueService::class)->create($project, $this->issueData($overrides), $user);
    }

    public function test_list_filters_by_status_and_is_paginated(): void
    {
        $site = $this->site();
        $this->raise($site->project, $site->user);
        $resolved = $this->raise($site->project, $site->user);
        app(IssueService::class)->update($resolved, ['status' => 'resolved']);

        $this->getJson(self::API."/projects/{$site->project->id}/issues?status=resolved")->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $resolved->id)
            ->assertJsonPath('data.0.status', 'resolved');
    }

    public function test_create_is_idempotent_on_client_uuid(): void
    {
        $site = $this->site();
        $payload = $this->issueData(['client_uuid' => (string) Str::uuid(), 'location_note' => 'Grid C7']);

        $this->postJson(self::API."/projects/{$site->project->id}/issues", $payload)->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.raised_by', $site->user->id)
            ->assertJsonPath('data.location_note', 'Grid C7');
        $this->postJson(self::API."/projects/{$site->project->id}/issues", $payload)->assertOk();

        $this->assertSame(1, Issue::count());
    }

    public function test_patch_sets_and_clears_resolved_at_like_the_web(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);

        $this->patchJson(self::API."/issues/{$issue->id}", ['status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertNotNull($issue->fresh()->resolved_at);

        $this->patchJson(self::API."/issues/{$issue->id}", ['status' => 'open'])->assertOk()->assertJsonPath('data.resolved_at', null);
        $this->assertNull($issue->fresh()->resolved_at);
    }

    public function test_patch_can_reassign_without_touching_the_status(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);
        $other = $this->member($site->organization);

        $this->patchJson(self::API."/issues/{$issue->id}", ['assigned_to' => $other->id])->assertOk()
            ->assertJsonPath('data.assigned_to', $other->id)
            ->assertJsonPath('data.status', 'open');
    }

    public function test_an_assignee_from_another_company_is_rejected(): void
    {
        $site = $this->site();
        $outsider = $this->member($this->tenant('Other Co'));
        $issue = $this->raise($site->project, $site->user);

        $this->patchJson(self::API."/issues/{$issue->id}", ['status' => 'open', 'assigned_to' => $outsider->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->postJson(self::API."/projects/{$site->project->id}/issues", $this->issueData(['assigned_to' => $outsider->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_routes_need_their_permission(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);

        $this->signIn($this->member($site->organization, 'qs'));
        $this->getJson(self::API."/projects/{$site->project->id}/issues")->assertForbidden();

        $this->signIn($this->member($site->organization, 'store'));
        $this->postJson(self::API."/projects/{$site->project->id}/issues", $this->issueData())->assertForbidden();
        $this->patchJson(self::API."/issues/{$issue->id}", ['status' => 'closed'])->assertForbidden();
    }

    public function test_another_companys_issues_are_a_404(): void
    {
        $site = $this->site();
        $issue = $this->raise($site->project, $site->user);
        $this->signInElsewhere();

        $this->getJson(self::API."/projects/{$site->project->id}/issues")->assertNotFound();
        $this->postJson(self::API."/projects/{$site->project->id}/issues", $this->issueData())->assertNotFound();
        $this->patchJson(self::API."/issues/{$issue->id}", ['status' => 'closed'])->assertNotFound();
    }
}
