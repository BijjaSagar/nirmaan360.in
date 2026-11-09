<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\MaterialIssue;
use App\Models\Project;
use App\Services\Procurement\MaterialIssueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaterialIssueController extends Controller
{
    use PicksMaterials;

    public function __construct(private MaterialIssueService $service) {}

    public function index(Project $project): View
    {
        return view('modules.procurement.issues.index', [
            'project' => $project,
            'issues' => MaterialIssue::with('store')->withCount('items')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function create(Project $project): View
    {
        return view('modules.procurement.issues.create', [
            'project' => $project,
            'stores' => $this->projectStores($project),
            'materialOptions' => $this->materialOptions(false),
            'boqItems' => $this->boqOptions($project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $issue = $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('procurement.issues.show', $issue)->with('success', __('Material issue posted.'));
    }

    public function show(MaterialIssue $materialIssue): View
    {
        $materialIssue->load('project', 'store', 'items.material.baseUnit', 'items.unit', 'items.boqItem', 'creator', 'reverser', 'returns');

        return view('modules.procurement.issues.show', ['issue' => $materialIssue, 'project' => $materialIssue->project]);
    }

    public function reverse(Request $request, MaterialIssue $materialIssue): RedirectResponse
    {
        $this->service->reverse($materialIssue, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', __('Material issue reversed.'));
    }
}
