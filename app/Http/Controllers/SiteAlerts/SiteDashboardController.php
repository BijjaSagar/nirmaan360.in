<?php

namespace App\Http\Controllers\SiteAlerts;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\SiteAlerts\SiteDashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SiteDashboardController extends Controller
{
    public function show(Request $request, Project $project, SiteDashboard $dashboard): View
    {
        return view('modules.site_alerts.dashboard', [
            'project' => $project,
            'cards' => $dashboard->cards($project, $request->user()),
        ]);
    }
}
