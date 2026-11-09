<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentRun;
use App\Models\Dpr;
use App\Services\Agents\AgentOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AgentRunController extends Controller
{
    public function index(Request $request): View
    {
        return view('modules.agents.runs.index', [
            'runs' => AgentRun::with('agent', 'actor', 'project')->visibleTo($request->user())->latest('id')->paginate(25),
        ]);
    }

    public function show(Request $request, AgentRun $run): View
    {
        $this->assertVisible($request, $run);

        $run->load('agent', 'agentVersion', 'actor', 'project', 'steps.toolCalls', 'actionRequests.approvals.steps.user');
        $dprIds = collect($run->drafts())->where('type', 'dpr')->pluck('id');

        return view('modules.agents.runs.show', [
            'run' => $run,
            'project' => $run->project,
            'dprDrafts' => $dprIds->isEmpty() ? collect() : Dpr::whereIn('id', $dprIds)->get()->keyBy('id'),
        ]);
    }

    /** What the page polls while a run is working. */
    public function status(Request $request, AgentRun $run): JsonResponse
    {
        $this->assertVisible($request, $run);

        return response()->json(['status' => $run->status, 'phase' => self::phase($run)]);
    }

    public function cancel(Request $request, AgentRun $run, AgentOrchestrator $orchestrator): RedirectResponse
    {
        $this->assertVisible($request, $run);

        $orchestrator->cancel($run);

        return back()->with('success', __('Run cancelled.'));
    }

    public static function phase(AgentRun $run): string
    {
        return match (true) {
            $run->isFinished() => 'finished',
            $run->status === AgentRun::WAITING_APPROVAL => 'waiting',
            default => 'working',
        };
    }

    private function assertVisible(Request $request, AgentRun $run): void
    {
        abort_unless($run->isVisibleTo($request->user()), 404);
    }
}
