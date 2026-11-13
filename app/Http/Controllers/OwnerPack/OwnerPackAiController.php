<?php

namespace App\Http\Controllers\OwnerPack;

use App\Http\Controllers\Controller;
use App\Models\AiTextCall;
use App\Models\ClientQuotation;
use App\Models\OwnerUpdate;
use App\Services\DocumentReading\ReadRefused;
use App\Services\DocumentReading\SpendRefused;
use App\Services\OwnerPack\OwnerUpdateService;
use App\Services\OwnerPack\PackDrafts;
use App\Services\OwnerPack\QuotationTranslations;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The three requests of the home-owner pack that ask the AI for something: the opening and the
 * closing of a quotation's cover note, the same two sentences of a weekly update, and translations
 * of a quotation's line descriptions. Each route carries the AI plan, agents.use, RequireAiReader
 * (':text') and the "ai-text" throttle. The answer reaches the draft later; the page looks again.
 */
class OwnerPackAiController extends Controller
{
    public function quotation(Request $request, ClientQuotation $quotation, PackDrafts $drafts): JsonResponse|RedirectResponse
    {
        return $this->asked(
            $request,
            fn () => $drafts->ask($quotation, $request->user()),
            route('quotations.show', $quotation).'#ai-sentences',
            __('The AI is writing the opening and the closing sentences. The page shows them when they are ready.'),
        );
    }

    public function update(Request $request, OwnerUpdate $update, PackDrafts $drafts, OwnerUpdateService $updates): JsonResponse|RedirectResponse
    {
        // An update that states the payment position is money: someone who may not see it may not have it drafted either.
        $updates->assertMayRead($update, $request->user());

        return $this->asked(
            $request,
            fn () => $drafts->ask($update, $request->user()),
            route('owner-updates.show', $update).'#ai-sentences',
            __('The AI is writing the opening and the closing sentences. The page shows them when they are ready.'),
        );
    }

    public function translate(Request $request, ClientQuotation $quotation, QuotationTranslations $translations): JsonResponse|RedirectResponse
    {
        return $this->asked(
            $request,
            fn () => $translations->ask($quotation, $request->user()),
            route('quotations.show', $quotation).'#translations',
            __('The AI is translating the descriptions. Each translation waits for you to accept it before the quotation prints it.'),
        );
    }

    /** @param  Closure(): AiTextCall  $ask */
    private function asked(Request $request, Closure $ask, string $page, string $message): JsonResponse|RedirectResponse
    {
        try {
            $call = $ask();
        } catch (SpendRefused|ReadRefused $refused) {
            // Nothing was sent and nothing was held: the code and its status to a JSON caller, the sentence on the page to a browser.
            if ($request->expectsJson()) {
                return response()->json([...$refused->toArray(), 'message' => $refused->getMessage()], $refused->status);
            }

            return back()->withInput()->withErrors(['ai_reader' => $refused->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['call_id' => $call->id, 'status' => $call->status], 202);
        }

        return redirect()->to($page)->with('success', $message);
    }
}
