<?php

namespace Tests\Feature\Agents;

use App\Jobs\RunAgentStep;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Agents\CostMeter;
use App\Services\Agents\ModelGateway;
use App\Services\Agents\ModelGatewayException;
use App\Support\PlatformSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** E5-01 and the "not connected" rule. */
class GatewayAndAdminTest extends AgentTestCase
{
    private function admin(): User
    {
        $user = $this->member($this->tenant(), 'owner');
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    public function test_gateway_reports_not_connected_without_a_key_and_makes_no_call(): void
    {
        Http::fake();
        $gateway = app(ModelGateway::class);

        $this->assertFalse($gateway->isConnected());

        try {
            $gateway->send(['model' => 'claude-sonnet-5-5']);
            $this->fail('It should not send without a key.');
        } catch (ModelGatewayException $exception) {
            $this->assertSame(ModelGatewayException::NOT_CONNECTED, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_gateway_sends_the_key_and_api_version(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response($this->message([['type' => 'text', 'text' => 'hi']], 'end_turn'))]);

        $response = app(ModelGateway::class)->send(['model' => 'claude-sonnet-5-5', 'max_tokens' => 10, 'messages' => []]);

        $this->assertSame('hi', $response->text());
        $this->assertSame(1000, $response->usage['input']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-key-value')
            && $request->hasHeader('anthropic-version', '2026-10-21'));
    }

    /** @return list<object> The raw request bodies, decoded so that {} and [] stay different. */
    private function captureBodies(array $replies): array
    {
        $bodies = [];

        Http::fake(['api.anthropic.com/*' => function (Request $request) use (&$replies, &$bodies) {
            if ($request['model'] === ModelGateway::CHEAP_MODEL) {
                return Http::response($this->message([['type' => 'text', 'text' => 'A title']], 'end_turn'));
            }

            $bodies[] = json_decode($request->body());

            return Http::response(array_shift($replies));
        }]);

        $this->runAgent('owners_copilot', $this->member($this->tenant(), 'owner'));

        return $bodies;
    }

    public function test_a_tool_with_no_arguments_is_described_and_replayed_with_objects(): void
    {
        $this->connect();

        $bodies = $this->captureBodies([
            $this->toolUse('company_overview', []),
            $this->finalAnswer('Brief.', extra: ['decisions' => []]),
        ]);

        $overview = collect($bodies[0]->tools)->firstWhere('name', 'company_overview');
        $this->assertIsObject($overview->input_schema->properties);

        $this->assertSame('tool_use', $bodies[1]->messages[1]->content[0]->type);
        $this->assertIsObject($bodies[1]->messages[1]->content[0]->input);
    }

    public function test_thinking_blocks_go_back_to_the_model_unchanged(): void
    {
        $this->connect();
        $thinking = ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig_abc123'];

        $bodies = $this->captureBodies([
            $this->message([$thinking, ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'company_overview', 'input' => []]], 'tool_use'),
            $this->finalAnswer('Brief.', extra: ['decisions' => []]),
        ]);

        $replayed = $bodies[1]->messages[1]->content;
        $this->assertSame('thinking', $replayed[0]->type);
        $this->assertSame('sig_abc123', $replayed[0]->signature);
        $this->assertSame('tool_use', $replayed[1]->type);
    }

    public function test_requests_avoid_what_current_models_reject(): void
    {
        $this->connect();

        $bodies = $this->captureBodies([$this->finalAnswer('Brief.', extra: ['decisions' => []])]);

        foreach (['temperature', 'top_p', 'top_k', 'tool_choice', 'thinking'] as $field) {
            $this->assertFalse(property_exists($bodies[0], $field), $field);
        }

        $this->assertSame('json_schema', $bodies[0]->output_config->format->type);
        $this->assertSame('ephemeral', $bodies[0]->system[0]->cache_control->type);
    }

    public function test_a_rejected_key_is_reported_without_repeating_it(): void
    {
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'bad key test-key-value']], 401)]);

        try {
            app(ModelGateway::class)->send(['model' => 'claude-sonnet-5-5']);
            $this->fail('A rejected key must raise.');
        } catch (ModelGatewayException $exception) {
            $this->assertSame(ModelGatewayException::REJECTED_KEY, $exception->reason);
            $this->assertStringNotContainsString('test-key-value', $exception->getMessage());
        }
    }

    public function test_busy_responses_are_retried_once(): void
    {
        $this->connect();
        Http::fakeSequence('api.anthropic.com/*')
            ->push(['error' => 'overloaded'], 529)
            ->push($this->message([['type' => 'text', 'text' => 'ok']], 'end_turn'));

        $this->assertSame('ok', app(ModelGateway::class)->send(['model' => 'claude-sonnet-5-5'])->text());
        Http::assertSentCount(2);
    }

    public function test_cost_is_exact_for_each_model(): void
    {
        $meter = app(CostMeter::class);

        $this->assertSame('0.012000', $meter->usd('claude-sonnet-5-5', ['input' => 1000, 'output' => 1000, 'cache_read' => 0, 'cache_write' => 0]));
        $this->assertSame('0.024000', $meter->usd('claude-opus-5-5', ['input' => 1000, 'output' => 1000, 'cache_read' => 0, 'cache_write' => 0]));
        $this->assertSame('0.000600', $meter->usd('claude-haiku-5-5', ['input' => 1000, 'output' => 1000, 'cache_read' => 0, 'cache_write' => 0]));
        $this->assertSame('0.002600', $meter->usd('claude-sonnet-5-5', ['input' => 0, 'output' => 0, 'cache_read' => 1000, 'cache_write' => 1000]));
    }

    public function test_credits_follow_the_rate_and_margin_and_round_up(): void
    {
        $meter = app(CostMeter::class);

        // 0.012 × 90 × 1.30 = 1.404
        $this->assertSame('1.4040', $meter->credits('0.012000'));
        $this->assertSame('0.0002', $meter->credits('0.000001'), 'A sliver of use still costs something.');
        $this->assertSame('0.0000', $meter->credits('0.000000'));

        app(PlatformSettings::class)->set(['ai_usd_inr' => '100', 'ai_margin' => '2']);
        $this->assertSame('2.4000', $meter->credits('0.012000'));
    }

    public function test_usage_is_metered_per_company(): void
    {
        $first = $this->tenant('First');
        $second = $this->tenant('Second');
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('ok')]);

        $this->runAgent('project_brain', $this->member($first, 'owner'));
        $this->runAgent('project_brain', $this->member($first, 'owner'));
        $this->runAgent('project_brain', $this->member($second, 'owner'));

        $meter = app(CostMeter::class);
        $from = now()->startOfMonth();

        $this->assertSame(2, $meter->usage($first, $from)['claude-sonnet-5-5']['calls']);
        $this->assertSame(1, $meter->usage($second, $from)['claude-sonnet-5-5']['calls']);
        $this->assertSame(2000, $meter->usage($first, $from)['claude-sonnet-5-5']['input']);
        $this->assertSame('0.006000', $meter->usage($first, $from)['claude-sonnet-5-5']['cost_usd']);
    }

    public function test_every_agent_screen_says_not_connected(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);

        foreach (['/agents', '/agents/site_reporter', '/agents/site_reporter/chat', '/agent-runs', '/agent-credits', "/projects/{$project->id}/ask"] as $screen) {
            $this->get($screen)->assertOk()->assertSee('not connected');
        }
    }

    public function test_run_screen_says_not_connected_after_the_key_is_removed(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('ok')]);
        $run = $this->runAgent('project_brain', $owner);

        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);

        $this->actingAs($owner)->get("/agent-runs/{$run->id}")->assertOk()->assertSee('not connected');
    }

    public function test_connected_screens_do_not_say_not_connected(): void
    {
        $organization = $this->tenant();
        $this->connect();

        $this->actingAs($this->member($organization, 'owner'))->get('/agents')->assertOk()->assertDontSee('not connected');
    }

    public function test_no_run_can_start_without_a_key(): void
    {
        $organization = $this->tenant();
        Http::fake();

        $this->actingAs($this->member($organization, 'owner'))
            ->post('/agents/project_brain/chat', ['message' => 'hello'])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, AgentRun::count());
        Http::assertNothingSent();
    }

    public function test_a_queued_run_fails_cleanly_if_the_key_is_removed_before_it_starts(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');
        $this->connect();
        Queue::fake();

        $run = $this->runAgent('project_brain', $owner);
        $this->assertSame('QUEUED', $run->status);

        app(PlatformSettings::class)->set(['anthropic_api_key' => null]);
        Http::fake();

        (new RunAgentStep($run->id))->handle();

        $this->assertSame('FAILED', $run->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_key_is_encrypted_and_never_shown(): void
    {
        $this->actingAs($this->admin());

        $this->patch('/admin/ai-agents', ['anthropic_api_key' => 'sk-ant-test-secret', 'ai_usd_inr' => '90', 'ai_margin' => '1.3'])->assertSessionHasNoErrors();

        $this->assertTrue(app(ModelGateway::class)->isConnected());
        $this->assertNotSame('sk-ant-test-secret', PlatformSetting::find('anthropic_api_key')->value);
        $this->get('/admin/ai-agents')->assertOk()->assertSee('Connected')->assertDontSee('sk-ant-test-secret');

        $audit = AuditLog::where('action', 'platform.settings_changed')->latest('id')->first();
        $this->assertStringNotContainsString('sk-ant-test-secret', json_encode($audit->new_values));
    }

    public function test_blank_key_keeps_the_saved_one_and_clear_removes_it(): void
    {
        $this->actingAs($this->admin());
        $this->patch('/admin/ai-agents', ['anthropic_api_key' => 'sk-ant-test-secret', 'ai_usd_inr' => '90', 'ai_margin' => '1.3']);

        $this->patch('/admin/ai-agents', ['anthropic_api_key' => '', 'ai_usd_inr' => '91', 'ai_margin' => '1.3'])->assertSessionHasNoErrors();
        $this->assertTrue(app(ModelGateway::class)->isConnected());

        $this->patch('/admin/ai-agents', ['anthropic_api_key' => '', 'clear_key' => '1', 'ai_usd_inr' => '91', 'ai_margin' => '1.3']);
        $this->assertFalse(app(ModelGateway::class)->isConnected());
    }

    public function test_key_test_reports_a_rejected_key(): void
    {
        $this->actingAs($this->admin());
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response([], 401)]);

        $this->post('/admin/ai-agents/test')->assertSessionHasErrors('anthropic_api_key');
    }

    public function test_key_test_reports_a_working_key(): void
    {
        $this->actingAs($this->admin());
        $this->connect();
        Http::fake(['api.anthropic.com/*' => Http::response(['data' => []])]);

        $this->post('/admin/ai-agents/test')->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    public function test_company_owners_cannot_reach_the_admin_screen(): void
    {
        $this->actingAs($this->member($this->tenant(), 'owner'));

        $this->get('/admin/ai-agents')->assertNotFound();
        $this->patch('/admin/ai-agents', ['anthropic_api_key' => 'x', 'ai_usd_inr' => '90', 'ai_margin' => '1.3'])->assertNotFound();
        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_admin_sees_cost_by_company_and_customers_do_not(): void
    {
        $organization = $this->tenant('Acme Infra');
        $owner = $this->member($organization, 'owner');
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('ok')]);
        $this->runAgent('project_brain', $owner);

        $this->assertSame(2, AgentStep::withoutGlobalScopes()->count());

        $this->actingAs($this->admin())->get('/admin/ai-agents')->assertOk()->assertSee('Acme Infra')->assertSee('0.00');

        $this->actingAs($owner)->get('/agent-credits')->assertOk()->assertDontSee('Cost (US$)');
    }
}
