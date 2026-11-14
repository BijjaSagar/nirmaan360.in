<?php

namespace App\Http\Controllers\SiteDiary;

use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * How a request to the AI that a limit, the wallet or the provider refused is answered: with its code
 * and status to a caller that asks for JSON, and back to the page with the sentence in a browser, as
 * RequireAiReader answers. Never an error page: nothing was sent and nothing was held.
 */
trait AnswersAiRefusals
{
    private function refusal(Request $request, SpendRefused|ReadRefused $refused): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
        }

        return back()->withInput()->withErrors(['ai_reader' => $refused->getMessage()]);
    }
}
