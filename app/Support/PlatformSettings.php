<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Settings the platform owner changes from /admin without a deploy.
 * A saved value wins over the environment. Secrets are encrypted at rest.
 */
class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings.v1';

    public const SECRETS = ['razorpay_key_secret', 'razorpay_webhook_secret', 'mail_password', 'msg91_auth_key', 'whatsapp_access_token', 'fcm_service_account'];

    /** @var array<string,string|null>|null */
    private ?array $values = null;

    /** @return array<string,string|null> */
    private function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            return $this->values = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::pluck('value', 'key')->all());
        } catch (Throwable) {
            // During the very first migration the tables do not exist yet.
            return $this->values = [];
        }
    }

    /** A queue worker lives for days, so it calls this to pick up keys saved in /admin since it started. */
    public function fresh(): void
    {
        $this->values = null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        if ($value === null || $value === '') {
            return $default;
        }

        if (in_array($key, self::SECRETS, true)) {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException) {
                return $default;
            }
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return filled($this->all()[$key] ?? null);
    }

    /** Section switches are on until someone turns them off. */
    public function isOn(string $key): bool
    {
        return ($this->all()[$key] ?? '1') !== '0';
    }

    /** @param array<string,mixed> $values A null or empty value clears the setting. */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null || $value === '') {
                PlatformSetting::whereKey($key)->delete();

                continue;
            }

            PlatformSetting::updateOrCreate(['key' => $key], [
                'value' => in_array($key, self::SECRETS, true) ? Crypt::encryptString((string) $value) : (string) $value,
            ]);
        }

        Cache::forget(self::CACHE_KEY);
        $this->values = null;
        $this->applyToConfig();
    }

    public function applyToConfig(): void
    {
        foreach ([
            'site_name' => 'buildos.brand',
            'razorpay_key_id' => 'services.razorpay.key_id',
            'razorpay_key_secret' => 'services.razorpay.key_secret',
            'razorpay_webhook_secret' => 'services.razorpay.webhook_secret',
            'subscription_gst_mode' => 'buildos.subscription_gst_mode',
        ] as $setting => $configKey) {
            if ($this->has($setting)) {
                config([$configKey => $this->get($setting)]);
            }
        }

        foreach (['trial_days' => 'buildos.trial_days', 'subscription_gst_pct' => 'buildos.subscription_gst_pct', 'payment_grace_days' => 'buildos.payment_grace_days'] as $setting => $configKey) {
            if ($this->has($setting)) {
                config([$configKey => (int) $this->get($setting)]);
            }
        }

        if ($this->has('mail_host')) {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $this->get('mail_host'),
                'mail.mailers.smtp.port' => (int) $this->get('mail_port', 587),
                'mail.mailers.smtp.scheme' => $this->get('mail_security') === 'ssl' ? 'smtps' : 'smtp',
                'mail.mailers.smtp.username' => $this->get('mail_username'),
                'mail.mailers.smtp.password' => $this->get('mail_password'),
            ]);
        }

        if ($this->has('mail_from_address')) {
            config(['mail.from.address' => $this->get('mail_from_address')]);
        }

        if ($this->has('mail_from_name')) {
            config(['mail.from.name' => $this->get('mail_from_name')]);
        }

        $plans = json_decode((string) $this->get('plans', ''), true);

        if (is_array($plans)) {
            foreach ($plans as $key => $values) {
                if (config("buildos.plans.{$key}")) {
                    config(["buildos.plans.{$key}" => [...config("buildos.plans.{$key}"), ...$values]]);
                }
            }
        }
    }
}
