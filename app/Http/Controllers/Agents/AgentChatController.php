<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\AgentRun;
use App\Models\Dpr;
use App\Models\Project;
use App\Models\User;
use App\Services\Agents\AgentOrchestrator;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentRunBlocked;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AgentChatController extends Controller
{
    public function __construct(private AgentRegistry $registry, private AgentOrchestrator $orchestrator) {}

    public function create(Request $request, string $agent): View
    {
        $project = $request->filled('project') ? Project::findOrFail($request->integer('project')) : null;

        return $this->chat($request->user(), $this->registry->find($agent), $project, null);
    }

    /** "Ask my project" is Project Brain, limited to one project. */
    public function ask(Request $request, Project $project): View
    {
        return $this->chat($request->user(), $this->registry->find('project_brain'), $project, null);
    }

    public function show(Request $request, AgentConversation $conversation): View
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);

        return $this->chat($request->user(), $this->registry->find($conversation->agent->key), $conversation->project, $conversation);
    }

    public function send(Request $request, string $agent): RedirectResponse
    {
        $agent = $this->registry->find($agent);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
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
            $run = $this->orchestrator->start($agent, $request->user(), $data['message'], $project, $conversation, $data['client_uuid'] ?? null);
        } catch (AgentRunBlocked $blocked) {
            return back()->withInput()->withErrors(['message' => $blocked->getMessage()]);
        }

        return redirect()->route('agents.chats.show', $run->agent_conversation_id);
    }

    private function chat(User $user, Agent $agent, ?Project $project, ?AgentConversation $conversation): View
    {
        abort_unless($user->hasPermission($agent->currentVersion->permission), 403);

        $runs = $conversation ? $conversation->runs()->get() : collect();

        return view('modules.agents.chat', [
            'agent' => $agent,
            'project' => $project,
            'conversation' => $conversation,
            'runs' => $runs,
            'pending' => $runs->last(fn (AgentRun $run) => ! $run->isFinished()),
            'dprDrafts' => $this->dprDrafts($runs),
            'recent' => AgentConversation::where('user_id', $user->id)
                ->where('agent_id', $agent->id)
                ->when($project, fn ($q) => $q->where('project_id', $project->id))
                ->latest('id')
                ->take(8)
                ->get(),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    /** @param Collection<int,AgentRun> $runs */
    private function dprDrafts(Collection $runs): Collection
    {
        $ids = $runs->flatMap(fn (AgentRun $run) => $run->drafts())->where('type', 'dpr')->pluck('id');

        return $ids->isEmpty() ? collect() : Dpr::whereIn('id', $ids)->get()->keyBy('id');
    }
}
