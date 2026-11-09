<?php

namespace App\Http\Controllers\SiteAlerts;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\SiteAlerts\SiteDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The same cards as the web dashboard, already limited to what this person may see. */
class SiteDashboardApiController extends Controller
{
    public function show(Request $request, Project $project, SiteDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => [
            'project' => ['id' => $project->id, 'code' => $project->code, 'name' => $project->name, 'status' => $project->status],
            'today' => now()->toDateString(),
            'cards' => $dashboard->cards($project, $request->user()),
        ]]);
    }
}
