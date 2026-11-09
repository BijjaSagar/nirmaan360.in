<?php

namespace Tests\Feature\Agents;

use App\Models\AgentConversation;
use App\Models\AgentInstall;
use App\Models\AgentRun;
use App\Models\Dpr;
use App\Services\Agents\AgentRegistry;
use App\Support\Modules;
use Laravel\Sanctum\Sanctum;

/** Plans, permissions and tenant isolation, on the web and the mobile API. */
class AccessTest extends AgentTestCase
{
    private function finishedRun($user, ?string $reply = 'ok'): AgentRun
    {
        $this->connect();
        $this->fakeClaude([$this->finalAnswer($reply)]);

        return $this->runAgent('project_brain', $user);
    }

    public function test_agents_are_on_business_and_enterprise_only(): void
    {
        foreach (['free' => false, 'contractor' => false, 'business' => true, 'enterprise' => true] as $plan => $included) {
            $organization = $this->tenant('Co '.$plan, $plan);
            $this->assertSame($included, $organization->allowsFeature('ai_agents'), $plan);

            $response = $this->actingAs($this->member($organization, 'owner'))->get('/agents');
            $included ? $response->assertOk() : $response->assertForbidden();
        }
    }

    public function test_menu_shows_agents_only_on_those_plans(): void
    {
        $this->assertNotEmpty(Modules::nav('company', $this->tenant('Big', 'business')));
        $this->assertSame([], array_filter(Modules::nav('company', $this->tenant('Small', 'contractor')), fn ($item) => $item['route'] === 'agents.index'));
    }

    public function test_lower_plan_cannot_reach_any_agent_screen_or_endpoint(): void
    {
        $organization = $this->tenant('Small', 'contractor');
        $project = $this->makeProject($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);

        foreach (['/agents', '/agents/site_reporter', '/agents/site_reporter/chat', '/agent-runs', '/agent-credits', "/projects/{$project->id}/ask"] as $screen) {
            $this->get($screen)->assertForbidden();
        }

        $this->post('/agents/site_reporter/chat', ['message' => 'hi'])->assertForbidden();
        $this->assertSame(0, AgentRun::count());

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/agents')->assertForbidden();
        $this->postJson('/api/v1/agents/site_reporter/runs', ['input' => 'hi'])->assertForbidden();
    }

    public function test_viewer_has_no_access(): void
    {
        $this->actingAs($this->member($this->tenant(), 'viewer'))->get('/agents')->assertForbidden();
    }

    public function test_only_managers_change_agent_settings(): void
    {
        $organization = $this->tenant();

        $this->actingAs($this->member($organization, 'engineer'))
            ->patch('/agents/project_brain/settings', ['enabled' => 1, 'autonomy' => 'L0', 'max_steps' => 2, 'max_seconds' => 30, 'max_credits' => 1])
            ->assertForbidden();

        $this->actingAs($this->member($organization, 'owner'))
            ->patch('/agents/project_brain/settings', ['enabled' => 1, 'autonomy' => 'L0', 'max_steps' => 2, 'max_seconds' => 30, 'max_credits' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, AgentInstall::sole()->max_steps);
    }

    public function test_autonomy_cannot_be_raised_past_the_agents_ceiling(): void
    {
        $organization = $this->tenant();

        $this->actingAs($this->member($organization, 'owner'))
            ->patch('/agents/project_brain/settings', ['enabled' => 1, 'autonomy' => 'L2', 'max_steps' => 2, 'max_seconds' => 30, 'max_credits' => 1])
            ->assertSessionHasErrors('autonomy');

        $this->patch('/agents/site_reporter/settings', ['enabled' => 1, 'autonomy' => 'L3', 'max_steps' => 2, 'max_seconds' => 30, 'max_credits' => 1])
            ->assertSessionHasErrors('autonomy');

        $this->assertSame(0, AgentInstall::count());
    }

    public function test_a_stored_autonomy_above_the_ceiling_is_capped(): void
    {
        $organization = $this->tenant();
        $this->settings('project_brain', $organization, ['autonomy' => 'L2']);
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('ok')]);

