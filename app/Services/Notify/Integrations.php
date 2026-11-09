<?php

namespace App\Services\Notify;

use App\Services\SmsGateway;
use App\Support\PlatformSettings;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/** The outside services the platform admin connects from the Integrations tab. */
class Integrations
{
    public const NAMES = ['sms', 'whatsapp', 'fcm'];

    public function __construct(
        private PlatformSettings $settings,
        private SmsGateway $sms,
        private WhatsAppGateway $whatsapp,
        private FcmGateway $fcm,
    ) {}

    /** @return array<string, list<mixed>> */
    public function rules(string $name): array
    {
        return collect($this->definition($name)['fields'])->pluck('rules', 'key')->all();
    }

    /** A blank secret keeps what is saved. @return list<string> The setting names that were written. */
    public function save(string $name, array $input, bool $remove): array
    {
        $fields = collect($this->definition($name)['fields']);

        $values = $remove
            ? $fields->mapWithKeys(fn (array $field) => [$field['key'] => null])->all()
            : $fields->reject(fn (array $field) => $this->isSecret($field) && blank($input[$field['key']] ?? null))
                ->mapWithKeys(fn (array $field) => [$field['key'] => $input[$field['key']] ?? null])->all();

        // New details have not been tested yet, whatever the old ones proved.
        $this->settings->set([...$values, $this->testedKey($name) => null]);

        return array_keys($values);
    }

    /** @return string|null An error to show, or null when the service accepted the check. */
    public function test(string $name, ?string $phone = null): ?string
    {
        try {
            $works = match ($name) {
                'sms' => $this->sms->sendOtp((string) $phone, (string) random_int(100000, 999999)),
                'whatsapp' => $this->whatsapp->keysWork(),
                'fcm' => $this->fcm->keysWork(),
            };
        } catch (Throwable $exception) {
            Log::warning('Integration test failed', ['integration' => $name, 'error' => $exception->getMessage()]);
            $works = false;
        }

        $this->settings->set([$this->testedKey($name) => $works ? now()->toIso8601String() : null]);

        return $works ? null : match ($name) {
            'sms' => __('The SMS was not accepted. Check the key, the template and the number, then try again.'),
            'whatsapp' => __('Meta did not accept these details. Check them and try again.'),
            'fcm' => __('Google did not accept this service account. Check it and try again.'),
        };
    }

    public function successMessage(string $name): string
    {
        return match ($name) {
            'sms' => __('The SMS provider accepted the message. The code should reach that number soon.'),
            'whatsapp' => __('Meta accepted these details. No message was sent.'),
            'fcm' => __('Google accepted this service account. No notification was sent.'),
        };
    }

    /** Everything the admin screen draws. Secret values are never included. */
    public function cards(): array
    {
        return collect(self::NAMES)->map(function (string $name) {
            $definition = $this->definition($name);
            $tested = $this->settings->get($this->testedKey($name));
            $live = ($definition['live'])();

            return [
                'name' => $name,
                'title' => $definition['title'],
                'purpose' => $definition['purpose'],
                'testNote' => $definition['testNote'],
                'live' => $live,
                'state' => ! $live ? 'not_connected' : ($tested ? 'tested' : 'saved'),
                'testedAt' => $tested ? Carbon::parse($tested) : null,
                'fields' => array_map(fn (array $field) => [
                    ...$field,
                    'value' => $this->isSecret($field) ? null : $this->settings->get($field['key']),
                    'saved' => $this->isSecret($field) && $this->settings->has($field['key']),
                ], $definition['fields']),
            ];
        })->all();
    }

    private function isSecret(array $field): bool
    {
        return in_array($field['key'], PlatformSettings::SECRETS, true);
    }

    private function testedKey(string $name): string
    {
        return 'integration_'.$name.'_tested_at';
    }

    /** @return array{title:string, purpose:string, testNote:string, live:Closure, fields:list<array<string,mixed>>} */
    private function definition(string $name): array
    {
        return match ($name) {
            'sms' => [
                'title' => __('SMS sign-in codes'),
                'purpose' => __('Sends the one-time codes for mobile sign-in.'),
                'testNote' => __('Sends a real SMS with a sample code to the number you enter.'),
                'live' => fn () => $this->sms->isLive(),
                'fields' => [
                    ['key' => 'sms_driver', 'label' => __('Provider'), 'type' => 'select', 'options' => ['' => __('Off'), 'msg91' => 'MSG91'], 'rules' => ['nullable', Rule::in(array_keys(SmsGateway::PROVIDERS))]],
                    ['key' => 'msg91_auth_key', 'label' => __('MSG91 auth key'), 'type' => 'password', 'rules' => ['nullable', 'string', 'max:128']],
                    ['key' => 'msg91_otp_template_id', 'label' => __('MSG91 flow template ID'), 'type' => 'text', 'hint' => __('The approved template must use ##otp## for the code.'), 'rules' => ['nullable', 'string', 'max:64']],
                ],
            ],
            'whatsapp' => [
                'title' => __('WhatsApp'),
                'purpose' => __('Sends approval and issue alerts through the Meta Cloud API, to people who have turned WhatsApp on.'),
                'testNote' => __('Asks Meta whether these details work. No message is sent.'),
                'live' => fn () => $this->whatsapp->isLive(),
                'fields' => [
                    ['key' => 'whatsapp_phone_number_id', 'label' => __('Phone number ID'), 'type' => 'text', 'rules' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/']],
                    ['key' => 'whatsapp_access_token', 'label' => __('Access token'), 'type' => 'password', 'rules' => ['nullable', 'string', 'max:1024']],
                    ['key' => 'whatsapp_template_approval', 'label' => __('Approval template name'), 'type' => 'text', 'hint' => __('The template body takes two values: {{1}} is the title and {{2}} is the details.'), 'rules' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/']],
                    ['key' => 'whatsapp_template_issue', 'label' => __('Issue template name'), 'type' => 'text', 'hint' => __('The template body takes two values: {{1}} is the title and {{2}} is the details.'), 'rules' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/']],
                    ['key' => 'whatsapp_template_language', 'label' => __('Template language code'), 'type' => 'text', 'hint' => __('The language the templates were approved in, for example en or hi.'), 'rules' => ['nullable', 'string', 'max:12', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/']],
                ],
            ],
            'fcm' => [
                'title' => __('Push notifications'),
                'purpose' => __('Sends alerts to the mobile app through Firebase Cloud Messaging.'),
                'testNote' => __('Signs in to Google and asks Firebase to check a sample message. No notification is sent.'),
                'live' => fn () => $this->fcm->isLive(),
                'fields' => [
                    ['key' => 'fcm_service_account', 'label' => __('Service account JSON'), 'type' => 'textarea', 'hint' => __('Paste the whole file from Firebase, under Project settings then Service accounts.'), 'rules' => ['nullable', 'string', 'max:16384', function (string $attribute, mixed $value, Closure $fail) {
                        if (filled($value) && ! FcmGateway::isServiceAccount((string) $value)) {
                            $fail(__('That is not a valid service account file.'));
                        }
                    }]],
                ],
            ],
        };
    }
}
