<?php

namespace App\Services\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agents\ModelGateway;
use App\Services\Website\AiOffer;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What only the platform admin decides about the readers: the spending limits of the platform,
 * the queues the jobs wait in, whether the readers are offered to companies at all, and what one
 * company is allowed or stopped from doing.
 *
 * Every change is written to the platform's own audit trail, which no company can read.
 */
class ReaderControl
{
    /** Saved as exactly "1" while the readers are offered to companies. Anything else means they are not. */
    public const RELEASED = AiOffer::RELEASED;

    public const MONTHLY_CAP = AiSpend::MONTHLY_CAP;

    public const DAILY_LIMIT = AiSpend::DAILY_LIMIT;

    public const MONTH_SHARE = AiSpend::MONTH_SHARE;

    public const TRIAL_ALLOWANCE = AiSpend::TRIAL_ALLOWANCE;

    /** When the admin last confirmed that spend alerts are set in the provider's console. */
    public const ALERTS_CONFIRMED_AT = 'ai_spend_alerts_confirmed_at';

    /** The readers and text calls whose dry run the admin confirmed when releasing, as a JSON list. */
    public const DRY_RUN_DONE = 'ai_dry_run_done';

    public const RELEASED_AT = 'ai_readers_released_at';

    public function __construct(
        private PlatformSettings $settings,
        private AiSpend $spend,
        private ReaderTrials $trials,
        private ModelGateway $gateway,
    ) {}

    /**
     * The limits on what the provider may be asked for. An empty value puts the default back; the
     * provider's monthly cap has no default and may not be removed while the readers are released,
     * because without it there is no monthly limit to hold to.
     *
     * @param  array{monthly_cap?: ?string, daily_limit?: ?string, month_share?: ?string, trial_allowance?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function saveLimits(User $by, array $data): void
    {
        $cap = $this->amount($data['monthly_cap'] ?? null);
        $daily = $this->amount($data['daily_limit'] ?? null);
        $share = $this->amount($data['month_share'] ?? null);
        $allowance = $this->amount($data['trial_allowance'] ?? null);

        $errors = array_filter([
            'monthly_cap' => match (true) {
                $cap === false || ($cap !== null && ! Dec::of($cap)->isPositive()) => __('Enter the monthly cap in US dollars, as the provider\'s console shows it.'),
                $cap === null && $this->switchedOn() => __('Switch the readers off before you remove the monthly cap.'),
                default => null,
            },
            'daily_limit' => $daily === false || ($daily !== null && ! Dec::of($daily)->isPositive()) ? __('Enter an amount in US dollars above zero, or leave it empty for the usual limit.') : null,
            'month_share' => $share === false || ($share !== null && (! Dec::of($share)->isPositive() || Dec::of($share)->isGreaterThan(1))) ? __('Enter a share between 0 and 1, such as 0.60, or leave it empty for the usual share.') : null,
            'trial_allowance' => $allowance === false ? __('Enter an amount in US dollars, or leave it empty for the usual allowance. Zero switches dry runs off.') : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }

        $this->change($by, [self::MONTHLY_CAP => $cap, self::DAILY_LIMIT => $daily, self::MONTH_SHARE => $share, self::TRIAL_ALLOWANCE => $allowance]);
    }

    /**
     * The queue each lane of reader jobs is sent to. An empty name sends the lane back to the queue
     * the worker already reads.
     *
     * @param  array<string, ?string>  $data  Lane (pages, interactive, prepare) => queue name.
     *
     * @throws ValidationException
     */
    public function saveQueues(User $by, array $data): void
    {
        $values = [];
        $errors = [];

        foreach (ReadQueues::LANES as $lane) {
            $name = trim((string) ($data[$lane] ?? ''));

            if ($name !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,59}$/', $name) !== 1) {
                $errors["queues.{$lane}"] = [__('A queue name may hold letters, digits and - _ . : only.')];
            }

            $values["ai_queue_{$lane}"] = $name === '' ? null : $name;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->change($by, $values);
    }

