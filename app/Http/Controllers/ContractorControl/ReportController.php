<?php

namespace App\Http\Controllers\ContractorControl;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\ContractorControl\CostVarianceService;
use App\Services\ContractorControl\DeadlineViewService;
use Illuminate\View\View;

class ReportController extends Controller
{
    /** Work % and days only, so site staff can use it too. */
    public function deadlines(Project $project, DeadlineViewService $service): View
    {
        return view('modules.contractor_control.deadlines', ['project' => $project, ...$service->forProject($project)]);
    }

    public function variance(Project $project, CostVarianceService $service): View
    {
        return view('modules.contractor_control.variance', ['project' => $project, ...$service->forProject($project)]);
    }
}
