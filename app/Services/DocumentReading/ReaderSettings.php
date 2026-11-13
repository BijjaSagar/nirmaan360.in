<?php

namespace App\Services\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\AuditLog;
use App\Models\DocumentRead;
use App\Models\Organization;
use App\Models\User;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * What a company's owner or admin decides about the readers: whether its pages, photos and text may
 * be sent to the AI provider at all, and how much a read, a person and the company may spend.
 *
 * Nothing here is offered while the readers are off: AiSpend::readersOn() is the one test, the same
 * one the public website, the privacy policy and terms, the menu and the phone's status answer use.
 * A company that has agreed keeps the page and its menu entry while the readers are off, to switch
 * them off (shown()); no reader sends anything meanwhile, as every gate asks readersOn's parts itself.
 */
class ReaderSettings
{
    /** Scopes of a daily budget, as AiSpend reads them from ai_document_settings.budgets. */
    private const SCOPES = ['user', 'company'];

    public function __construct(
        private AiSpend $spend,
        private ReadLifecycle $lifecycle,
        private ReadKinds $kinds,
    ) {}

    /** Whether any feature that sends a company's documents, photos or spoken words exists in this deployment, or for this company's plan. */
    public function readerDeployed(?Organization $organization = null): bool
    {
        return $this->spend->readerDeployed($organization);
    }

    /**
     * Whether the consent switch is shown, and the company's menu entry for it: the readers are on for
     * this company (AiSpend::readersOn). Without a company, as the public privacy policy and terms ask
     * it for their sentences about readers, the plan is not part of the answer.
     */
    public function offered(?Organization $organization = null): bool
    {
        return $this->spend->readersOn($organization);
    }

    /**
     * Whether the company's menu has the entry for these settings: while the readers are on for it (offered()),
     * and while its agreement is on record although they are not, so an owner or admin can still switch them off.
     * It is asked on every page, so the agreement is read from the company's own row, with no query
     * (AiDocumentSettings::agreed).
     */
    public function shown(Organization $organization): bool
    {
        return $this->offered($organization) || AiDocumentSettings::agreed($organization);
    }

    /**
     * Whether a company may set its spending limits: the same release, connection and plan as the
     * readers (AiSpend::aiOn), and something that spends is deployed. A text feature can be there before
     * any reader is.
     */
    public function limitsOffered(?Organization $organization = null): bool
    {
        return $this->spend->aiOn($organization) && $this->features() !== [];
    }

    /** Voice notes are deployed, and the platform admin has recorded that speech stays on the phone: AiSpend::voiceDeployed. */
    public function voiceOffered(): bool
    {
        return $this->spend->voiceDeployed();
    }

    /**
     * The notice as it is shown today, sentence by sentence.
     *
     * @return list<string>
     */
    public function sentences(?string $locale = null): array
    {
        return AiDocumentConsent::sentences($locale, $this->voiceOffered());
    }

    public function notice(?string $locale = null): string
    {
        return AiDocumentConsent::notice($locale, $this->voiceOffered());
    }

    /** The company's row, or null when nothing was ever decided. Asking never creates it. */
    public function of(Organization $organization): ?AiDocumentSettings
    {
        return AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
    }

    /**
     * Where the company's consent stands, for a screen or the phone. "version" is the notice the
     * company last agreed to, which stays on record after the switch is turned off.
     *
     * @return array{enabled:bool, version:?string, current:bool}
     */
    public function consent(Organization $organization): array
    {
        $settings = $this->of($organization);
        $enabled = (bool) $settings?->enabled;

        return [
            'enabled' => $enabled,
            'version' => $settings?->consent_version,
            'current' => $enabled && $settings->consent_version === AiDocumentConsent::VERSION,
        ];
    }

