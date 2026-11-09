<?php

namespace App\Http\Controllers\SiteProgress;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Services\SiteProgress\ProgressReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** The progress overview, the pending list, the weekly view and the project's time frame. */
class ProgressController extends Controller
{
    public function __construct(private ProgressReport $report) {}

    public function index(Request $request, Project $project): View
    {
        $items = $this->report->items($project);

        return view('modules.site_progress.index', [
            'project' => $project,
            'summary' => $this->report->summary($project),
            'items' => $items,
            'floors' => $this->report->floors($project),
            'floorOptions' => $this->floorOptions($project),
            'recent' => $this->report->recent($project, 8),
            'clientUuid' => (string) Str::uuid(),
            'lastFloor' => $request->session()->get('site_progress.floor.'.$project->id),
        ]);
    }

    public function schedule(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'planned_days' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'deadline_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $project->update($data);

        return back()->with('success', __('Time frame saved.'));
    }

    public function pending(Project $project): View
    {
        return view('modules.site_progress.pending', [
            'project' => $project,
            'pending' => $this->report->pending($project),
            'floors' => $this->report->floors($project),
        ]);
    }

    public function week(Request $request, Project $project): View
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $day = $request->filled('date') ? Carbon::parse($request->query('date')) : now();

        return view('modules.site_progress.week', [
            'project' => $project,
            'week' => $this->report->week($project, $day),
        ]);
    }

    /** Floors and flats for a select, parents first. */
    public static function floorOptions(Project $project): array
    {
        $floors = ProjectFloor::where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get(['id', 'name', 'parent_id']);
        $options = [];

        foreach ($floors->whereNull('parent_id') as $floor) {
            $options[] = ['id' => $floor->id, 'label' => $floor->name];

            foreach ($floors->where('parent_id', $floor->id) as $child) {
                $options[] = ['id' => $child->id, 'label' => $floor->name.' › '.$child->name];
            }
        }

        return $options;
    }
}
