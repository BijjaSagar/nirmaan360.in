<?php

namespace App\Http\Controllers;

use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class IssueController extends Controller
{
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
        Issue::create([
            ...$request->validate([
                'title' => ['required', 'string', 'max:160'],
                'description' => ['nullable', 'string', 'max:5000'],
                'priority' => ['required', Rule::in(config('buildos.issue_priorities'))],
                'location_note' => ['nullable', 'string', 'max:160'],
                'assigned_to' => ['nullable', $this->sameOrganizationUser($request)],
                'due_date' => ['nullable', 'date'],
            ]),
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'raised_by' => $request->user()->id,
        ]);

        return back()->with('success', __('Issue raised.'));
    }

    public function update(Request $request, Issue $issue): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(config('buildos.issue_statuses'))],
            'assigned_to' => ['nullable', $this->sameOrganizationUser($request)],
        ]);

        $issue->update([
            ...$data,
            'resolved_at' => in_array($data['status'], ['resolved', 'closed'], true) ? ($issue->resolved_at ?? now()) : null,
        ]);

        return back()->with('success', __('Issue updated.'));
    }

    private function users(Request $request)
    {
        return User::where('organization_id', $request->user()->organization_id)->where('is_active', true)->orderBy('name')->get();
    }

    private function sameOrganizationUser(Request $request)
    {
        return Rule::exists('users', 'id')->where('organization_id', $request->user()->organization_id);
    }
}
