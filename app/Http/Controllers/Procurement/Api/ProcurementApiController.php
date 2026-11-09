<?php

namespace App\Http\Controllers\Procurement\Api;

use App\Http\Controllers\Controller;
use App\Models\Grn;
use App\Models\MaterialIssue;
use App\Models\Project;
use App\Services\Procurement\GrnService;
use App\Services\Procurement\MaterialIssueService;
use App\Services\Procurement\ProcurementSyncService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Field app endpoints for goods receipts and issues. Every write needs a client_uuid. */
class ProcurementApiController extends Controller
{
    private const RECENT = 50;

    public function __construct(
        private ProcurementSyncService $sync,
        private GrnService $grnService,
        private MaterialIssueService $issueService,
    ) {}

    public function snapshot(Project $project): JsonResponse
    {
        return response()->json($this->sync->snapshot($project));
    }

    public function push(Request $request): JsonResponse
    {
        // Only the batch shape is checked here: a bad item is reported in its own result.
        $items = $request->validate(['items' => ['required', 'array', 'max:200']])['items'];

        return response()->json(['results' => $this->sync->push($items, $request->user())]);
    }

    public function grns(Project $project): JsonResponse
    {
        $grns = Grn::with('items')->where('project_id', $project->id)->latest('id')->take(self::RECENT)->get();

        return response()->json(['data' => $grns->map(fn (Grn $grn) => $this->sync->presentGrn($grn))]);
    }

    public function storeGrn(Request $request, Project $project): JsonResponse
    {
        $uuid = $request->validate(['client_uuid' => ['required', 'uuid']])['client_uuid'];

        // Before the field rules, because a replay can be valid even after its PO has closed.
        $grn = $this->grnService->find($project->organization_id, $uuid)
            ?? $this->grnService->create($project, $request->validate($this->grnService->rules($project)), $request->user());

        $this->assertSameProject($grn, $project);

        return response()->json(['data' => $this->sync->presentGrn($grn->load('items'))], $grn->wasRecentlyCreated ? 201 : 200);
    }

    public function issues(Project $project): JsonResponse
    {
        $issues = MaterialIssue::with('items')->where('project_id', $project->id)->latest('id')->take(self::RECENT)->get();

        return response()->json(['data' => $issues->map(fn (MaterialIssue $issue) => $this->sync->presentIssue($issue))]);
    }

    public function storeIssue(Request $request, Project $project): JsonResponse
    {
        $uuid = $request->validate(['client_uuid' => ['required', 'uuid']])['client_uuid'];

        $issue = $this->issueService->find($project->organization_id, $uuid)
            ?? $this->issueService->create($project, $request->validate($this->issueService->rules($project)), $request->user());

        $this->assertSameProject($issue, $project);

        return response()->json(['data' => $this->sync->presentIssue($issue->load('items'))], $issue->wasRecentlyCreated ? 201 : 200);
    }

    private function assertSameProject(Model $row, Project $project): void
    {
        if ((int) $row->project_id !== $project->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This record belongs to another project.')]);
        }
    }
}
