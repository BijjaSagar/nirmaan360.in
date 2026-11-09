<?php

namespace App\Http\Controllers\BillingExtras;

use App\Http\Controllers\Controller;
use App\Models\BillNote;
use App\Models\Project;
use App\Services\BillingExtras\ContractPositionService;
use Illuminate\View\View;

class ContractPositionController extends Controller
{
    public function __invoke(Project $project, ContractPositionService $positions): View
    {
        return view('modules.billing_extras.position', [
            'project' => $project,
            'contracts' => $positions->forProject($project),
            'notes' => BillNote::with('bill', 'party')->where('project_id', $project->id)->latest('id')->limit(50)->get(),
        ]);
    }
}
