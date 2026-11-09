<?php

namespace App\Http\Controllers\PriceWatch;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\PriceWatch\PriceBoard;
use App\Services\PriceWatch\ProjectPriceImpact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** BOQ rate against today's price for each watched material, and the effect on the project budget. Needs money.overview. */
class ProjectPriceController extends Controller
{
    public function __construct(private ProjectPriceImpact $impact, private PriceBoard $board) {}

    public function show(Request $request, Project $project): View
    {
        $this->board->seedDefaults($project->organization_id);
        $rows = $this->impact->rows($project);

        return view('modules.price_watch.project', [
            'project' => $project,
            'rows' => $rows,
            'summary' => $this->impact->summary($project, $rows),
        ]);
    }

    public function saveRates(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate($this->impact->rateRules());

        $changed = $this->impact->saveRates($project, $data['rates'] ?? [], $data['qtys'] ?? [], $request->user());

        return back()->with('success', $changed > 0 ? __(':count BOQ rates saved.', ['count' => $changed]) : __('No BOQ rate was changed.'));
    }
}
