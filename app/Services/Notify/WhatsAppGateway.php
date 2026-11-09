<?php

namespace App\Services\Notify;

use App\Support\PlatformSettings;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Meta WhatsApp Cloud API. Business-started messages must use a template Meta has approved. */
class WhatsAppGateway
{
    private const GRAPH_URL = 'https://graph.facebook.com/v23.0';

    /** Meta's codes for "slow down", which a later retry can get past. */
    private const RATE_LIMIT_CODES = [4, 80007, 130429, 131056];

    public function __construct(private PlatformSettings $settings) {}

    public function isLive(): bool
    {
        return filled($this->settings->get('whatsapp_phone_number_id'))
            && filled($this->settings->get('whatsapp_access_token'))
            && filled($this->settings->get('whatsapp_template_approval'))
            && filled($this->settings->get('whatsapp_template_issue'));
    }

    /** Asks Meta whether the saved keys work, without sending anything. */
    public function keysWork(): bool
    {
        if (! $this->isLive()) {
            return false;
        }

        $response = Http::withToken((string) $this->settings->get('whatsapp_access_token'))
            ->timeout(10)
            ->get(self::GRAPH_URL.'/'.$this->settings->get('whatsapp_phone_number_id'), ['fields' => 'display_phone_number']);

        return $response->successful() && filled($response->json('display_phone_number'));
    }

    /** The approved template for an event. Events without one are not sent on WhatsApp. */
    public function templateFor(string $event): ?string
    {
        $group = Str::before($event, '.');

        return in_array($group, ['approval', 'issue'], true) ? $this->settings->get('whatsapp_template_'.$group) : null;
    }

    /** Both templates take two body values: {{1}} the title and {{2}} the details. */
    public function send(string $digits, NotificationMessage $message): void
    {
        $response = Http::withToken((string) $this->settings->get('whatsapp_access_token'))
            ->acceptJson()
            ->timeout(15)
            ->post(self::GRAPH_URL.'/'.$this->settings->get('whatsapp_phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $digits,
                'type' => 'template',
                'template' => [
                    'name' => $this->templateFor($message->event),
                    'language' => ['code' => $this->settings->get('whatsapp_template_language', 'en')],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $this->templateValue($message->title)],
                            ['type' => 'text', 'text' => $this->templateValue($message->body)],
                        ],
                    ]],
                ],
            ]);

        if (! $response->successful() || blank($response->json('messages.0.id'))) {
            $this->fail($response);
        }
    }

    /** Meta rejects template values that are empty or contain line breaks. */
    private function templateValue(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? '-' : Str::limit($text, 300);
    }

    private function fail(Response $response): never
    {
        $code = $response->json('error.code');
        $error = 'WhatsApp refused the message: HTTP '.$response->status().' code '.$code.' '.$response->json('error.message');

        throw $response->serverError() || $response->status() === 429 || in_array($code, self::RATE_LIMIT_CODES, true)
            ? new RuntimeException($error)
            : new PermanentDeliveryFailure($error);
    }
}
