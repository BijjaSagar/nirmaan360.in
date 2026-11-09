<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentActionRequest;
use App\Models\AgentConversation;
use App\Models\AgentRun;
use App\Models\Project;
use App\Services\Agents\AgentOrchestrator;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentRunBlocked;
use App\Services\Agents\CreditService;
use App\Services\Agents\ModelGateway;
use App\Services\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The same agents for the mobile app. */
class AgentApiController extends Controller
{
    public function __construct(
        private AgentRegistry $registry,
        private AgentOrchestrator $orchestrator,
        private ModelGateway $gateway,
        private CreditService $credits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $organization = $user->organization;

        return response()->json([
            'connected' => $this->gateway->isConnected(),
            'data' => $this->registry->all()->map(fn ($agent) => [
                'key' => $agent->key,
                'name' => $agent->name,
                'description' => $agent->description,
                'autonomy' => $this->registry->autonomy($install = $this->registry->install($agent, $organization), $agent->currentVersion),
                'enabled' => $this->registry->isEnabled($install),
                'can_use' => $user->hasPermission($agent->currentVersion->permission),
            ])->all(),
        ]);
    }

    public function start(Request $request, string $agent): JsonResponse
    {
        $agent = $this->registry->find($agent);

        $data = $request->validate([
            'input' => ['required', 'string', 'max:4000'],
            'project_id' => ['bail', 'nullable', 'integer', org_exists('projects')],
            'conversation_id' => ['bail', 'nullable', 'integer', org_exists('agent_conversations')],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        $conversation = null;

        if (isset($data['conversation_id'])) {
            $conversation = AgentConversation::findOrFail($data['conversation_id']);
            abort_unless($conversation->user_id === $request->user()->id && $conversation->agent_id === $agent->id, 404);
        }

        $project = $conversation?->project ?? (isset($data['project_id']) ? Project::findOrFail($data['project_id']) : null);

        try {
            $run = $this->orchestrator->start($agent, $request->user(), $data['input'], $project, $conversation, $data['client_uuid'] ?? null, 'mobile');
        } catch (AgentRunBlocked $blocked) {
            return response()->json(['message' => $blocked->getMessage(), 'reason' => $blocked->reason], match ($blocked->reason) {
                'permission', 'plan' => 403,
                'no_credits' => 402,
                default => 409,
            });
        }

        return response()->json(['data' => $this->present($run)], $run->wasRecentlyCreated ? 202 : 200);
    }

    public function run(Request $request, AgentRun $run): JsonResponse
    {
        $this->assertVisible($request, $run);

        return response()->json(['data' => $this->present($run)]);
    }

    public function cancel(Request $request, AgentRun $run): JsonResponse
    {
        $this->assertVisible($request, $run);

        $this->orchestrator->cancel($run);

        return response()->json(['data' => $this->present($run->refresh())]);
    }

    /** Decides the run's waiting request through the normal approval rules. */
    public function approve(Request $request, AgentRun $run, ApprovalService $approvals): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'return', 'reject'])],
            'remark' => ['nullable', 'string', 'max:1000'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        $pending = AgentActionRequest::where('agent_run_id', $run->id)->where('status', 'IN_REVIEW')->with('currentApproval')->orderBy('id')->first();

        abort_if($pending === null, 409, __('This run is not waiting for approval.'));

        $approvals->act($pending->currentApproval, $request->user(), $data['action'], $data['remark'] ?? null, $data['client_uuid'] ?? null);

        return response()->json(['data' => $this->present($run->refresh())]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $conversations = AgentConversation::with('agent')->where('user_id', $request->user()->id)->latest('id')->paginate(25);

        return response()->json($conversations->through(fn (AgentConversation $conversation) => [
            'id' => $conversation->id,
            'agent' => $conversation->agent->key,
            'project_id' => $conversation->project_id,
            'title' => $conversation->displayTitle(),
            'created_at' => $conversation->created_at?->toIso8601String(),
        ]));
    }

    public function conversation(Request $request, AgentConversation $conversation): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'agent' => $conversation->agent->key,
                'project_id' => $conversation->project_id,
                'title' => $conversation->displayTitle(),
                'runs' => $conversation->runs()->get()->map($this->present(...))->all(),
            ],
        ]);
    }

    public function credits(Request $request): JsonResponse
    {
        $wallet = $this->credits->wallet($request->user()->organization);

        return response()->json(['data' => [
            'connected' => $this->gateway->isConnected(),
            'available' => $wallet->available(),
            'included' => $wallet->included_balance,
            'purchased' => $wallet->purchased_balance,
        ]]);
    }

    private function present(AgentRun $run): array
    {
        return [
            'id' => $run->id,
            'agent' => $run->agent->key,
            'conversation_id' => $run->agent_conversation_id,
            'project_id' => $run->project_id,
            'status' => $run->status,
            'limit_reason' => $run->limit_reason,
            'error' => $run->error,
            'input' => $run->input,
            'answer' => $run->answer,
            'clarifying_question' => $run->output['clarifying_question'] ?? null,
            'decisions' => $run->output['decisions'] ?? [],
            'sources' => $run->sources ?? [],
            'drafts' => $run->drafts(),
            'model' => $run->model,
            'steps' => $run->steps_count,
            'input_tokens' => $run->input_tokens,
            'output_tokens' => $run->output_tokens,
            'credits_used' => $run->credits_used,
            'created_at' => $run->created_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    private function assertVisible(Request $request, AgentRun $run): void
    {
        abort_unless($run->isVisibleTo($request->user()), 404);
    }
}
