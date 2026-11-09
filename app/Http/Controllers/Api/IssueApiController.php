<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\IssuePresenter;
use App\Models\Issue;
use App\Models\Project;
use App\Services\IssueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IssueApiController extends Controller
{
    public function __construct(private IssueService $service) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        return response()->json(
            $project->issues()
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString()
                ->through(IssuePresenter::present(...))
        );
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $issue = $this->service->create($project, $request->validate($this->service->rules($project, $request->user())), $request->user());

        return response()->json(['data' => IssuePresenter::present($issue)], $issue->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Issue $issue): JsonResponse
    {
        $rules = $this->service->statusRules($request->user());
        $data = $request->validate([...$rules, 'status' => ['sometimes', ...$rules['status']]]);

        return response()->json(['data' => IssuePresenter::present($this->service->update($issue, $data))]);
    }
}
