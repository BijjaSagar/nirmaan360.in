<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Sends one-time codes. Only the log driver exists so far: it writes the code to
 * the application log and delivers nothing. Add a real provider (for example MSG91)
 * here before showing OTP sign-in in production.
 */
class SmsGateway
{
    public function isLive(): bool
    {
        return config('buildos.sms_driver') !== 'log';
    }

    public function sendOtp(string $phone, string $code): void
    {
        Log::info('OTP generated (log driver, not delivered)', ['phone' => $phone, 'code' => $code]);
    }
}
