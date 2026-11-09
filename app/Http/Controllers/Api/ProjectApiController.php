<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PlanLimitReachedException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BoqItemPresenter;
use App\Http\Presenters\ProjectPresenter;
use App\Models\BoqItem;
use App\Models\Project;
use App\Services\BoqService;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectApiController extends Controller
{
    public function __construct(private ProjectService $projects, private BoqService $boq) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Project::whereIn('status', ['planning', 'active'])->orderBy('name')->get()->map(ProjectPresenter::present(...)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $uuid = $request->validate(['client_uuid' => ['nullable', 'uuid']])['client_uuid'] ?? null;

        // Before the full rules: a repeat's own code would fail the unique check.
        if ($uuid && ($existing = $this->projects->findByClientUuid($user->organization_id, $uuid))) {
            return response()->json(['data' => ProjectPresenter::present($existing)]);
        }

        try {
            $project = $this->projects->create($request->validate($this->projects->quickRules($user)), $user);
        } catch (PlanLimitReachedException $exception) {
            throw ValidationException::withMessages(['plan' => $exception->getMessage()]);
        }

        return response()->json(['data' => ProjectPresenter::present($project)], $project->wasRecentlyCreated ? 201 : 200);
    }

    public function boq(Project $project): JsonResponse
    {
        return response()->json([
            'data' => BoqItem::withLedger()->with('section')->where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get()
                ->map(BoqItemPresenter::present(...)),
        ]);
    }

    public function storeBoqItem(Request $request, Project $project): JsonResponse
    {
        $item = $this->boq->create($project, $request->validate([
            ...$this->boq->rules(),
            'code' => ['nullable', 'string', 'max:32'],
            'client_uuid' => ['nullable', 'uuid'],
        ]));

        // Read back with its ledger: a repeat may come after measurements were booked.
        $line = BoqItem::withTrashed()->withLedger()->with('section')->findOrFail($item->id);

        return response()->json(['data' => BoqItemPresenter::present($line)], $item->wasRecentlyCreated ? 201 : 200);
    }
}
