<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PlanLimitReachedException;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BoqItemPresenter;
use App\Http\Presenters\ContractorControlPresenter;
use App\Http\Presenters\ProjectPresenter;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use App\Services\BoqService;
use App\Services\BoqValues;
use App\Services\ContractorControl\BoqLineTenders;
use App\Services\ProjectService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectApiController extends Controller
{
    public function __construct(private ProjectService $projects, private BoqService $boq, private BoqLineTenders $lineTenders) {}

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

    /** A project as the edit screen needs it, with the phases of its value. */
    public function show(Request $request, Project $project): JsonResponse
    {
        return response()->json(['data' => $this->detail($project, $request->user())]);
    }

    /** Changes the fields sent and nothing else. Answers as show() does. */
    public function update(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate($this->projects->updateRules($user, $project), $this->projects->advanceMessages());

        if (isset($data['deduction_order'])) {
            $data['deduction_order'] = explode(',', $data['deduction_order']);
        }

        $project = $this->projects->update($project, $data, $user);

        return response()->json(['data' => $this->detail($project, $user)]);
    }

    private function detail(Project $project, User $user): array
    {
        return ProjectPresenter::detail($project, $user, $this->projects->phasesOf($project), $this->projects->advancesOf($project));
    }

    /**
     * Each line also carries the marker the web BOQ table shows: how many tenders it is in and how many
     * quotes came in, with the lowest rate for money.view. Null for a line with no tender, and on every
     * line for someone who may not see tenders.
     */
    public function boq(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $tenders = ContractorControlPresenter::tenderMarkers($this->lineTenders->markersFor($user, $project), Money::visible($user));

        $items = BoqItem::withLedger()->with('section')->where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get();

        return response()->json([
            'data' => $items->map(fn (BoqItem $item) => [...BoqItemPresenter::present($item), 'tenders' => $tenders[$item->id] ?? null]),
            // The same totals as the web BOQ page. Money, so null to anyone who may not see it.
            'totals' => Money::visible($user) ? BoqItemPresenter::totals(BoqValues::of($items)) : null,
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
