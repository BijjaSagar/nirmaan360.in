<?php

namespace App\Http\Controllers\AutoBoq;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\AutoBoq\AutoBoqService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The dimensions of a building, the BOQ lines they give, and applying the lines the engineer keeps. */
class AutoBoqController extends Controller
{
    public function __construct(private AutoBoqService $service) {}

    public function show(Request $request, Project $project): View
    {
        return view('modules.auto_boq.show', [
            ...$this->service->screen($project, $request->user()),
            'blank' => $this->service->blankRows(),
        ]);
    }

    public function save(Request $request, Project $project): RedirectResponse
    {
        $this->service->save($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('auto-boq.show', $project)->with('success', __('Dimensions saved. The lines below are worked out from them.'));
    }

    public function apply(Request $request, Project $project): RedirectResponse
    {
        // Rates are money. Someone who may not see money cannot pick one, even by hand-crafting the form.
        abort_if($request->has('rates') && ! Money::visible($request->user()), 403);

        $result = $this->service->apply($project, $request->validate($this->service->applyRules($project, $request->user())), $request->user());

        return redirect()->route('boq.index', $project)->with('success', __(':created lines added and :updated updated from the building dimensions.', $result));
    }
}
