<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\IssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IssueController extends Controller
{
    public function __construct(private IssueService $service) {}

    public function index(Request $request, Project $project): View
    {
        $status = $request->query('status');

        return view('issues.index', [
            'project' => $project,
            'issues' => $project->issues()->with('assignee')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'users' => $this->users($request),
            'status' => $status,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->service->create($project, $request->validate($this->service->rules($project, $request->user())), $request->user());

        return back()->with('success', __('Issue raised.'));
    }

    public function update(Request $request, Issue $issue): RedirectResponse
    {
        $this->service->update($issue, $request->validate($this->service->statusRules($request->user())));

        return back()->with('success', __('Issue updated.'));
    }

    private function users(Request $request)
    {
        return User::where('organization_id', $request->user()->organization_id)->where('is_active', true)->orderBy('name')->get();
    }
}
