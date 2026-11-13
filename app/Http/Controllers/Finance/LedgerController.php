<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Finance\ProjectLedgerService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LedgerController extends Controller
{
    /** The Finance menu item: the ledger for whoever may see the project's overall spend, the vendor bills for everyone else. */
    public function home(Request $request, Project $project): RedirectResponse
    {
        return redirect()->route(Money::overview($request->user()) ? 'finance.project.ledger' : 'finance.project.vendor-bills.index', $project);
    }

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
