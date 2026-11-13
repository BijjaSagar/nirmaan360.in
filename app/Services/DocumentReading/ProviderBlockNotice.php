<?php

namespace App\Services\DocumentReading;

use App\Models\User;
use App\Services\Agents\ModelGatewayException;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use Illuminate\Support\Facades\Route;

/**
 * Puts the stop on every reader call when the provider has refused one for a reason the next call
 * would meet as well, and tells the platform admins. A page and a text call raise it the same way.
 */
class ProviderBlockNotice
{
    public function __construct(private Notifier $notifier) {}

    /**
     * @param  string  $reason  One of ProviderBlock::REASONS, as the gateway named it.
     * @return bool Whether this call set the stop. The admins are told once per stop, not once per call that meets it.
     */
    public function raise(string $reason): bool
    {
        if (! ProviderBlock::set($reason)) {
            return false;
        }

        $this->tellAdmins($reason);

        return true;
    }

    /** Platform admins are told once when the provider starts refusing every call. Companies are never told why. */
    private function tellAdmins(string $reason): void
    {
        $admins = User::where('is_active', true)
            ->where(fn ($query) => $query->where('is_platform_admin', true)->orWhereIn('email', config('buildos.platform_admins')))
            ->orderBy('id')->get()->filter->isPlatformAdmin();

        foreach ($admins as $admin) {
            $this->notifier->send($admin, function (string $locale) use ($reason) {
                $what = match ($reason) {
                    ModelGatewayException::SPEND_LIMIT => __('The provider account has reached its spending limit.', [], $locale),
                    ModelGatewayException::BILLING => __('The provider reports a billing problem on the account.', [], $locale),
                    ModelGatewayException::REJECTED_KEY => __('The provider rejected the saved API key.', [], $locale),
                    default => __('No API key for the AI service is saved.', [], $locale),
                };

                $next = $reason === ModelGatewayException::SPEND_LIMIT
                    ? __('Reads in progress are paused. No page is sent until this stop is cleared on the AI reader page.', [], $locale)
                    : __('Reads in progress are paused. New pages are held back for :minutes minutes, then tried again when someone continues a read.', ['minutes' => ProviderBlock::MINUTES], $locale);

                return new NotificationMessage(
                    event: 'ai.provider_block',
                    title: __('The AI readers have stopped: the AI service refused a request', [], $locale),
                    body: $what.' '.$next,
                    url: Route::has('ai-reader.admin.index') ? route('ai-reader.admin.index') : null,
                    critical: true,
                );
            });
        }
    }
}
