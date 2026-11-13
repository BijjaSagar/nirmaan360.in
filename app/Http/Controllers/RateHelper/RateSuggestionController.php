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
use Illuminate\Validation\ValidationException;
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

        try {
            $data = $request->validate($this->acceptance->rules());
            $this->acceptance->accept($request->user(), $run, $line, $data['candidate'], $data['mode'], $request->boolean('checked_warnings'), $data['shown'] ?? null);
        } catch (ValidationException $refused) {
            return $this->refused($request, $run, $line, $refused);
        }

        return redirect()->to($this->atLine($run, $line))->with('success', __('Rate accepted.'));
    }

    public function skip(Request $request, RateSuggestionRun $run, RateSuggestionLine $line): RedirectResponse
    {
        $this->projectOf($run);

        try {
            $this->acceptance->skip($request->user(), $run, $line);
        } catch (ValidationException $refused) {
            return $this->refused($request, $run, $line, $refused);
        }

        return redirect()->to($this->atLine($run, $line))->with('success', __('Line skipped.'));
    }

    private function atLine(RateSuggestionRun $run, RateSuggestionLine $line): string
    {
        return route('rate-suggestions.show', $run).'#line-'.$line->id;
    }

    /** A refusal goes back to the line it is about, and the screen prints it inside that line, so a long run need not be searched. */
    private function refused(Request $request, RateSuggestionRun $run, RateSuggestionLine $line, ValidationException $refused): RedirectResponse
    {
        if ($request->expectsJson()) {
            throw $refused;
        }

        return redirect()->to($this->atLine($run, $line))->withErrors($refused->errors())->with('refused_line', $line->id);
    }

    /** A run whose project was removed is gone with it. */
    private function projectOf(RateSuggestionRun $run): Project
    {
        return $run->project ?? abort(404);
    }
}
