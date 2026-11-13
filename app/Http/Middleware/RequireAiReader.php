<?php

namespace App\Http\Middleware;

use App\Models\AiDocumentSettings;
use App\Services\Agents\ModelGateway;
use App\Services\DocumentReading\AiDocumentConsent;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\ProviderBlock;
use App\Services\DocumentReading\SpendRefused;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards a route that makes the AI model read or write something for a company. It goes ONLY on
 * routes that cause a model call. Looking at a read, correcting it, confirming it, its status and
 * its page pictures never depend on the provider, the limits or the consent switch, so a company
 * can always finish what was already read.
 *
 * Used by class name with one word:
 *   RequireAiReader::class.':consent'  the call sends a page, a photo, document text or spoken words
 *   RequireAiReader::class.':text'     the call sends only labels and descriptions the code prepared
 *
 * Refusals, in this order. The phone and any request that asks for JSON get the code; a browser is
 * sent back to the page it came from with the sentence.
 *   409 not_released      the platform admin has not released the readers
 *   409 not_connected     no key is saved, or the provider is refusing every call for now
 *   403                   the company's plan has no AI features
 *   409 company_limit     the platform admin has switched the readers off for this company (limit: blocked)
 *   409 consent_required  ":consent" only: the company has not agreed to the current notice. In a
 *                         browser an owner or admin is taken to the notice, anyone else gets a 403.
 *   409 reader_paused     a platform spending limit for the day or the month is reached (period)
 *
 * The other limits (budgets, the wallet, a company's share and brakes) are checked where credits
 * are put on hold, because they depend on what is about to be spent.
 */
class RequireAiReader
{
    public const CONSENT = 'consent';

    public const TEXT = 'text';

    public const CONSENT_REQUIRED = 'consent_required';

    public const NOT_CONNECTED = 'not_connected';

    public function __construct(private AiSpend $spend, private ModelGateway $gateway) {}

    public function handle(Request $request, Closure $next, string $needs = self::CONSENT): Response
    {
        if (! in_array($needs, [self::CONSENT, self::TEXT], true)) {
            throw new LogicException("RequireAiReader takes \"consent\" or \"text\", not \"{$needs}\".");
        }

        $user = $request->user();
        $organization = $user?->organization;

        abort_if($organization === null, 403);

        if (! $this->spend->released()) {
            return $this->refuse($request, SpendRefused::NOT_RELEASED, __('The readers are not switched on yet.'));
        }

        if (! $this->gateway->isConnected() || ProviderBlock::current() !== null) {
            return $this->refuse($request, self::NOT_CONNECTED, __('The AI service is not available right now. Try again later.'));
        }

        abort_unless($organization->allowsFeature('ai_agents'), 403, __('Your plan does not include this module. Upgrade to continue.'));

        $settings = AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $organization->id)->first();

        if ($settings?->platform_block) {
            return $this->refuse($request, SpendRefused::COMPANY_LIMIT, __('The readers are switched off for your company. Please contact support.'), ['limit' => 'blocked']);
        }

        if ($needs === self::CONSENT && ! AiDocumentConsent::current($organization)) {
            $message = __('Your company has not agreed to the current notice for sending documents to the AI service. An owner or admin can do that in Settings.');

            if ($this->wantsJson($request)) {
                return $this->json(self::CONSENT_REQUIRED, $message);
            }

            abort_unless($user->can('settings.manage'), 403, $message);

            return redirect()->route('ai-documents.edit')->withErrors(['ai_reader' => __('Read the notice and switch the readers on for your company first.')]);
        }

        if (($period = $this->spend->platformStop()) !== null) {
            return $this->refuse($request, SpendRefused::READER_PAUSED, $period === 'monthly'
                ? __('The reader is paused for this month.')
                : __('The reader is paused for today. Try again tomorrow.'), ['period' => $period]);
        }

        return $next($request);
    }

    /** @param  array<string, scalar|null>  $details */
    private function refuse(Request $request, string $code, string $message, array $details = []): Response
    {
        if ($this->wantsJson($request)) {
            return $this->json($code, $message, $details);
        }

        // Never a 409 page in a browser: the person goes back to where they were and reads why.
        return back()->withInput()->withErrors(['ai_reader' => $message]);
    }

    /** @param  array<string, scalar|null>  $details */
    private function json(string $code, string $message, array $details = []): Response
    {
        return response()->json(['code' => $code, ...$details, 'message' => $message], 409);
    }

    private function wantsJson(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }
}
