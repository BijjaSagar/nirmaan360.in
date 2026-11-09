<?php

namespace App\Services\PublicApi;

use App\Models\AuditLog;
use App\Models\CompanyApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Creates, checks and revokes company API keys. Only a SHA-256 hash of a key is stored, so a key is shown once. */
class ApiKeyService
{
    /** Each key carries the scopes it was given and nothing more. */
    public const SCOPES = [
        'projects:read' => 'Projects',
        'boq:read' => 'BOQ and quantities',
        'dpr:read' => 'Daily reports',
        'bills:read' => 'RA bills',
    ];

    public const KEY_PREFIX = 'n360_';

    public const DEFAULT_RATE = 60;

    public const MAX_RATE = 600;

    public const MAX_ACTIVE_KEYS = 20;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', array_keys(self::SCOPES))],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:10', 'max:'.self::MAX_RATE],
            'expires_on' => ['nullable', 'date', 'after:today'],
        ];
    }

    /** Whether a company's plan includes the public API. The older "api" plan flag still switches it off too. */
    public function enabledFor(Organization $organization): bool
    {
        return $organization->allowsFeature('public_api') && $organization->allowsFeature('api');
    }

    /** @return array{key: CompanyApiKey, secret: string} The secret is not kept and cannot be shown again. */
    public function create(User $by, array $data): array
    {
        $active = CompanyApiKey::withoutGlobalScopes()->where('organization_id', $by->organization_id)->whereNull('revoked_at')->count();

        if ($active >= self::MAX_ACTIVE_KEYS) {
            throw ValidationException::withMessages(['name' => __('You already have :max active keys. Revoke one you no longer use.', ['max' => self::MAX_ACTIVE_KEYS])]);
        }

        $secret = self::KEY_PREFIX.Str::random(40);

        $key = CompanyApiKey::create([
            'organization_id' => $by->organization_id,
            'name' => $data['name'],
            'prefix' => substr($secret, 0, 12),
            'key_hash' => $this->hash($secret),
            'scopes' => array_values(array_intersect(array_keys(self::SCOPES), $data['scopes'])),
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? self::DEFAULT_RATE,
            'expires_at' => filled($data['expires_on'] ?? null) ? now()->parse($data['expires_on'])->endOfDay() : null,
            'created_by' => $by->id,
        ]);
        AuditLog::record('api_key.created', $key, null, ['name' => $key->name, 'prefix' => $key->prefix, 'scopes' => $key->scopes]);

        return ['key' => $key, 'secret' => $secret];
    }

    public function revoke(CompanyApiKey $key): CompanyApiKey
    {
        if ($key->revoked_at === null) {
            $key->update(['revoked_at' => now()]);
            AuditLog::record('api_key.revoked', $key, null, ['name' => $key->name]);
        }

        return $key;
    }

    /** The key a presented secret belongs to, whether or not it is still usable. */
    public function find(string $secret): ?CompanyApiKey
    {
        if (! str_starts_with($secret, self::KEY_PREFIX)) {
            return null;
        }

        return CompanyApiKey::withoutGlobalScopes()->where('key_hash', $this->hash($secret))->first();
    }

    private function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }
}
