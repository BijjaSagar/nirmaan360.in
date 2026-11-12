<?php

namespace App\Services\OwnerPack;

use App\Models\AuditLog;
use App\Models\OwnerUpdate;
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
 * The link to an approved weekly update. The app sends nothing to a client: a person makes the
 * link and sends it himself. The address is signed and carries a random token kept on the update;
 * how long it stays open and whether it was withdrawn are read from the row, so a link can be
 * stopped at once and a new link replaces the old one.
 */
class OwnerUpdateShareService
{
    public function __construct(private OwnerUpdateService $updates) {}

    /**
     * Opens a new link to an approved update. Any earlier link stops working.
     *
     * @throws ValidationException
     */
    public function share(OwnerUpdate $update, User $by): OwnerUpdate
    {
        // Only a named person who may see money shares what goes to a client, with or without amounts in it.
        if (! Money::visible($by)) {
            throw new AuthorizationException(Money::refusal());
        }

        return DB::transaction(function () use ($update, $by) {
            $update = $this->current($update);

            if (! $update->isApproved()) {
                throw ValidationException::withMessages(['share' => __('Approve the update before you share it.')]);
            }

            // The records are read once more. An update whose figures no longer match them is not sent out.
            $this->updates->checked($update, __('The records behind this update have changed since it was approved. Prepare a new update for the week.'));

            $update->update([
                'status' => OwnerUpdate::SHARED,
                'share_token' => Str::random(40),
                'share_expires_at' => now()->addDays((int) config('modules.owner_pack.share_link_days')),
                'share_revoked_at' => null,
                'shared_at' => $update->shared_at ?? now(),
            ]);

            AuditLog::record('owner_update.shared', $update, null, ['week_start' => $update->week_start->toDateString(), 'share_expires_at' => $update->share_expires_at->toIso8601String()], $by);

            return $update;
        });
    }

    /** Withdraws the link. The address then shows that it is no longer active. */
    public function revoke(OwnerUpdate $update, User $by): OwnerUpdate
    {
        return DB::transaction(function () use ($update, $by) {
            $update = $this->current($update);

            if ($update->share_token !== null && $update->share_revoked_at === null) {
                $update->update(['share_revoked_at' => now()]);

                AuditLog::record('owner_update.share_revoked', $update, null, ['week_start' => $update->week_start->toDateString()], $by);
            }

            return $update;
        });
    }

    /** The address of the open link, or null when none is open. */
    public function url(OwnerUpdate $update): ?string
    {
        return $update->isShared()
            ? URL::signedRoute('owner-updates.shared', ['id' => $update->id, 'token' => $update->share_token])
            : null;
    }

    /** The address of one photo of the update, behind the same token. */
    public function photoUrl(OwnerUpdate $update, int $photo): string
    {
        return URL::signedRoute('owner-updates.shared.photo', ['id' => $update->id, 'token' => $update->share_token, 'photo' => $photo]);
    }

    /** The message that goes into WhatsApp, in the update's language. It names the week and carries no figure of the work or of money. */
    public function whatsappText(OwnerUpdate $update, string $company): ?string
    {
        $url = $this->url($update);

        if ($url === null) {
            return null;
        }

        $locale = $update->language;
        $date = fn ($day) => FactFormat::display(Fact::DATE, $day->toDateString(), $locale);

        return implode("\n", [
            $company.' · '.__('Weekly update', [], $locale),
            __(':from to :to', ['from' => $date($update->week_start), 'to' => $date($update->week_end)], $locale),
            __('Open the update (the link works until :date):', ['date' => $date($update->share_expires_at)], $locale),
            $url,
        ]);
    }

    public function whatsappUrl(OwnerUpdate $update, string $company): ?string
    {
        $text = $this->whatsappText($update, $company);

        return $text === null ? null : 'https://wa.me/?text='.rawurlencode($text);
    }

    /** The row as it is now, held until the transaction ends. The caller reached it through the tenant scopes. */
    private function current(OwnerUpdate $update): OwnerUpdate
    {
        return OwnerUpdate::withoutGlobalScopes()
            ->where('organization_id', $update->organization_id)
            ->whereKey($update->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
