<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Material;
use App\Models\MaterialUnitConversion;
use App\Services\Procurement\MaterialCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaterialController extends Controller
{
    public function __construct(private MaterialCatalogService $catalog) {}

    public function index(Request $request): View
    {
        return view('modules.procurement.materials.index', [
            'materials' => Material::with('baseUnit')->withCount('conversions')->orderBy('name')->paginate(50),
            'units' => $this->catalog->units($request->user()->organization_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $material = $this->catalog->createMaterial($request->validate($this->catalog->materialRules()));

        return redirect()->route('procurement.materials.show', $material)->with('success', __('Material added.'));
    }

    public function show(Request $request, Material $material): View
    {
        return view('modules.procurement.materials.show', [
            'material' => $material->load('baseUnit', 'conversions.unit'),
            'units' => $this->catalog->units($request->user()->organization_id),
        ]);
    }

    public function update(Request $request, Material $material): RedirectResponse
    {
        $this->catalog->updateMaterial($material, [
            'is_active' => false,
            ...$request->validate($this->catalog->materialRules($material)),
        ]);

        return back()->with('success', __('Material saved.'));
    }

    public function storeConversion(Request $request, Material $material): RedirectResponse
    {
        $this->catalog->saveConversion($material, $request->validate($this->catalog->conversionRules()));

        return back()->with('success', __('Conversion saved.'));
    }

    public function destroyConversion(MaterialUnitConversion $conversion): RedirectResponse
    {
        $conversion->delete();

        return back()->with('success', __('Conversion removed.'));
    }

    public function storeUnit(Request $request): RedirectResponse
    {
        $this->catalog->createUnit($request->validate($this->catalog->unitRules()));

        return back()->with('success', __('Unit added.'));
    }
}
