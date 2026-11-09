<?php

namespace App\Http\Controllers\Qhs;

use App\Http\Controllers\Controller;
use App\Http\Presenters\InspectionPresenter;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\Project;
use App\Services\Qhs\ChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mobile endpoints for checklists. A repeated client_uuid returns the first inspection and applies nothing. */
class InspectionApiController extends Controller
{
    public function __construct(private ChecklistService $service) {}

    public function templates(): JsonResponse
    {
        return response()->json([
            'data' => ChecklistTemplate::where('is_active', true)->orderBy('name')->get()->map(InspectionPresenter::template(...))->all(),
        ]);
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        return response()->json(
            Inspection::where('project_id', $project->id)
                ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
                ->when($request->query('category'), fn ($query, $category) => $query->where('category', $category))
                ->latest('inspected_on')->latest('id')
                ->paginate(25)->withQueryString()
                ->through(InspectionPresenter::inspection(...))
        );
    }

    /** Start an inspection from a template. Results by item position and "complete" may come in the same call. */
    public function store(Request $request, Project $project): JsonResponse
    {
        $inspection = $this->service->start($project, $request->validate($this->service->startRules($project, $request->user())), $request->user());

        return response()->json(['data' => InspectionPresenter::detail($inspection->load('items'))], $inspection->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Inspection $inspection): JsonResponse
    {
        return response()->json(['data' => InspectionPresenter::detail($inspection->load('items'))]);
    }

    public function update(Request $request, Inspection $inspection): JsonResponse
    {
        $this->service->save($inspection, $request->validate($this->service->saveRules($inspection))['items']);

        return response()->json(['data' => InspectionPresenter::detail($inspection->refresh()->load('items'))]);
    }

    public function complete(Request $request, Inspection $inspection): JsonResponse
    {
        $this->service->complete($inspection, $request->user());

        return response()->json(['data' => InspectionPresenter::detail($inspection->refresh()->load('items'))]);
    }
}
