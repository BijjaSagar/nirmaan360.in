<?php

namespace App\Http\Controllers\SiteAlerts;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AlertPresenter;
use App\Models\Alert;
use App\Models\Project;
use App\Services\SiteAlerts\AlertEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mobile endpoints for alerts. Money alerts are left out for anyone without the money permission they need. */
class AlertApiController extends Controller
{
    public function index(Request $request, Project $project): JsonResponse
    {
        $status = $request->query('status', Alert::OPEN);

        return response()->json(
            Alert::where('project_id', $project->id)
                ->visibleTo($request->user())
                ->when($status !== 'all', fn ($query) => $query->where('status', $status === Alert::RESOLVED ? Alert::RESOLVED : Alert::OPEN))
                ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
                ->latest('first_seen_at')
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
                ->through(AlertPresenter::present(...))
        );
    }

    /** Open alerts across every project this person can see, for the home screen. */
    public function company(Request $request): JsonResponse
    {
        return response()->json(
            Alert::with('project')
                ->visibleTo($request->user())
                ->open()
                ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
                ->latest('first_seen_at')
                ->latest('id')
                ->paginate(50)
                ->through(AlertPresenter::present(...))
        );
    }

    public function scan(Project $project, AlertEngine $engine): JsonResponse
    {
        return response()->json(['data' => $engine->scanProject($project)]);
    }
}
