<?php

namespace App\Http\Controllers\Qhs;

use App\Http\Controllers\Controller;
use App\Models\Ncr;
use App\Models\Project;
use App\Models\User;
use App\Services\Qhs\NcrService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NcrController extends Controller
{
    public function __construct(private NcrService $service) {}

    public function index(Request $request, Project $project): View
    {
        $status = $request->query('status');

        return view('modules.qhs.ncrs.index', [
            'project' => $project,
            'ncrs' => Ncr::with('assignee')->where('project_id', $project->id)
                ->when($status, fn ($query, $value) => $query->where('status', $value))
                ->latest('seq')->paginate(25)->withQueryString(),
            'status' => $status,
            'reworkTotal' => $this->service->reworkTotal($project),
            'openCount' => Ncr::where('project_id', $project->id)->whereIn('status', ['open', 'in_progress'])->count(),
            'sites' => $project->sites,
            'users' => $this->users($request),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $ncr = $this->service->create($project, $request->validate($this->service->rules($project, $request->user())), $request->user());

        return redirect()->route('ncrs.show', $ncr)->with('success', __('NCR raised.'));
    }

    public function show(Request $request, Ncr $ncr): View
    {
        return view('modules.qhs.ncrs.show', [
            'ncr' => $ncr->load('project', 'site', 'raiser', 'assignee', 'attachments.uploader'),
            'project' => $ncr->project,
            'users' => $this->users($request),
        ]);
    }

    public function update(Request $request, Ncr $ncr): RedirectResponse
    {
        $this->service->update($ncr, $request->validate($this->service->updateRules($ncr->project, $request->user())), $request->user());

        return back()->with('success', __('NCR updated.'));
    }

    private function users(Request $request)
    {
        return User::where('organization_id', $request->user()->organization_id)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}
