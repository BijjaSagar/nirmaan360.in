<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\Grn;
use App\Models\MaterialIssue;
use App\Models\MaterialReturn;
use App\Models\Project;
use App\Services\Procurement\MaterialReturnService;
use App\Support\Dec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaterialReturnController extends Controller
{
    public function __construct(private MaterialReturnService $service) {}

    public function index(Project $project): View
    {
        return view('modules.procurement.returns.index', [
            'project' => $project,
            'returns' => MaterialReturn::with('store', 'issue', 'grn')->withCount('items')->where('project_id', $project->id)->latest('id')->paginate(25),
        ]);
    }

    public function createFromIssue(MaterialIssue $materialIssue): View
    {
        abort_if($materialIssue->isReversed(), 409, __('This issue has been reversed.'));

        return $this->form($materialIssue, 'material_issue_item_id', route('procurement.returns.store-from-issue', $materialIssue), __('Return to store'));
    }

    public function storeFromIssue(Request $request, MaterialIssue $materialIssue): RedirectResponse
    {
        $return = $this->service->returnToStore($materialIssue, $this->validated($request, 'material_issue_items'), $request->user());

        return redirect()->route('procurement.returns.show', $return)->with('success', __('Return posted to stock.'));
    }

    public function createFromGrn(Grn $grn): View
    {
        abort_if($grn->isReversed(), 409, __('This receipt has been reversed.'));

        return $this->form($grn, 'grn_item_id', route('procurement.returns.store-from-grn', $grn), __('Return to vendor'));
    }

    public function storeFromGrn(Request $request, Grn $grn): RedirectResponse
    {
        $return = $this->service->returnToVendor($grn, $this->validated($request, 'grn_items'), $request->user());

        return redirect()->route('procurement.returns.show', $return)->with('success', __('Return posted to stock.'));
    }

    public function show(MaterialReturn $materialReturn): View
    {
        $materialReturn->load('project', 'store', 'issue', 'grn', 'items.material.baseUnit', 'items.unit', 'creator', 'reverser');

        return view('modules.procurement.returns.show', ['return' => $materialReturn, 'project' => $materialReturn->project]);
    }

    public function reverse(Request $request, MaterialReturn $materialReturn): RedirectResponse
    {
        $this->service->reverse($materialReturn, $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'], $request->user());

        return back()->with('success', __('Return reversed.'));
    }

    private function form(MaterialIssue|Grn $source, string $lineColumn, string $action, string $title): View
    {
        $source->load('project', 'store', 'items.material.baseUnit', 'items.unit');
        $returned = $this->service->returned($lineColumn, $source->items->pluck('id'));

        return view('modules.procurement.returns.create', [
            'project' => $source->project,
            'source' => $source,
            'action' => $action,
            'title' => $title,
            'lines' => $source->items->map(fn ($item) => [
                'item' => $item,
                'can_return' => Dec::qty(Dec::of($item->base_qty)->minus(Dec::of($returned[$item->id] ?? 0))),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $lineTable): array
    {
        // Only the lines being returned are filled in.
        $request->merge(['items' => array_values(array_filter(
            (array) $request->input('items', []),
            fn ($line) => filled($line['qty'] ?? null),
        ))]);

        return $request->validate($this->service->rules($lineTable));
    }
}
