<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Services\BillingExtras\IssuePhotoService;
use App\Services\IssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class IssueController extends Controller
{
    public function __construct(private IssueService $service) {}

    public function index(Request $request, Project $project): View
    {
        $status = $request->query('status');

        return view('issues.index', [
            'project' => $project,
            'issues' => $project->issues()->with('assignee', 'photos')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString(),
            'users' => $this->users($request),
            'status' => $status,
        ]);
    }

    public function store(Request $request, Project $project, IssuePhotoService $photos): RedirectResponse
    {
        $data = $request->validate([...$this->service->rules($project, $request->user()), ...$photos->listRules()]);

        $issue = $this->service->create($project, Arr::except($data, 'photos'), $request->user());

        if ($issue->wasRecentlyCreated) {
            foreach ($request->file('photos', []) as $photo) {
                $photos->add($issue, $photo, ['kind' => 'before'], $request->user());
            }
        }

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
