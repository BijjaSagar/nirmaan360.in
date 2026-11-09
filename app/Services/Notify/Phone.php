<?php

namespace App\Services\Notify;

final class Phone
{
    /**
     * A number as MSG91 and WhatsApp want it: country code and digits, no plus sign.
     * A number without a country code is taken to be Indian. Returns null if it cannot be a mobile number.
     */
    public static function digits(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        $international = str_starts_with($phone, '+');
        $digits = ltrim((string) preg_replace('/\D+/', '', $phone), '0');

        if (preg_match('/^(91)?([6-9]\d{9})$/', $digits, $match)) {
            return '91'.$match[2];
        }

        return $international && ! str_starts_with($digits, '91') && preg_match('/^\d{8,15}$/', $digits) ? $digits : null;
    }
}
