<?php

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\PlanActivity;
use App\Models\PlanBoqLink;
use App\Models\PlanDependency;
use App\Models\Project;
use App\Services\Planning\ProgressService;
use App\Services\Planning\ScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function __construct(private ScheduleService $schedule) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        $activity = $this->schedule->createActivity($project, $request->validate($this->schedule->activityRules($project)));

        return redirect()->route('planning.activities.show', $activity)->with('success', __('Activity added. Link it to BOQ items so its progress can be tracked.'));
    }

    public function show(PlanActivity $activity, ProgressService $progress): View
    {
        $project = $activity->project;
        $plan = $progress->snapshot($project);

        $activity->load('wbsNode', 'boqLinks.boqItem', 'predecessorLinks.predecessor', 'successorLinks.successor');

        return view('modules.planning.activity', [
            'project' => $project,
            'activity' => $activity,
            'plan' => $plan,
            'progress' => $plan->progress[$activity->id],
            'nodes' => $plan->nodes->sortBy([['sort', 'asc'], ['code', 'asc']])->values(),
            'others' => $plan->activities->reject(fn (PlanActivity $other) => $other->id === $activity->id)->values(),
            'boqItems' => BoqItem::where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get(['id', 'code', 'description', 'unit', 'qty']),
        ]);
    }

    public function update(Request $request, PlanActivity $activity): RedirectResponse
    {
        $moved = $this->schedule->updateActivity($activity, $request->validate($this->schedule->activityRules($activity->project, $activity)));

        return back()->with('success', $moved > 0
            ? __('Activity updated. :count later activities moved to fit.', ['count' => $moved])
            : __('Activity updated.'));
    }

    public function destroy(PlanActivity $activity): RedirectResponse
    {
        $project = $activity->project;
        $this->schedule->deleteActivity($activity);

        return redirect()->route('planning.schedule', $project)->with('success', __('Activity removed.'));
    }

    public function storeDependency(Request $request, PlanActivity $activity): RedirectResponse
    {
        [, $moved] = $this->schedule->addDependency($activity, $request->validate($this->schedule->dependencyRules($activity->project)));

        return back()->with('success', $moved > 0
            ? __('Link saved. :count activities moved to fit.', ['count' => $moved])
            : __('Link saved.'));
    }

    public function destroyDependency(PlanDependency $dependency): RedirectResponse
    {
        $dependency->delete();

        return back()->with('success', __('Link removed.'));
    }

    public function storeBoqLink(Request $request, PlanActivity $activity): RedirectResponse
    {
        $this->schedule->linkBoqItem($activity, $request->validate($this->schedule->boqLinkRules($activity->project)));

        return back()->with('success', __('BOQ item linked.'));
    }

    public function destroyBoqLink(PlanBoqLink $link): RedirectResponse
    {
        $link->delete();

        return back()->with('success', __('BOQ item unlinked.'));
    }
}
