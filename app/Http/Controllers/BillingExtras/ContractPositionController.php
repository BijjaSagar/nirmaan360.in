<?php

namespace App\Http\Controllers\BillingExtras;

use App\Http\Controllers\Controller;
use App\Models\BillNote;
use App\Models\Project;
use App\Services\BillingExtras\ContractPositionService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContractPositionController extends Controller
{
    public function __invoke(Request $request, Project $project, ContractPositionService $positions): View
    {
        // The position is billed, paid, outstanding and retained money from end to end.
        abort_unless(Money::visible($request->user()), 403, Money::refusal());

        return view('modules.billing_extras.position', [
            'project' => $project,
            'contracts' => $positions->forProject($project),
            'notes' => BillNote::with('bill', 'party')->where('project_id', $project->id)->latest('id')->limit(50)->get(),
        ]);
    }
}
