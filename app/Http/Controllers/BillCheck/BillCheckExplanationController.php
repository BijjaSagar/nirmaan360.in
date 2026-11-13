<?php

namespace App\Http\Controllers\BillCheck;

use App\Http\Controllers\Controller;
use App\Models\BillCheck;
use App\Services\BillCheck\Explain\BillCheckExplanations;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\Wording\FactFormat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The findings of a check explained in plain words. Reading an explanation never depends on the AI
 * service: without it every finding is explained in the app's own words. Only asking for one written
 * with AI makes a model call, and only that route carries the AI gates.
 */
class BillCheckExplanationController extends Controller
{
    public function __construct(private BillCheckExplanations $explanations) {}

    /** Ask for the explanation in one language, written with AI. 202 while it is written; 200 when it already is. */
    public function store(Request $request, BillCheck $check): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(FactFormat::LOCALES)],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        try {
            [$explanation, $call, $started] = $this->explanations->ask($check, $data['locale'], $request->user(), $data['client_uuid'] ?? null);
        } catch (SpendRefused|ReadRefused $refused) {
            if ($request->expectsJson()) {
                return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
            }

            return back()->withErrors(['ai_reader' => $refused->getMessage()]);
        }

        $writing = $call !== null && $call->isOpen();

        if ($request->expectsJson()) {
            return response()->json(['call_id' => $call?->id, 'status' => $writing ? 'drafting' : 'done'], $writing || $started ? 202 : 200);
        }

        return back()->with('success', $writing
            ? __('The AI is writing the explanation. It appears here when it is ready.')
            : __('The explanation is ready.'));
    }

    /** The explanation in one language, for the reader. ?locale=hi; without it, the reader's own language. */
    public function show(Request $request, BillCheck $check): JsonResponse
    {
        $data = $request->validate(['locale' => ['nullable', 'string', Rule::in(FactFormat::LOCALES)]]);

        return response()->json($this->explanations->present($check, BillCheckExplanations::locale($data['locale'] ?? null), $request->user()));
    }
}
