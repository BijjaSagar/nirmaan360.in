<?php

namespace Tests\Feature\Agents;

use App\Models\AgentCreditWallet;
use App\Models\AgentInstall;
use App\Models\AgentRun;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Services\Agents\AgentOrchestrator;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\CreditService;
use App\Services\Agents\ModelGateway;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\BuildsTenant;
use Tests\TestCase;

abstract class AgentTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    /** @var list<array<string,mixed>> Bodies sent to the model, not counting chat titles. */
    protected array $sentToModel = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    protected function connect(): void
    {
        app(PlatformSettings::class)->set(['anthropic_api_key' => 'test-key-value']);
    }

    /** @param list<array<string,mixed>|callable> $replies Given out in order. After the last one, the final reply repeats. */
    protected function fakeClaude(array $replies): void
    {
        Http::fake(['api.anthropic.com/*' => function (Request $request) use (&$replies) {
            $body = $request->data();

            if ($body['model'] === ModelGateway::CHEAP_MODEL) {
                return Http::response($this->message([['type' => 'text', 'text' => 'Slab work today']], 'end_turn', 40, 8));
            }

            $this->sentToModel[] = $body;
            $reply = count($replies) > 1 ? array_shift($replies) : $replies[0];

            return Http::response(is_callable($reply) ? $reply($body) : $reply);
        }]);
    }

    protected function message(array $content, string $stopReason, int $input = 1000, int $output = 100): array
    {
        return [
            'id' => 'msg_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'content' => $content,
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
        ];
    }

    protected function toolUse(string $name, array $input, string $id = 'toolu_1', int $inputTokens = 1000, int $outputTokens = 100): array
    {
        return $this->message([['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input]], 'tool_use', $inputTokens, $outputTokens);
    }

    protected function finalAnswer(string $answer, array $sources = [], ?string $question = null, array $extra = []): array
    {
        return $this->message([[
            'type' => 'text',
            'text' => json_encode(['answer' => $answer, 'clarifying_question' => $question, 'sources' => $sources, ...$extra], JSON_UNESCAPED_UNICODE),
        ]], 'end_turn');
    }

    protected function site(Project $project, string $name = 'Tower A'): Site
    {
        return Site::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => $name]);
    }

    protected function settings(string $agent, Organization $organization, array $values): void
    {
        AgentInstall::withoutGlobalScopes()->updateOrCreate(
            ['organization_id' => $organization->id, 'agent_id' => app(AgentRegistry::class)->find($agent)->id],
            $values,
        );
    }

    protected function setCredits(Organization $organization, string $included, string $purchased = '0'): void
    {
        app(CreditService::class)->wallet($organization);

        AgentCreditWallet::withoutGlobalScopes()->where('organization_id', $organization->id)
            ->update(['included_balance' => $included, 'purchased_balance' => $purchased]);
    }

    protected function runAgent(string $agent, User $user, string $input = 'hello', ?Project $project = null): AgentRun
    {
        return app(AgentOrchestrator::class)->start(app(AgentRegistry::class)->find($agent), $user, $input, $project);
    }
}