    /**
     * Switches the readers on for a company under the notice as it reads today. The words that were
     * shown, their language and the version are kept on the company's row; the audit trail keeps the
     * version, the language and a fingerprint of the words, never the words.
     *
     * @param  string  $locale  The language the notice was shown in.
     * @param  ?string  $shown  The fingerprint of the notice the person had in front of them. When it is
     *                          not today's notice in that language, nothing is switched on.
     *
     * @throws ValidationException under "accept"
     */
    public function turnOn(Organization $organization, User $by, string $locale, ?string $shown = null): AiDocumentSettings
    {
        $this->assertOwnCompany($organization, $by);

        if (! $this->offered($organization)) {
            throw ValidationException::withMessages(['accept' => __('No reader is switched on yet.')]);
        }

        $locale = array_key_exists($locale, config('buildos.locales')) ? $locale : config('app.fallback_locale');
        $notice = $this->notice($locale);

        if ($shown !== null && ! hash_equals(AiDocumentConsent::hash($notice), $shown)) {
            throw ValidationException::withMessages(['accept' => __('The notice has changed since this page was opened. Read it again before you switch the readers on.')]);
        }

        return DB::transaction(function () use ($organization, $by, $locale, $notice) {
            $settings = AiDocumentSettings::withoutGlobalScopes()->lockForUpdate()->find(AiDocumentSettings::for($organization)->id);

            if ($settings->enabled && $settings->consent_version === AiDocumentConsent::VERSION) {
                return $settings;
            }

            $before = ['enabled' => (bool) $settings->enabled, 'version' => $settings->consent_version];

            $settings->forceFill([
                'enabled' => true,
                'consent_version' => AiDocumentConsent::VERSION,
                'consent_text' => $notice,
                'consent_locale' => $locale,
                'consented_by' => $by->id,
                'consented_at' => now(),
                'withdrawn_by' => null,
                'withdrawn_at' => null,
            ])->save();

            AuditLog::record('ai_documents.consent_on', $settings, $before, [
                'enabled' => true,
                'version' => AiDocumentConsent::VERSION,
                'locale' => $locale,
                'sha256' => AiDocumentConsent::hash($notice),
            ], $by, $organization->id);

            return $settings;
        });
    }

    /**
     * Switches the readers off for a company. From this moment the readers send nothing new: every read that
     * is not finished is cancelled, its waiting pages are skipped and its credits on hold are given
     * back. A read that is finished and waiting for review stays, and can still be checked and saved.
     *
     * The switch is saved first and by itself, so a read that could not be cancelled here still
     * stops at its next page, where the worker finds the consent gone.
     *
     * @return int How many reads were cancelled.
     */
    public function turnOff(Organization $organization, User $by): int
    {
        $this->assertOwnCompany($organization, $by);

        DB::transaction(function () use ($organization, $by) {
            $settings = AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $organization->id)->lockForUpdate()->first();

            if ($settings === null || ! $settings->enabled) {
                return;
            }

            $settings->forceFill(['enabled' => false, 'withdrawn_by' => $by->id, 'withdrawn_at' => now()])->save();

            AuditLog::record('ai_documents.consent_off', $settings,
                ['enabled' => true, 'version' => $settings->consent_version],
                ['enabled' => false, 'version' => $settings->consent_version],
                $by, $organization->id);
        });

        $cancelled = 0;

        $open = DocumentRead::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('status', DocumentRead::OPEN)
            ->orderBy('id')
            ->get();

        foreach ($open as $read) {
            $cancelled += (int) $this->lifecycle->cancel($read, $by, ReadWords::CONSENT_WITHDRAWN);
        }

