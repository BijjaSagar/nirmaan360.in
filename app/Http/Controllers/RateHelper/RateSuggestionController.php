<?php

namespace App\Http\Controllers\RateHelper;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Services\RateHelper\RateAcceptance;
use App\Services\RateHelper\RateReview;
use App\Services\RateHelper\RateSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Rate suggestions for BOQ lines, from the company's own records. The routes ask for boq.manage and money.view. */
class RateSuggestionController extends Controller
{
    public function __construct(
        private RateSuggestionService $runs,
        private RateReview $review,
        private RateAcceptance $acceptance,
    ) {}

    /** A form goes on to the review screen. A JSON caller gets the id of the run. */
    public function store(Request $request, Project $project): JsonResponse|RedirectResponse
    {
        $data = $request->validate($this->runs->rules($project), $this->runs->messages());

        $run = $this->runs->start($project, $request->user(), $data['boq_item_ids'], $data['client_uuid']);

        if ($request->expectsJson()) {
            return response()->json(['id' => $run->id], $run->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()->route('rate-suggestions.show', $run);
    }

    public function show(Request $request, RateSuggestionRun $run): View
    {
        return view('modules.rate_helper.show', [
            'run' => $run,
            'project' => $this->projectOf($run),
            ...$this->review->for($run, $request->user()),
        ]);
    }

    public function accept(Request $request, RateSuggestionRun $run, RateSuggestionLine $line): RedirectResponse
    {
        $this->projectOf($run);
        $data = $request->validate($this->acceptance->rules());

        $this->acceptance->accept($request->user(), $run, $line, $data['candidate'], $data['mode'], $request->boolean('checked_warnings'));

        return redirect()->to(route('rate-suggestions.show', $run).'#line-'.$line->id)->with('success', __('Rate accepted.'));
    }

    public function skip(Request $request, RateSuggestionRun $run, RateSuggestionLine $line): RedirectResponse
    {
        $this->projectOf($run);
        $this->acceptance->skip($request->user(), $run, $line);

        return redirect()->to(route('rate-suggestions.show', $run).'#line-'.$line->id)->with('success', __('Line skipped.'));
    }

    /** A run whose project was removed is gone with it. */
    private function projectOf(RateSuggestionRun $run): Project
    {
        return $run->project ?? abort(404);
    }
}
