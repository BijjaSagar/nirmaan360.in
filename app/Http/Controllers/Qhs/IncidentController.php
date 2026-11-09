<?php

namespace App\Http\Controllers\Qhs;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SafetyIncident;
use App\Services\Qhs\SafetyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class IncidentController extends Controller
{
    public function __construct(private SafetyService $service) {}

    public function index(Request $request, Project $project): View
    {
        $kind = $request->query('kind');

        return view('modules.qhs.safety.index', [
            'project' => $project,
            'incidents' => SafetyIncident::where('project_id', $project->id)
                ->when($kind, fn ($query, $value) => $query->where('kind', $value))
                ->latest('occurred_at')->paginate(25)->withQueryString(),
            'kind' => $kind,
            'counts' => [
                'incidents' => SafetyIncident::where('project_id', $project->id)->where('kind', 'incident')->count(),
                'near_misses' => SafetyIncident::where('project_id', $project->id)->where('kind', 'near_miss')->count(),
                'open' => SafetyIncident::where('project_id', $project->id)->where('status', '!=', 'closed')->count(),
                'lost_days' => (int) SafetyIncident::where('project_id', $project->id)->sum('lost_days'),
            ],
            'sites' => $project->sites,
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $incident = $this->service->report($project, $request->validate($this->service->incidentRules($project)), $request->user());

        return redirect()->route('incidents.show', $incident)->with('success', __('Report saved.'));
    }

    public function show(SafetyIncident $incident): View
    {
        return view('modules.qhs.safety.show', [
            'incident' => $incident->load('project', 'site', 'reporter', 'attachments.uploader'),
            'project' => $incident->project,
        ]);
    }

    public function update(Request $request, SafetyIncident $incident): RedirectResponse
    {
        $this->service->update($incident, $request->validate($this->service->updateRules()), $request->user());

        return back()->with('success', __('Report updated.'));
    }
}
