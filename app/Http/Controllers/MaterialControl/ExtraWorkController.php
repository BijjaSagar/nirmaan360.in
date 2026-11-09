<?php

namespace App\Http\Controllers\MaterialControl;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Procurement\PicksMaterials;
use App\Models\MaterialExtraWork;
use App\Models\Project;
use App\Services\MaterialControl\ExtraWorkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExtraWorkController extends Controller
{
    use PicksMaterials;

    public function __construct(private ExtraWorkService $service) {}

    public function index(Project $project): View
    {
        return view('modules.material_control.extra-work.index', [
            'project' => $project,
            'materialOptions' => $this->materialOptions(),
            'boqItems' => $this->boqOptions($project),
            'works' => MaterialExtraWork::with('material.baseUnit', 'unit', 'boqItem', 'creator')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return back()->with('success', __('Extra work saved. This material is no longer counted as wastage.'));
    }

    public function reverse(Request $request, MaterialExtraWork $extraWork): RedirectResponse
    {
        $this->service->reverse($extraWork, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', __('Extra work reversed.'));
    }
}