        $this->assertSame('L0', $this->runAgent('project_brain', $this->member($organization, 'owner'))->autonomy);
    }

    public function test_a_company_can_lower_limits_but_not_raise_them(): void
    {
        $organization = $this->tenant();
        $registry = app(AgentRegistry::class);
        $agent = $registry->find('project_brain');
        $version = $agent->currentVersion;

        $this->actingAs($this->member($organization, 'owner'))
            ->patch('/agents/project_brain/settings', ['enabled' => 1, 'autonomy' => 'L0', 'max_steps' => $version->max_steps + 1, 'max_seconds' => 30, 'max_credits' => 1])
            ->assertSessionHasErrors('max_steps');

        $this->settings('project_brain', $organization, ['max_steps' => 9999, 'max_seconds' => 5, 'max_credits' => '9999']);

        $limits = $registry->limits($registry->install($agent, $organization), $version);
        $this->assertSame($version->max_steps, $limits['max_steps']);
        $this->assertSame(5, $limits['max_seconds']);
        $this->assertSame($version->max_credits, $limits['max_credits']);
    }

    public function test_a_switched_off_agent_cannot_run(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->settings('project_brain', $organization, ['enabled' => false]);

        $this->actingAs($this->member($organization, 'owner'))
            ->post('/agents/project_brain/chat', ['message' => 'hello'])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, AgentRun::count());
    }

    public function test_agent_needs_its_own_permission_too(): void
    {
        $organization = $this->tenant();
        $this->connect();

        // A store keeper can use agents, but cannot write daily reports.
        $this->actingAs($this->member($organization, 'store'))->get('/agents/site_reporter/chat')->assertForbidden();
        $this->post('/agents/site_reporter/chat', ['message' => 'hi'])->assertSessionHasErrors('message');
    }

    public function test_runs_are_private_to_the_person_unless_they_manage_agents(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $engineer = $this->member($organization, 'engineer');
        $other = $this->member($organization, 'engineer');
        $run = $this->finishedRun($engineer);

        $this->actingAs($engineer)->get("/agent-runs/{$run->id}")->assertOk();
        $this->actingAs($other)->get("/agent-runs/{$run->id}")->assertNotFound();
        $this->actingAs($other)->get("/agent-runs/{$run->id}/status")->assertNotFound();
        $this->actingAs($other)->post("/agent-runs/{$run->id}/cancel")->assertNotFound();
        $this->actingAs($owner)->get("/agent-runs/{$run->id}")->assertOk();

        $this->actingAs($other)->get('/agent-runs')->assertOk()->assertDontSee('#'.$run->id);
        $this->actingAs($owner)->get('/agent-runs')->assertOk()->assertSee('#'.$run->id);
    }

    public function test_runs_stay_inside_their_company(): void
    {
        $organization = $this->tenant();
        $run = $this->finishedRun($this->member($organization, 'owner'));
        $foreign = $this->member($this->tenant('Rival'), 'owner');

        $this->actingAs($foreign)->get("/agent-runs/{$run->id}")->assertNotFound();
        $this->actingAs($foreign)->post("/agent-runs/{$run->id}/cancel")->assertNotFound();
        $this->actingAs($foreign)->get('/agent-runs')->assertOk()->assertDontSee('#'.$run->id);

        Sanctum::actingAs($foreign);
        $this->getJson("/api/v1/agent-runs/{$run->id}")->assertNotFound();
        $this->postJson("/api/v1/agent-runs/{$run->id}/approve", ['action' => 'approve'])->assertNotFound();
    }

    public function test_chats_are_private_to_their_owner(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization, 'engineer');
        $this->finishedRun($engineer);
        $conversation = AgentConversation::sole();

        $this->actingAs($this->member($organization, 'owner'))->get("/agent-chats/{$conversation->id}")->assertNotFound();
        $this->actingAs($this->member($this->tenant('Rival'), 'owner'))->get("/agent-chats/{$conversation->id}")->assertNotFound();

        Sanctum::actingAs($this->member($organization, 'pm'));
        $this->getJson("/api/v1/agent-chats/{$conversation->id}")->assertNotFound();
    }

    public function test_someone_elses_chat_cannot_be_continued(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization, 'engineer');
        $this->finishedRun($engineer);
        $conversation = AgentConversation::sole();

        $this->actingAs($this->member($organization, 'pm'))
            ->post('/agents/project_brain/chat', ['message' => 'hi', 'conversation_id' => $conversation->id])
            ->assertNotFound();
    }

    public function test_project_and_chat_ids_must_be_in_the_company(): void
    {
        $organization = $this->tenant();
        $foreignProject = $this->makeProject($this->tenant('Rival'));
        $this->connect();

        $this->actingAs($this->member($organization, 'owner'))
            ->post('/agents/project_brain/chat', ['message' => 'hi', 'project_id' => $foreignProject->id])
            ->assertSessionHasErrors('project_id');

        $this->get("/projects/{$foreignProject->id}/ask")->assertNotFound();
    }

    public function test_drafts_cannot_be_edited_across_companies(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $dpr = Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT']);

        $this->actingAs($this->member($this->tenant('Rival'), 'owner'))->get(route('agents.drafts.dpr.edit', $dpr))->assertNotFound();
    }

    public function test_only_some_roles_see_the_credits_screen(): void
    {
        $organization = $this->tenant();

        $this->actingAs($this->member($organization, 'engineer'))->get('/agent-credits')->assertForbidden();
        $this->actingAs($this->member($organization, 'accounts'))->get('/agent-credits')->assertOk();
    }

    public function test_mobile_api_runs_an_agent_and_returns_its_answer(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $user = $this->member($organization, 'engineer');
        Sanctum::actingAs($user);

        $this->fakeClaude([$this->toolUse('project_summary', ['project_id' => $project->id]), $this->finalAnswer('All good.', [['type' => 'project', 'id' => $project->id]])]);

        $response = $this->postJson('/api/v1/agents/project_brain/runs', ['input' => 'status?', 'project_id' => $project->id])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.answer', 'All good.')
            ->assertJsonPath('data.sources.0.type', 'project')
            ->assertJsonPath('data.sources.0.id', $project->id);

        $runId = $response->json('data.id');

        $this->getJson("/api/v1/agent-runs/{$runId}")->assertOk()->assertJsonPath('data.agent', 'project_brain');
        $this->getJson('/api/v1/agent-chats')->assertOk()->assertJsonPath('data.0.agent', 'project_brain');
        $this->getJson('/api/v1/agents')->assertOk()->assertJsonPath('connected', true)->assertJsonPath('data.1.key', 'project_brain');
    }

    public function test_mobile_api_repeat_with_the_same_uuid_returns_the_first_run(): void
    {
        $organization = $this->tenant();
        $this->connect();
        Sanctum::actingAs($this->member($organization, 'owner'));
        $this->fakeClaude([$this->finalAnswer('hi')]);
        $uuid = '5b2f3a2e-9a42-4a57-a3f5-98f1d1f2c301';

        $first = $this->postJson('/api/v1/agents/project_brain/runs', ['input' => 'hello', 'client_uuid' => $uuid])->assertStatus(202)->json('data.id');
        $second = $this->postJson('/api/v1/agents/project_brain/runs', ['input' => 'hello', 'client_uuid' => $uuid])->assertOk()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, AgentRun::count());
    }

    public function test_mobile_api_reports_not_connected(): void
    {
        $organization = $this->tenant();
        Sanctum::actingAs($this->member($organization, 'owner'));

        $this->getJson('/api/v1/agents')->assertOk()->assertJsonPath('connected', false);
        $this->getJson('/api/v1/credits')->assertOk()->assertJsonPath('data.connected', false);
        $this->postJson('/api/v1/agents/project_brain/runs', ['input' => 'hello'])->assertStatus(409)->assertJsonPath('reason', 'not_connected');
        $this->assertSame(0, AgentRun::count());
    }

    public function test_mobile_api_approves_a_waiting_request(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $this->settings('site_reporter', $organization, ['autonomy' => 'L2']);
        $engineer = $this->member($organization, 'engineer');
        $dpr = Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT']);
        $this->fakeClaude([$this->toolUse('dpr_submit_for_approval', ['dpr_id' => $dpr->id]), $this->finalAnswer('Sent.')]);

        $run = $this->runAgent('site_reporter', $engineer, 'send', $project);

        // The engineer has no approval rights, so this must be refused.
        Sanctum::actingAs($engineer);
        $this->postJson("/api/v1/agent-runs/{$run->id}/approve", ['action' => 'approve'])->assertForbidden();
        $this->assertSame('WAITING_APPROVAL', $run->refresh()->status);

        Sanctum::actingAs($this->member($organization, 'pm'));
        $this->postJson("/api/v1/agent-runs/{$run->id}/approve", ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame('IN_REVIEW', $dpr->refresh()->status);
    }

    public function test_agent_request_shows_in_the_approvals_inbox(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $this->settings('site_reporter', $organization, ['autonomy' => 'L2']);
        $dpr = Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT']);
        $this->fakeClaude([$this->toolUse('dpr_submit_for_approval', ['dpr_id' => $dpr->id])]);

        $run = $this->runAgent('site_reporter', $this->member($organization, 'engineer'), 'send', $project);

        $pm = $this->member($organization, 'pm');
        $this->actingAs($pm)->get('/approvals')->assertOk()->assertSee('AI agent request')->assertSee($project->name);
        $this->get("/agent-runs/{$run->id}")->assertOk()->assertSee('Needs a person');
    }

    public function test_public_website_still_says_early_access(): void
    {
        $this->get('/ai')->assertOk()->assertSee('Early access');
    }

    public function test_screens_work_in_hindi_and_marathi(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');

        $this->actingAs($owner)->withSession(['locale' => 'mr'])->get('/agents')->assertOk()->assertSee('AI एजंट कनेक्ट केलेले नाहीत')->assertSee('साइट रिपोर्टर');
        $this->actingAs($owner)->withSession(['locale' => 'hi'])->get('/agents')->assertOk()->assertSee('AI एजेंट कनेक्ट नहीं हैं')->assertSee('मालिक का कोपायलट');
    }
}
