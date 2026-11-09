<?php

namespace App\Http\Controllers\SiteAlerts;

use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\Project;
use App\Services\SiteAlerts\AlertEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        $status = $request->query('status') === Alert::RESOLVED ? Alert::RESOLVED : Alert::OPEN;

        $alerts = Alert::where('project_id', $project->id)
            ->visibleTo($request->user())
            ->where('status', $status)
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->latest($status === Alert::OPEN ? 'first_seen_at' : 'resolved_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('modules.site_alerts.alerts', [
            'project' => $project,
            'alerts' => $alerts,
            'status' => $status,
            'openCount' => Alert::where('project_id', $project->id)->visibleTo($request->user())->open()->count(),
        ]);
    }

    /** Open alerts across every project this person can see. */
    public function company(Request $request): View
    {
        $alerts = Alert::with('project')
            ->visibleTo($request->user())
            ->open()
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->latest('first_seen_at')
            ->latest('id')
            ->get();

        return view('modules.site_alerts.company', [
            'groups' => $alerts->groupBy('project_id')->sortBy(fn ($group) => $group->first()->project?->name),
        ]);
    }

    public function scan(Project $project, AlertEngine $engine): RedirectResponse
    {
        $result = $engine->scanProject($project);

        return back()->with('success', __('Alert check finished. New: :new. Resolved: :resolved. Open: :open.', $result));
    }
}
