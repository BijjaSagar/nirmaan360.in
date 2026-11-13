<?php

namespace Tests\Feature\OwnerPack;

use App\Models\AgentCreditLedger;
use App\Models\AiTextCall;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agents\CreditService;
use App\Services\DocumentReading\TextCallRunner;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The invented house contractor of the owner-pack tests on the Business plan, with the AI switched
 * on for it: the platform admin has released the readers with a monthly cap on record and a key is
 * saved. The sentences and the translations send nothing a person wrote in a daily report, so the
 * company's consent switch is not needed and stays off.
 *
 * The provider is faked: nothing leaves the test. Calls are run by hand, one step at a time.
 */
abstract class OwnerPackAiTestCase extends TestCase
{
    use BuildsOwnerUpdates, RefreshDatabase;

    /** @var list<array<string, mixed>> Every body sent to the Messages address, in order. */
    protected array $sent = [];

    /** @var list<mixed>|null What the fake provider still has to answer with. */
    protected ?array $replies = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        // A call waits on the queue until a test runs it.
        Queue::fake();

        config([
            'modules.document_reading.ceilings.cache_seconds' => 0,
            'modules.document_reading.typical.cache_seconds' => 0,
        ]);

        $this->site('business');
        $this->switchOn();
    }

    protected function switchOn(): void
    {
        app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'ai_tier_monthly_cap_usd' => '1000', 'anthropic_api_key' => 'test-key-value']);
    }

    /**
     * Answers the Messages address with these replies in turn; the last one repeats.
     *
     * @param  list<mixed>  $replies  A body, or a ready response.
     */
    protected function fakeModel(array $replies): void
    {
        $first = $this->replies === null;
        $this->replies = $replies;

        if (! $first) {
            return;
        }

        Http::fake([
            'api.anthropic.com/v1/messages' => function (Request $request) {
                $this->sent[] = $request->data();
                $reply = count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];

                return is_array($reply) ? Http::response($reply, 200, ['request-id' => 'req_pack_'.count($this->sent)]) : $reply;
            },
        ]);
    }

    /** A complete answer of the model: the form as JSON text, with what it used. */
    protected function answer(array $form, int $input = 1800, int $output = 300): array
    {
        return [
            'id' => 'msg_pack_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5-5',
            'content' => [['type' => 'text', 'text' => json_encode($form, JSON_UNESCAPED_UNICODE)]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $input, 'output_tokens' => $output],
        ];
    }

    /** @param  array<string, string>  $texts  Slot id => text. */
    protected function slots(array $texts): array
    {
        return $this->answer(['slots' => array_map(fn ($id, $text) => ['id' => $id, 'text' => $text], array_keys($texts), $texts)]);
    }

    /** What a worker does with one call. */
    protected function runCall(AiTextCall $call): void
    {
        app(TextCallRunner::class)->run($call->id);
    }

    /** The whole text of the one user message of a sent body. */
    protected function messageOf(array $body): string
    {
        return implode("\n", array_column($body['messages'][0]['content'], 'text'));
    }

    protected function bodyText(array $body): string
    {
        return (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<AgentCreditLedger> What the company was charged for AI work. */
    protected function charges(?Organization $organization = null): array
    {
        return AgentCreditLedger::withoutGlobalScopes()->where('organization_id', ($organization ?? $this->organization)->id)->where('type', 'usage')->get()->all();
    }

    protected function available(?Organization $organization = null): string
    {
        return app(CreditService::class)->wallet($organization ?? $this->organization)->available();
    }

    /** A person of the company holding exactly these permissions. */
    protected function holding(array $permissions, ?Organization $organization = null): User
    {
        $organization ??= $this->organization;
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'name' => 'Role '.uniqid(),
            'permissions' => $permissions,
        ]);
        $user = $this->member($organization, $role->baseRole());
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user;
    }

    protected function calls(): int
    {
        return AiTextCall::withoutGlobalScopes()->count();
    }

    /**
     * A page that waits for the AI loads itself again unless the person is busy in the page itself. The
     * project menu beside it always keeps the group of the page open, so that may not hold it back.
     */
    protected function assertLooksAgainByItself(TestResponse $page): void
    {
        $html = $page->getContent();
        $this->assertMatchesRegularExpression('/<details class="nav-group"[^>]*\sopen[\s>]/', $html, 'The menu keeps the group of the page open.');

        preg_match_all('/<div x-data data-look-again x-init="([^"]*)"/', $html, $scripts);
        $this->assertNotEmpty($scripts[1], 'The page looks again by itself.');

        foreach ($scripts[1] as $script) {
            $script = html_entity_decode($script, ENT_QUOTES);
            $this->assertStringContainsString("const page = \$el.closest('main');", $script);
            $this->assertStringContainsString("page?.querySelector('details[open]')", $script);
            $this->assertStringNotContainsString('document.querySelector', $script);
        }

        $this->assertSame(1, preg_match('/<main\b.*<\/main>/s', $html, $main));
        $this->assertDoesNotMatchRegularExpression('/<details\b[^>]*\sopen[\s>]/', $main[0], 'Nothing in the page itself is open, so it loads again.');
    }
}
