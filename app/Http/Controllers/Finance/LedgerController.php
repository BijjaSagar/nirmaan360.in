<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Finance\ProjectLedgerService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LedgerController extends Controller
{
    public function index(Request $request, Project $project, ProjectLedgerService $ledger): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        return view('modules.finance.ledger', [
            'project' => $project,
            'filters' => $filters,
            'statement' => $ledger->statement($project, $filters['from'] ?? null, $filters['to'] ?? null),
        ]);
    }
}
