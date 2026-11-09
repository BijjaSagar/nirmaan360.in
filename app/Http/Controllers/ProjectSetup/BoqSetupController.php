<?php

namespace App\Http\Controllers\ProjectSetup;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\Material;
use App\Models\Project;
use App\Services\ProjectSetup\BoqCategories;
use App\Services\ProjectSetup\ConcreteMix;
use App\Services\ProjectSetup\StarterBoq;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** How detailed the BOQ is, the starter template, and concrete entered by cubic metre or by ingredients. */
class BoqSetupController extends Controller
{
    public function __construct(private StarterBoq $starter, private ConcreteMix $mix) {}

    public function show(Project $project): View
    {
        $concrete = BoqItem::where('project_id', $project->id)->whereIn('category', BoqCategories::CONCRETE)
            ->orderBy('sort')->orderBy('id')->get()
            ->map(fn (BoqItem $item) => ['item' => $item, 'mix' => $this->mix->current($item)]);

        $materials = Material::with('baseUnit', 'conversions.unit')->where('is_active', true)->orderBy('name')->get();

        return view('modules.project_setup.boq-setup', [
            'project' => $project,
            'template' => $this->starter->template($project),
            'lineCount' => BoqItem::where('project_id', $project->id)->count(),
            'concrete' => $concrete,
            'materials' => $materials,
            // Units each material can be entered in: its base unit and any it has a conversion for.
            'unitsByMaterial' => $materials->mapWithKeys(fn (Material $material) => [
                $material->id => collect([['id' => $material->base_unit_id, 'code' => $material->baseUnit->code]])
                    ->merge($material->conversions->map(fn ($conversion) => ['id' => $conversion->unit_id, 'code' => $conversion->unit->code]))
                    ->values(),
            ]),
            'roles' => ConcreteMix::ROLES,
        ]);
    }

    public function mode(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate(['boq_mode' => ['required', Rule::in(StarterBoq::MODES)]]);

        $this->starter->setMode($project, $data['boq_mode']);

        return back()->with('success', __('BOQ style saved.'));
    }

    public function starter(Project $project): RedirectResponse
    {
        $this->starter->apply($project);

        return redirect()->route('boq.index', $project)->with('success', __('Starter BOQ added. Fill in the quantity and rate of each item.'));
    }

    public function concrete(Request $request, BoqItem $item): RedirectResponse
    {
        abort_unless($this->mix->applies($item), 404);

        $this->mix->save($item, $request->validate($this->mix->rules()));

        return back()->with('success', __('Concrete saved.'));
    }
}
