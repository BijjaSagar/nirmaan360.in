<?php

namespace App\Http\Controllers\Drawings;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\DrawingSheet;
use App\Models\Project;
use App\Models\TakeoffLayer;
use App\Models\TakeoffMeasurement;
use App\Services\Drawings\TakeoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TakeoffController extends Controller
{
    public function __construct(private TakeoffService $service) {}

    public function index(Project $project): View
    {
        return view('modules.drawings.takeoff', [
            'project' => $project,
            'layers' => TakeoffLayer::where('project_id', $project->id)->withCount('measurements')->orderBy('name')->get(),
            'layerTotals' => $this->service->layerTotals($project),
            'boqTotals' => $this->service->boqTotals($project),
            'unlinked' => $this->service->current($project)->whereNull('boq_item_id')->count(),
            'superseded' => $this->service->supersededCount($project),
            'sheets' => DrawingSheet::where('project_id', $project->id)
                ->whereHas('version', fn ($query) => $query->where('is_current', true)->whereHas('drawing'))
                ->whereHas('measurements')->with('version.drawing')->withCount('measurements')->get(),
        ]);
    }

    public function store(Request $request, DrawingSheet $sheet): JsonResponse
    {
        $measurement = $this->service->create($sheet, $request->validate($this->service->createRules($sheet)), $request->user());

        return response()->json(['measurement' => $this->service->present($measurement)], $measurement->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, TakeoffMeasurement $measurement): JsonResponse
    {
        $measurement = $this->service->update($measurement, $request->validate($this->service->updateRules($measurement)));

        return response()->json(['measurement' => $this->service->present($measurement)]);
    }

    public function destroy(TakeoffMeasurement $measurement): JsonResponse
    {
        $measurement->delete();

        return response()->json(['deleted' => true]);
    }

    public function storeLayer(Request $request, Project $project): JsonResponse|RedirectResponse
    {
        $layer = $this->service->createLayer($project, $request->validate($this->service->layerRules()));

        return $request->expectsJson()
            ? response()->json(['layer' => $layer->only('id', 'name', 'colour')], 201)
            : back()->with('success', __('Layer added.'));
    }

    public function updateLayer(Request $request, TakeoffLayer $layer): RedirectResponse
    {
        $this->service->updateLayer($layer, $request->validate($this->service->layerRules()));

        return back()->with('success', __('Layer updated.'));
    }

    public function destroyLayer(TakeoffLayer $layer): RedirectResponse
    {
        $this->service->deleteLayer($layer);

        return back()->with('success', __('Layer removed.'));
    }

    public function apply(BoqItem $item): RedirectResponse
    {
        $this->service->applyToBoq($item);

        return back()->with('success', __('BOQ quantity updated from the takeoff.'));
    }
}
