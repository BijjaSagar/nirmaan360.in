<?php

namespace App\Http\Controllers\ContractTerms;

use App\Http\Controllers\Controller;
use App\Models\ContractTerm;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Services\ContractTerms\ContractTerms;
use App\Services\ContractTerms\TermText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContractTermController extends Controller
{
    public function __construct(private ContractTerms $terms) {}

    public function index(Project $project, TermText $text): View
    {
        $terms = ContractTerm::with('workOrder', 'enteredBy', 'confirmedBy')
            ->where('project_id', $project->id)
            ->orderBy('kind')->orderBy('code')->orderBy('effective_from')->orderBy('id')
            ->get();

        return view('modules.contract_terms.index', [
            'project' => $project,
            // The client contract first, then each subcontract that has a term.
            'contracts' => $terms->groupBy(fn (ContractTerm $term) => (int) $term->work_order_id)->sortKeys(),
            'workOrders' => WorkOrder::where('project_id', $project->id)->orderBy('number')->get(['id', 'number', 'title']),
            'text' => $text,
            'today' => today(),
            'options' => config('modules.contract_terms'),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->terms->create($project, $request->validate($this->terms->rules($project, $request->all())), $request->user());

        return back()->with('success', __('Term saved. It counts once someone confirms it against the agreement.'));
    }

    public function update(Request $request, ContractTerm $term): RedirectResponse
    {
        $wasConfirmed = $term->isConfirmed();
        $term = $this->terms->update($term, $request->validate($this->terms->rules($this->projectOf($term), $request->all(), $term)));

        return back()->with('success', $wasConfirmed && ! $term->isConfirmed()
            ? __('Term changed. Its confirmation was removed, so confirm it against the agreement again.')
            : __('Term saved.'));
    }

    public function destroy(ContractTerm $term): RedirectResponse
    {
        $this->projectOf($term);
        $this->terms->delete($term);

        return back()->with('success', __('Term removed.'));
    }

    public function confirm(Request $request, ContractTerm $term): RedirectResponse
    {
        $seen = $request->validate(
            ['read_in_agreement' => ['accepted'], 'seen' => ['required', 'string', 'size:64']],
            ['read_in_agreement.accepted' => __('Tick the box to say you have read this in the agreement.')],
        )['seen'];

        $this->projectOf($term);
        $this->terms->confirm($term, $request->user(), $seen);

        return back()->with('success', __('Term confirmed.'));
    }

    /** A term outlives its project only as a record: once the project is removed, its terms cannot be changed. */
    private function projectOf(ContractTerm $term): Project
    {
        return $term->project ?? abort(404);
    }
}
