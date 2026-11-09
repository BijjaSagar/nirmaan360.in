<?php

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Models\PlanWbsNode;
use App\Models\Project;
use App\Services\Planning\MsProjectImporter;
use App\Services\Planning\ProgressService;
use App\Services\Planning\ScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function __construct(private ScheduleService $schedule) {}

    public function index(Project $project, ProgressService $progress): View
    {
        $plan = $progress->snapshot($project);

        return view('modules.planning.schedule', [
            'project' => $project,
            'plan' => $plan,
            'gantt' => $plan->gantt(),
            'nodes' => $plan->nodes->sortBy([['sort', 'asc'], ['code', 'asc']])->values(),
        ]);
    }

    public function storeNode(Request $request, Project $project): RedirectResponse
    {
        $this->schedule->createNode($project, $request->validate($this->schedule->nodeRules($project)));

        return back()->with('success', __('WBS node added.'));
    }

    public function updateNode(Request $request, PlanWbsNode $node): RedirectResponse
    {
        $this->schedule->updateNode($node, $request->validate($this->schedule->nodeRules($node->project, $node)));

        return back()->with('success', __('WBS node updated.'));
    }

    public function destroyNode(PlanWbsNode $node): RedirectResponse
    {
        $this->schedule->deleteNode($node);

        return back()->with('success', __('WBS node removed.'));
    }

    public function import(Request $request, Project $project, MsProjectImporter $importer): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:xml', 'max:10240']]);

        $result = $importer->import($project, (string) file_get_contents($request->file('file')->getRealPath()));

        $message = __('Imported :activities activities, :wbs WBS nodes and :links links.', $result);

        if ($result['skipped_links'] > 0) {
            $message .= ' '.__(':count links to summary tasks or other projects were skipped.', ['count' => $result['skipped_links']]);
        }

        if ($result['percent_lags'] > 0) {
            $message .= ' '.__('Percentage lag on :count links was not imported.', ['count' => $result['percent_lags']]);
        }

        return redirect()->route('planning.schedule', $project)->with('success', $message);
    }
}
