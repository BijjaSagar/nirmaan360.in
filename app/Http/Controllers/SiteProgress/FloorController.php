<?php

namespace App\Http\Controllers\SiteProgress;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Services\SiteProgress\FloorService;
use App\Services\SiteProgress\ProgressReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Floors and segments: setting them up, and tapping one to see its progress and what is pending. */
class FloorController extends Controller
{
    public function __construct(private FloorService $service, private ProgressReport $report) {}

    public function index(Project $project): View
    {
        return view('modules.site_progress.floors', [
            'project' => $project,
            'floors' => $this->report->floors($project),
            'topFloors' => ProjectFloor::where('project_id', $project->id)->whereNull('parent_id')->orderBy('sort')->orderBy('id')->get(['id', 'name']),
            'items' => $this->report->items($project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->service->create($project, $request->validate($this->service->rules($project)));

        return back()->with('success', __('Floor added.'));
    }

    public function show(Request $request, ProjectFloor $floor): View
    {
        $project = $floor->project;

        return view('modules.site_progress.floor', [
            'project' => $project,
            'floor' => $floor->load('parent:id,name'),
            'detail' => $this->report->floorDetail($floor),
            'items' => $this->report->items($project),
            'floorOptions' => ProgressController::floorOptions($project),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function update(Request $request, ProjectFloor $floor): RedirectResponse
    {
        $this->service->update($floor, $request->validate($this->service->rules($floor->project)));

        return back()->with('success', __('Floor saved.'));
    }

    public function destroy(ProjectFloor $floor): RedirectResponse
    {
        $project = $floor->project;
        $this->service->delete($floor);

        return redirect()->route('site-progress.floors.index', $project)->with('success', __('Floor removed.'));
    }

    /** Set or clear what is planned for one BOQ line on this floor. */
    public function target(Request $request, ProjectFloor $floor): RedirectResponse
    {
        $data = $request->validate([
            'boq_item_id' => ['bail', 'required', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $floor->project_id)->whereNull('deleted_at')],
            'planned_qty' => ['required', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999'],
        ]);

        $this->service->setTarget($floor, BoqItem::findOrFail($data['boq_item_id']), $data['planned_qty']);

        return back()->with('success', __('Target saved.'));
    }

    /** Share BOQ lines equally across the chosen floors. */
    public function split(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'all_items' => ['nullable', 'boolean'],
            'boq_item_ids' => ['nullable', 'array'],
            'boq_item_ids.*' => ['integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'floor_ids' => ['required', 'array', 'min:1'],
            'floor_ids.*' => ['integer', Rule::exists('project_floors', 'id')->where('project_id', $project->id)],
        ]);

        $itemIds = $request->boolean('all_items')
            ? BoqItem::where('project_id', $project->id)->pluck('id')->all()
            : ($data['boq_item_ids'] ?? []);

        $count = $this->service->splitEqually($project, $itemIds, $data['floor_ids']);

        return back()->with('success', __(':count BOQ lines shared across the chosen floors.', ['count' => $count]));
    }
}
