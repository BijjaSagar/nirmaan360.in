<?php

namespace App\Services\Notify\Sms;

use RuntimeException;

interface SmsProvider
{
    public function isConfigured(): bool;

    /**
     * @param  string  $digits  Country code and number, no plus sign.
     *
     * @throws RuntimeException when the provider does not accept the message.
     */
    public function sendOtp(string $digits, string $code): void;
}