        return $cancelled;
    }

    /**
     * The features that can spend in this deployment, each of which may have a daily budget:
     * the reader kinds that are registered, then the purposes a text call handler is registered for.
     * Voice counts only once it is deployed (AiSpend::voiceDeployed), as it does for every other place:
     * until then it cannot spend, so there is no limit to set for it.
     *
     * @return list<string>
     */
    public function features(): array
    {
        $deployed = array_keys($this->kinds->all());

        foreach (config('modules', []) as $module) {
            foreach (array_keys($module['text_calls'] ?? []) as $purpose) {
                if (is_string($purpose) && ($purpose !== AiSpend::VOICE || $this->spend->voiceDeployed())) {
                    $deployed[] = $purpose;
                }
            }
        }

        return array_values(array_filter($this->known(), fn (string $feature) => in_array($feature, $deployed, true)));
    }

    /** Whether a registered reader reads documents page by page, so a per-read maximum means something. */
    public function hasReadKinds(): bool
    {
        return collect(config('modules', []))->pluck('read_kinds')->filter()->isNotEmpty();
    }

    /**
     * The limits as the settings form shows them: the company's own figure where it has one, and the
     * figure that applies when it has none.
     *
     * @return array{
     *     max_credits_per_read: array{own:?string, platform:string},
     *     features: list<array{key:string, label:string, user:array{own:?string, default:?string}, company:array{own:?string, default:?string}}>,
     *     total: array{own:?string, default:?string}
     * }
     */
    public function limits(Organization $organization): array
    {
        $settings = $this->of($organization);
        $own = fn (string $scope, string $feature) => $this->figure($settings?->budgets[$scope][$feature] ?? null);
        $default = fn (string $scope, string $feature) => $this->figure(config("modules.document_reading.budgets.{$scope}.{$feature}"));

        return [
            'max_credits_per_read' => [
                'own' => $this->figure($settings?->max_credits_per_read),
                'platform' => (string) $this->figure(config('modules.document_reading.max_credits_per_read')),
            ],
            'features' => array_map(fn (string $feature) => [
                'key' => $feature,
                'label' => AiSpend::label($feature),
                'user' => ['own' => $own('user', $feature), 'default' => $default('user', $feature)],
                'company' => ['own' => $own('company', $feature), 'default' => $default('company', $feature)],
            ], $this->features()),
            'total' => ['own' => $own('company', 'total'), 'default' => $default('company', 'total')],
        ];
    }

    /**
     * Saves the company's own limits. An empty figure takes the limit back to the default; a figure
     * for a feature that was not part of the form is left as it was.
     *
     * @param  array{max_credits_per_read?:?string, budgets?:array<string, array<string, ?string>>}  $data  Already validated: amounts of credits, none negative.
     *
     * @throws ValidationException under "budgets", while there is nothing to set a limit for
     */
    public function saveLimits(Organization $organization, User $by, array $data): AiDocumentSettings
    {
        $this->assertOwnCompany($organization, $by);

        if (! $this->limitsOffered($organization)) {
            throw ValidationException::withMessages(['budgets' => __('No reader is switched on yet.')]);
        }

        return DB::transaction(function () use ($organization, $by, $data) {
            $settings = AiDocumentSettings::withoutGlobalScopes()->lockForUpdate()->find(AiDocumentSettings::for($organization)->id);
            $before = ['max_credits_per_read' => $this->figure($settings->max_credits_per_read), 'budgets' => $settings->budgets ?? []];

            $budgets = $settings->budgets ?? [];

            foreach (self::SCOPES as $scope) {
                $allowed = $scope === 'company' ? [...$this->known(), 'total'] : $this->known();

                foreach ($data['budgets'][$scope] ?? [] as $feature => $value) {
                    if (! in_array($feature, $allowed, true)) {
                        continue;
                    }

                    if (blank($value)) {
                        unset($budgets[$scope][$feature]);
                    } else {
                        $budgets[$scope][$feature] = $this->figure($value);
                    }
                }
            }

            $budgets = array_filter($budgets, fn ($figures) => is_array($figures) && $figures !== []);
            $max = array_key_exists('max_credits_per_read', $data) ? $data['max_credits_per_read'] : $settings->max_credits_per_read;
            $max = blank($max) ? null : $this->capped($max);

            $settings->forceFill(['max_credits_per_read' => $max, 'budgets' => $budgets === [] ? null : $budgets])->save();

            $after = ['max_credits_per_read' => $this->figure($settings->max_credits_per_read), 'budgets' => $settings->budgets ?? []];

            if ($after != $before) {
                AuditLog::record('ai_documents.limits_changed', $settings, $before, $after, $by, $organization->id);
            }

            return $settings;
        });
    }

    /**
     * Every feature a budget can be kept for: the reader kinds and the text purposes that have figures.
     *
     * @return list<string>
     */
    private function known(): array
    {
        return [
            ...array_keys(config('modules.document_reading.kinds', [])),
            ...array_keys(config('modules.document_reading.purposes', [])),
        ];
    }

    /** A company may lower the most one read can cost. It can never raise it above the platform's figure. */
    private function capped(mixed $value): string
    {
        $platform = Dec::of(config('modules.document_reading.max_credits_per_read'));
        $asked = Dec::of((string) $value);

        return (string) ($asked->isGreaterThan($platform) ? $platform : $asked)->toScale(4, RoundingMode::Down);
    }

    /** An amount of credits as a person types it: no trailing zeros. Null when there is no usable figure. */
    private function figure(mixed $value): ?string
    {
        if (! is_numeric($value) || Dec::of((string) $value)->isNegative()) {
            return null;
        }

        $plain = (string) Dec::of((string) $value)->toScale(4, RoundingMode::Down);

        return str_contains($plain, '.') ? rtrim(rtrim($plain, '0'), '.') : $plain;
    }

    private function assertOwnCompany(Organization $organization, User $by): void
    {
        if ((int) $by->organization_id !== (int) $organization->id) {
            throw new LogicException('A company\'s reader settings are changed only by its own people.');
        }
    }
}