    /**
     * Offers the readers to companies. Only with the provider's monthly cap on record, the admin's
     * word that spend alerts are set in the provider's console, and the admin's word for every
     * deployed reader and text call that its dry run was done.
     *
     * @param  list<string>  $dryRunDone  The readers and text calls the admin ticked.
     *
     * @throws ValidationException
     */
    public function release(User $by, bool $alertsSet, array $dryRunDone): void
    {
        $missing = array_values(array_diff($this->features(), $dryRunDone));

        $errors = array_filter([
            'monthly_cap' => $this->spend->monthlyCap() === null ? __('Enter the provider\'s monthly cap under Spending limits first.') : null,
            'alerts_set' => $alertsSet ? null : __('Confirm that spend alerts are set in the provider\'s console.'),
            'dry_run' => $missing === [] ? null : __('Confirm the dry run for: :features.', ['features' => implode(', ', array_map(AiSpend::label(...), $missing))]),
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }

        $done = array_values(array_intersect($this->features(), $dryRunDone));

        $this->settings->set([
            self::RELEASED => '1',
            self::RELEASED_AT => now()->toIso8601String(),
            self::ALERTS_CONFIRMED_AT => now()->toIso8601String(),
            self::DRY_RUN_DONE => $done === [] ? null : json_encode($done),
        ]);

        PlatformAudit::record('ai.readers_released', $by, ['dry_run_done' => $done, 'monthly_cap_usd' => (string) $this->spend->monthlyCap()], $by);
    }

    /**
     * Stops offering the readers to companies. Nothing new can be started, and a read that is
     * running pauses before its next page. What was already read stays with each company.
     */
    public function withdraw(User $by): void
    {
        if (! $this->switchedOn()) {
            return;
        }

        $this->settings->set([self::RELEASED => '0', self::RELEASED_AT => null]);

        PlatformAudit::record('ai.readers_withdrawn', $by, null, $by);
    }

    /** Lifts the stop that was set when the provider refused a call, so pages are sent again. */
    public function clearProviderStop(User $by): void
    {
        $standing = ProviderBlock::current();

        if ($standing === null) {
            return;
        }

        ProviderBlock::clear();

        PlatformAudit::record('ai.provider_stop_cleared', $by, ['reason' => ReadAudit::code($standing['reason'])], $by);
    }

    /**
     * What the admin allows or stops for one company:
     * lift_quota (a trial company may use the readers beyond its trial allowance), restore_quota,
     * block (the company can send nothing), unblock, and lift_brake (unusable answers of the last
     * day no longer count against it).
     *
     * @throws ValidationException For an action that does not exist.
     */
    public function company(Organization $organization, string $action, User $by): void
    {
        if ($action === 'lift_brake') {
            $this->spend->liftBrake($organization);
            PlatformAudit::record('ai.company_brake_lifted', $organization, ['organization_id' => $organization->id], $by);

            return;
        }

        $changes = match ($action) {
            'lift_quota' => ['quota_lifted_by' => $by->id, 'quota_lifted_at' => now()],
            'restore_quota' => ['quota_lifted_by' => null, 'quota_lifted_at' => null],
            'block' => ['platform_block' => true],
            'unblock' => ['platform_block' => false],
            default => throw ValidationException::withMessages(['action' => [__('Choose what to change for this company.')]]),
        };

        $settings = AiDocumentSettings::for($organization);
        $before = ['platform_block' => (bool) $settings->platform_block, 'quota_lifted' => $settings->quota_lifted_at !== null];

        $settings->forceFill($changes)->save();

        $after = ['platform_block' => (bool) $settings->platform_block, 'quota_lifted' => $settings->quota_lifted_at !== null];

        if ($after !== $before) {
            PlatformAudit::record('ai.company_'.$action, $settings, ['organization_id' => $organization->id, ...$after], $by, $before);
        }
    }

    /**
     * Every reader and every text call that is deployed and can be given a dry run. Each needs the
     * admin's word that its dry run was done before the readers are released.
     *
     * @return list<string>
     */
    public function features(): array
    {
        $targets = $this->trials->targets();

        return array_values(array_unique([...array_keys($targets['kinds']), ...array_column($targets['purposes'], 'purpose')]));
    }

    /** The switch itself (AiSpend::switchedOn). Whether a company is offered a reader is AiSpend::readersOn, never this alone. */
    public function switchedOn(): bool
    {
        return $this->spend->switchedOn();
    }

    /**
     * Where the release stands, for the admin's screen. "on" is the one test the website, the consent
     * switch, the menu and the phone use (AiSpend::readersOn, asked without a company); the other
     * entries are its parts, so the screen can say which one is missing.
     *
     * @return array{on: bool, switched_on: bool, released: bool, connected: bool, deployed: bool, released_at: ?Carbon, alerts_confirmed_at: ?Carbon, features: list<string>, dry_run_done: list<string>, not_confirmed: list<string>}
     */
    public function releaseState(): array
    {
        $done = json_decode((string) $this->settings->get(self::DRY_RUN_DONE, '[]'), true);
        $done = is_array($done) ? array_values(array_filter($done, is_string(...))) : [];
        $features = $this->features();

        return [
            'on' => $this->spend->readersOn(),
            'switched_on' => $this->switchedOn(),
            'released' => $this->spend->released(),
            'connected' => $this->gateway->isConnected(),
            'deployed' => $this->spend->readerDeployed(),
            'released_at' => $this->moment($this->settings->get(self::RELEASED_AT)),
            'alerts_confirmed_at' => $this->moment($this->settings->get(self::ALERTS_CONFIRMED_AT)),
            'features' => $features,
            'dry_run_done' => $done,
            // Deployed since the release, so nobody has confirmed a dry run for it.
            'not_confirmed' => $this->switchedOn() ? array_values(array_diff($features, $done)) : [],
        ];
    }

    /**
     * The saved values of the limits and the queue names, and the defaults they fall back to.
     *
     * @return array{limits: array<string, array{saved: ?string, usual: ?string}>, queues: array<string, array{saved: ?string, usual: string}>}
     */
    public function saved(): array
    {
        $ceilings = config('modules.document_reading.ceilings');
        $queues = [];

        foreach (ReadQueues::LANES as $lane) {
            $queues[$lane] = ['saved' => $this->text($this->settings->get("ai_queue_{$lane}")), 'usual' => (string) config("modules.document_reading.queues.{$lane}", 'default')];
        }

        return [
            'limits' => [
                'monthly_cap' => ['saved' => $this->text($this->settings->get(self::MONTHLY_CAP)), 'usual' => null],
                'daily_limit' => ['saved' => $this->text($this->settings->get(self::DAILY_LIMIT)), 'usual' => (string) $ceilings['daily_usd']],
                'month_share' => ['saved' => $this->text($this->settings->get(self::MONTH_SHARE)), 'usual' => (string) $ceilings['month_share']],
                'trial_allowance' => ['saved' => $this->text($this->settings->get(self::TRIAL_ALLOWANCE)), 'usual' => (string) $ceilings['trial_daily_usd']],
            ],
            'queues' => $queues,
        ];
    }

    /**
     * Saves platform settings and records which of them changed, with the old and the new value.
     * None of these is a secret.
     *
     * @param  array<string, ?string>  $values
     */
    private function change(User $by, array $values): void
    {
        $before = [];
        $after = [];

        foreach ($values as $key => $value) {
            $old = $this->text($this->settings->get($key));

            if ($old !== $value) {
                $before[$key] = $old;
                $after[$key] = $value;
            }
        }

        if ($after === []) {
            return;
        }

        $this->settings->set($after);

        PlatformAudit::record('ai.platform_settings_changed', $by, $after, $by, $before);
    }

    /** @return string|false|null A plain decimal that is not negative, null for nothing entered, false for anything else. */
    private function amount(mixed $value): string|false|null
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        return preg_match('/^\d{1,9}(\.\d{1,4})?$/', $text) === 1 ? $text : false;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function moment(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }
}
