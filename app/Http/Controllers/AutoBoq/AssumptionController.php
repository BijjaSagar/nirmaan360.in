<?php

namespace App\Http\Controllers\AutoBoq;

use App\Http\Controllers\Controller;
use App\Services\AutoBoq\Assumptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The company's typical figures: steel per cubic metre, PCC thickness, working space and so on. */
class AssumptionController extends Controller
{
    public function __construct(private Assumptions $assumptions) {}

    public function show(Request $request): View
    {
        $organization = $request->user()->organization;

        return view('modules.auto_boq.assumptions', [
            'values' => $this->assumptions->forOrganization($organization),
            'changed' => $this->assumptions->changed($organization),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate($this->assumptions->rules());

        $this->assumptions->save($request->user()->organization, $data['values'], $request->user());

        return redirect()->route('auto-boq.assumptions')->with('success', __('Assumptions saved. Open the auto BOQ of a project to see the new figures.'));
    }
}
