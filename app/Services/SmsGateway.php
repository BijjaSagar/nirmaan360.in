<?php

namespace App\Services;

use App\Services\Notify\Phone;
use App\Services\Notify\Sms\Msg91Provider;
use App\Services\Notify\Sms\SmsProvider;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends one-time codes through whichever provider the platform admin selected.
 * To add a provider, implement SmsProvider and list it here.
 * The "log" driver delivers nothing and only exists for local development.
 */
class SmsGateway
{
    /** @var array<string, class-string<SmsProvider>> */
    public const PROVIDERS = ['msg91' => Msg91Provider::class];

    public function __construct(private PlatformSettings $settings) {}

    public function driver(): string
    {
        return (string) $this->settings->get('sms_driver', config('buildos.sms_driver'));
    }

    public function isLive(): bool
    {
        return $this->provider()?->isConfigured() ?? false;
    }

    /** Never throws, and says nothing about why: the sign-in screen must not reveal which numbers exist. */
    public function sendOtp(string $phone, string $code): bool
    {
        if (! $this->isLive()) {
            Log::info('OTP generated (log driver, not delivered)', app()->isProduction() ? [] : ['phone' => $phone, 'code' => $code]);

            return false;
        }

        $digits = Phone::digits($phone);

        if ($digits === null) {
            Log::warning('OTP not sent: the number is not a valid mobile number');

            return false;
        }

        try {
            $this->provider()->sendOtp($digits, $code);

            return true;
        } catch (Throwable $exception) {
            Log::warning('OTP could not be sent', ['driver' => $this->driver(), 'error' => $exception->getMessage()]);

            return false;
        }
    }

    private function provider(): ?SmsProvider
    {
        $class = self::PROVIDERS[$this->driver()] ?? null;

        return $class ? app($class) : null;
    }
}
