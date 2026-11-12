<?php

namespace App\Services\OwnerPack;

use App\Models\AuditLog;
use App\Models\ClientQuotation;
use App\Models\User;
use App\Services\Wording\Fact;
use App\Services\Wording\FactFormat;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The link a contractor sends a client himself. The address is signed and carries a random token
 * kept on the quotation; how long it stays open and whether it was withdrawn are read from the row,
 * so a link can be stopped at once and a new link replaces the old one.
 */
class QuotationShareService
{
    public function __construct(private QuotationService $quotations) {}

    /**
     * Opens a new link to an issued quotation. Any earlier link stops working.
     *
     * @throws ValidationException
     */
    public function share(ClientQuotation $quotation, User $by): ClientQuotation
    {
        // Nothing reaches a client that a named person with money.view has not issued, and only such a person shares it.
        if (! Money::visible($by)) {
            throw new AuthorizationException(Money::refusal());
        }

        return DB::transaction(function () use ($quotation, $by) {
            $quotation = $this->current($quotation);

            if (! $quotation->isIssued()) {
                throw ValidationException::withMessages(['share' => __('Issue the quotation before you share it.')]);
            }

            // The figures are worked out once more. A quotation whose rows no longer give the issued figures is not sent out.
            $this->quotations->checked($quotation, __('The figures of this quotation no longer match what was issued. Make a revision and issue it again.'));

            $quotation->update([
                'share_token' => Str::random(40),
                'share_expires_at' => now()->addDays((int) config('modules.owner_pack.share_link_days')),
                'share_revoked_at' => null,
            ]);

            AuditLog::record('quotation.shared', $quotation, null, ['number' => $quotation->number, 'revision' => $quotation->revision, 'share_expires_at' => $quotation->share_expires_at->toIso8601String()], $by);

            return $quotation;
        });
    }

    /** Withdraws the link. The address then shows that it is no longer active. */
    public function revoke(ClientQuotation $quotation, User $by): ClientQuotation
    {
        return DB::transaction(function () use ($quotation, $by) {
            $quotation = $this->current($quotation);

            if ($quotation->share_token !== null && $quotation->share_revoked_at === null) {
                $quotation->update(['share_revoked_at' => now()]);

                AuditLog::record('quotation.share_revoked', $quotation, null, ['number' => $quotation->number, 'revision' => $quotation->revision], $by);
            }

            return $quotation;
        });
    }

    /** The address of the open link, or null when none is open. */
    public function url(ClientQuotation $quotation): ?string
    {
        return $quotation->isShared()
            ? URL::signedRoute('quotations.shared', ['id' => $quotation->id, 'token' => $quotation->share_token])
            : null;
    }

    /** The message that goes into WhatsApp, in the quotation's language. It names the document and carries no amount. */
    public function whatsappText(ClientQuotation $quotation, string $company): ?string
    {
        $url = $this->url($quotation);

        if ($url === null) {
            return null;
        }

        $locale = $quotation->language;

        return implode("\n", [
            $company.' · '.__('Quotation', [], $locale).' '.$quotation->number,
            __('Open the quotation (the link works until :date):', ['date' => FactFormat::display(Fact::DATE, $quotation->share_expires_at->toDateString(), $locale)], $locale),
            $url,
        ]);
    }

    public function whatsappUrl(ClientQuotation $quotation, string $company): ?string
    {
        $text = $this->whatsappText($quotation, $company);

        return $text === null ? null : 'https://wa.me/?text='.rawurlencode($text);
    }

    /** The row as it is now, held until the transaction ends. The caller reached it through the tenant scopes. */
    private function current(ClientQuotation $quotation): ClientQuotation
    {
        return ClientQuotation::withoutGlobalScopes()
            ->where('organization_id', $quotation->organization_id)
            ->whereKey($quotation->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
