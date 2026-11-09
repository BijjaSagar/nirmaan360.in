<?php

namespace App\Http\Controllers\ProjectSetup;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\Project;
use App\Services\BoqService;
use App\Services\ProjectSetup\BoqBudget;
use App\Services\ProjectSetup\BoqCategories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The budget against the BOQ, market rates typed in by hand, and what swapping an item would cost. */
class BoqBudgetController extends Controller
{
    public function __construct(private BoqBudget $budget, private BoqService $boq) {}

    public function show(Request $request, Project $project): View
    {
        $filters = $request->validate(['category' => ['nullable', Rule::in(BoqCategories::keys())]]);

        $all = $this->budget->lines($project);
        $summary = $this->budget->summary($project, $all);
        $category = $filters['category'] ?? null;

        return view('modules.project_setup.budget', [
            'project' => $project,
            'summary' => $summary,
            'lines' => $category ? $all->filter(fn (array $line) => $line['item']->category === $category)->values() : $all,
            'allLines' => $all,
            'category' => $category,
            'watched' => $this->budget->watched($project, $all, $summary['boq']),
            'swap' => $this->preview($request, $project),
        ]);
    }

    public function rates(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate($this->budget->rateRules());

        $saved = $this->budget->recordRates($project, $data['rates'], $data['noted_on'], $data['source'] ?? null, $request->user());

        return back()->with('success', $saved > 0 ? __(':count rates saved.', ['count' => $saved]) : __('No rate was changed.'));
    }

    /** Make the swap a revision of the BOQ line, with the reason. */
    public function swap(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([...$this->budget->swapRules(), ...$this->boq->reasonRules()]);
        $item = BoqItem::where('project_id', $project->id)->findOrFail($data['boq_item_id']);

        $this->budget->swap($item, $data['description'] ?? null, $data['rate'], $data['reason']);

        return redirect()->route('project-setup.budget', $project)->with('success', __('Swap applied. The BOQ and its history are updated.'));
    }

    /**
     * What a swap would do, from the swap_ fields of the page address. Nothing is saved. Null
     * when no swap is being tried.
     *
     * @return array<string, mixed>|null
     */
    private function preview(Request $request, Project $project): ?array
    {
        if (! $request->query('swap_item')) {
            return null;
        }

        $input = [
            'boq_item_id' => $request->query('swap_item'),
            'rate' => $request->query('swap_rate'),
            'description' => $request->query('swap_description'),
        ];

        Validator::make($input, $this->budget->swapRules())->validate();

        $item = BoqItem::where('project_id', $project->id)->findOrFail($input['boq_item_id']);

        return $this->budget->swapPreview($project, $item, $input['description'], $input['rate']);
    }
}
