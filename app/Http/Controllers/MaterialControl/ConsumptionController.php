<?php

namespace App\Http\Controllers\MaterialControl;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\PicksMaterials;
use App\Models\MaterialIssueItem;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Services\MaterialControl\ConsumptionService;
use App\Services\MaterialControl\SiteStores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsumptionController extends Controller
{
    use PicksMaterials;

    public function __construct(private ConsumptionService $service, private SiteStores $stores) {}

    public function index(Project $project): View
    {
        return view('modules.material_control.consumption.index', [
            'project' => $project,
            'stores' => $this->stores->forProject($project),
            'materialOptions' => $this->materialOptions(),
            'boqItems' => $this->boqOptions($project),
            'stages' => ProjectFloor::where('project_id', $project->id)->orderBy('sort')->pluck('name'),
            'recent' => MaterialIssueItem::with('issue.creator', 'material.baseUnit', 'unit', 'boqItem')
                ->whereHas('issue', fn ($query) => $query->where('project_id', $project->id))
                ->latest('id')
                ->paginate(25),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return back()->with('success', __('Use of material saved.'));
    }
}
