<?php

namespace App\Services\Notify\Sms;

use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * MSG91 Flow API. The code travels in the JSON body and the key in a header, so
 * neither can end up in a URL or in a logged connection error.
 */
class Msg91Provider implements SmsProvider
{
    private const URL = 'https://control.msg91.com/api/v5/flow/';

    public function __construct(private PlatformSettings $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->get('msg91_auth_key')) && filled($this->settings->get('msg91_otp_template_id'));
    }

    public function sendOtp(string $digits, string $code): void
    {
        $response = Http::withHeaders(['authkey' => (string) $this->settings->get('msg91_auth_key')])
            ->acceptJson()
            ->timeout(15)
            ->post(self::URL, [
                'template_id' => $this->settings->get('msg91_otp_template_id'),
                'short_url' => '0',
                'recipients' => [['mobiles' => $digits, 'otp' => $code]],
            ]);

        // MSG91 can answer HTTP 200 with type "error", so the status alone proves nothing.
        if (! $response->successful() || $response->json('type') !== 'success') {
            throw new RuntimeException('MSG91 refused the message: HTTP '.$response->status().' '.$response->json('message'));
        }
    }
}
