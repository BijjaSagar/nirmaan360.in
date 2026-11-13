<?php

namespace App\Http\Controllers\RateHelper;

use App\Http\Controllers\Controller;
use App\Models\RateSuggestionRanking;
use App\Models\RateSuggestionRun;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\RateHelper\Ranking\RateRanking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Rank with AI" on a run of rate suggestions, and where it stands. Only asking for a ranking makes
 * a model call, so only that route carries the AI gates; the status never depends on the AI service.
 */
class RateRankingController extends Controller
{
    public function __construct(private RateRanking $ranking) {}

    /** 202 while parts are being ranked, new or already under way; 200 when every line that could be ranked is. */
    public function store(Request $request, RateSuggestionRun $run): JsonResponse|RedirectResponse
    {
        $this->projectOf($run);
        $data = $request->validate(['client_uuid' => ['nullable', 'uuid']]);

        try {
            [$parts, , $refusal] = $this->ranking->ask($run, $request->user(), $data['client_uuid'] ?? null);
        } catch (SpendRefused|ReadRefused $refused) {
            if ($request->expectsJson()) {
                return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
            }

            return redirect()->to($this->atRanking($run))->withErrors(['ai_reader' => $refused->getMessage()]);
        }

        $ranking = collect($parts)->contains(fn (RateSuggestionRanking $part) => $part->isAsked());

        if ($request->expectsJson()) {
            return response()->json([
                'run_id' => $run->id,
                'status' => $ranking ? RateRanking::RANKING : RateRanking::DONE,
                'parts' => collect($parts)->map(fn (RateSuggestionRanking $part) => [
                    'id' => $part->id,
                    'part' => $part->part,
                    'lines' => $part->lines,
                    'status' => $part->status,
                    'reason' => $part->reason,
                    'call_id' => $part->ai_text_call_id,
                ])->values()->all(),
                'refused' => $refusal === null ? null : [...$refusal->toArray(), 'message' => $refusal->getMessage()],
            ], $ranking ? 202 : 200);
        }

        $redirect = redirect()->to($this->atRanking($run))->with('success', $ranking
            ? __('The AI is putting the suggestions in order. This page updates when it is done.')
            : __('Every line that could be ranked is ranked.'));

        return $refusal === null ? $redirect : $redirect->withErrors(['ai_reader' => __('Some lines could not be sent: :reason', ['reason' => $refusal->getMessage()])]);
    }

    /** Where the ranking stands. Each request also looks after the calls that are still open. */
    public function show(RateSuggestionRun $run): JsonResponse
    {
        $this->projectOf($run);

        return response()->json($this->ranking->status($run));
    }

    private function atRanking(RateSuggestionRun $run): string
    {
        return route('rate-suggestions.show', $run).'#ranking';
    }

    /** A run whose project was removed is gone with it. */
    private function projectOf(RateSuggestionRun $run): void
    {
        $run->project ?? abort(404);
    }
}
