<?php

namespace App\Http\Controllers\Qhs;

use App\Http\Controllers\Controller;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\Project;
use App\Services\Qhs\ChecklistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InspectionController extends Controller
{
    public function __construct(private ChecklistService $service) {}

    public function index(Request $request, Project $project): View
    {
        $category = $request->query('category');
        $status = $request->query('status');

        return view('modules.qhs.inspections.index', [
            'project' => $project,
            'inspections' => Inspection::with('inspector')
                ->withCount(['items', 'items as failed_count' => fn ($query) => $query->where('result', 'fail')])
                ->where('project_id', $project->id)
                ->when($category, fn ($query, $value) => $query->where('category', $value))
                ->when($status, fn ($query, $value) => $query->where('status', $value))
                ->latest('inspected_on')->latest('id')
                ->paginate(25)->withQueryString(),
            'templates' => ChecklistTemplate::where('is_active', true)->orderBy('name')->get(['id', 'name', 'category']),
            'sites' => $project->sites,
            'category' => $category,
            'status' => $status,
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $inspection = $this->service->start($project, $request->validate($this->service->startRules($project, $request->user())), $request->user());

        return redirect()->route('inspections.show', $inspection);
    }

    public function show(Inspection $inspection): View
    {
        return view('modules.qhs.inspections.show', [
            'inspection' => $inspection->load('project', 'site', 'inspector', 'items', 'template'),
            'project' => $inspection->project,
        ]);
    }

    /** Save the results. With "complete" set, finish the inspection straight after. */
    public function update(Request $request, Inspection $inspection): RedirectResponse
    {
        $this->service->save($inspection, $request->validate($this->service->saveRules($inspection))['items']);

        if ($request->boolean('complete')) {
            $this->service->complete($inspection->refresh(), $request->user());

            return back()->with('success', __('Inspection completed.'));
        }

        return back()->with('success', __('Inspection saved.'));
    }
}
