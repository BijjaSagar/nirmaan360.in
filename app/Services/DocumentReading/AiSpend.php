<?php

namespace App\Services\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agents\ModelGateway;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use App\Services\Website\AiOffer;
use App\Support\Dec;
use App\Support\PlatformSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Every limit on what the readers and the text calls may spend, other than the wallet itself:
 * the release switch, the platform's daily and monthly limits, each company's share and brakes,
 * the trial allowance and the daily budgets.
 *
 * A "feature" is a reader kind (boq, slip, bill, tender) or a text purpose (wording, voice and so on).
 * It runs on the queue as well as in requests, so every query names its company.
 *
 * ai_text_calls and reader_trial_pages come with their own migrations. Where one of those tables
 * does not exist they simply add nothing.
 */
class AiSpend
{
    private const TEXT_CALLS = 'ai_text_calls';

    private const TRIAL_PAGES = 'reader_trial_pages';

    /** The platform settings the platform admin's AI reader page writes (ReaderControl). */
    public const MONTHLY_CAP = 'ai_tier_monthly_cap_usd';

    public const DAILY_LIMIT = 'ai_reader_daily_usd';

    public const MONTH_SHARE = 'ai_reader_month_share';

    public const TRIAL_ALLOWANCE = 'ai_trial_daily_usd';

    public function __construct(private PlatformSettings $settings, private ModelGateway $gateway) {}

    /**
     * The one test of whether the readers are on. The public website (its cards and the pricing line),
     * a company's consent switch and its menu entry, the privacy policy and the terms, the phone's status
     * answer and the offer to read a PDF as a scan all ask this, so they cannot disagree. The gates
     * (RequireAiReader, PageReader, TextCallRunner, assertRoom) check the same parts one at a time,
     * because each part has its own refusal: released(), then the model connected, then the plan, and
     * a reader route exists only once its kind is deployed.
     *
     * On means all of these hold:
     * - the platform admin has released the readers and the provider's monthly cap is on record (released()),
     * - the AI model is connected: a key is saved,
     * - for a company, its plan has the AI features,
     * - a reader is deployed: the kind asked about, or with none any reader kind or the voice notes
     *   route; for a company, a kind counts only when its plan has the module the kind belongs to.
     */
    public function readersOn(?Organization $organization = null, ?string $kind = null): bool
    {
        return $this->aiOn($organization) && $this->readerDeployed($organization, $kind);
    }

    /**
     * The parts of readersOn() that are not about a reader: released, connected and, for a company, its
     * plan. Spending limits ask this with the features that can spend, which may be text calls only.
     */
    public function aiOn(?Organization $organization = null): bool
    {
        return $this->released()
            && $this->gateway->isConnected()
            && ($organization === null || $organization->allowsFeature('ai_agents'));
    }

    /**
     * Whether a reader is deployed: the kind asked about, or with none any reader kind or the voice notes
     * route. For a company, a kind counts only when its plan has the module the kind belongs to.
     */
    public function readerDeployed(?Organization $organization = null, ?string $kind = null): bool
    {
        $allowed = function (string $key) use ($organization): bool {
            $feature = config("modules.document_reading.kinds.{$key}.feature");

            return $organization === null || ! is_string($feature) || $organization->allowsFeature($feature);
        };

        $kinds = array_keys(app(ReadKinds::class)->all());

        if ($kind !== null) {
            return in_array($kind, $kinds, true) && $allowed($kind);
        }

        return array_filter($kinds, $allowed) !== [] || Route::has(AiOffer::VOICE_ROUTE);
    }

    /**
     * Part of readersOn(): the platform admin has switched the readers on, and the provider's monthly cap
     * is on record, since without it there is no monthly limit to hold to. A gate asks this on its own to
     * refuse with not_released.
     */
    public function released(): bool
    {
        return $this->switchedOn() && $this->monthlyCap() !== null;
    }

    /**
     * The release switch alone, as the platform admin left it: saved as exactly "1" while it is on, off
     * by default. Only the platform admin's AI reader page writes it (ReaderControl::release). Nothing
     * outside that page and released() asks it: whether the readers are on is readersOn().
     */
    public function switchedOn(): bool
    {
        return (string) $this->settings->get(AiOffer::RELEASED, '0') === '1';
    }

    /**
     * Whether a platform limit has stopped the readers for every company: 'daily', 'monthly' or null.
     * The four agents are not stopped by either.
     */
    public function platformStop(): ?string
    {
        $today = $this->platformCostToday();
        $daily = $this->dailyCeiling();
        $this->tellAdmins('daily', now('Asia/Kolkata')->toDateString(), $today, $daily);

        if ($today->isGreaterThanOrEqualTo($daily)) {
            return 'daily';
        }

        $limit = $this->monthlyLimit();

        if ($limit === null) {
            return null;
        }

        $month = $this->platformCostThisMonth();
        $this->tellAdmins('monthly', now('UTC')->format('Y-m'), $month, $limit);

        return $month->isGreaterThanOrEqualTo($limit) ? 'monthly' : null;
    }

    /**
     * Why this one company may not send anything right now, or null:
     * 'blocked' (by the platform admin), 'share' (its part of the platform's day), 'absorbed'
     * (cost it caused without being charged), 'brake' (too many unusable answers in 24 hours),
     * 'trial_pages' or 'trial_text_calls' (the trial allowance is used).
     *
     * @param  DocumentRead|DocumentReadPage|null  $for  What is about to be sent or started. A page that was
     *                                                   already sent once may be finished after the trial's
     *                                                   pages are used up: its second pass, a half, a retry.
     */
    public function companyStop(Organization $organization, bool $textCall = false, DocumentRead|DocumentReadPage|null $for = null): ?string
    {
        $settings = $this->settingsOf($organization);

        if ($settings?->platform_block) {
            return 'blocked';
        }

        $share = $this->dailyCeiling()->multipliedBy(Dec::of(config('modules.document_reading.company_share_of_ceiling')));

        if ($this->costToday($organization->id)->isGreaterThanOrEqualTo($share)) {
            return 'share';
        }

        if ($this->absorbedToday($organization)->isGreaterThanOrEqualTo($this->absorbedCap($organization))) {
            return 'absorbed';
        }

        if ($this->unusableAnswers($organization) >= (int) config('modules.document_reading.failed_pages_per_day')) {
            return 'brake';
        }

        if ($textCall && $this->trialTextCallsLeft($organization) === 0) {
            return 'trial_text_calls';
        }

        if (! $textCall && $this->trialPagesLeft($organization) === 0 && ! $this->alreadySent($organization, $for)) {
            return 'trial_pages';
        }

        return null;
    }

    /**
     * For the worker about to send a page: the reason to pause the read with, or null to go on.
     * 'platform_ceiling' stops every company, 'company_limit' this one.
     */
    public function pauseReason(Organization $organization, ?DocumentReadPage $page = null): ?string
    {
        if ($this->platformStop() !== null) {
            return 'platform_ceiling';
        }

        return $this->companyStop($organization, false, $page) === null ? null : 'company_limit';
    }

    /**
     * Checked before credits are put on hold for a read (pass the read) or before a text call is made.
     *
     * @param  User  $bookedTo  The person the read or the call is booked to. What it holds and is charged
     *                          counts against this person's own daily budget, so this is the budget checked.
     * @param  string  $credits  What is about to be held: a read's limit, or a text call's maximum.
     * @param  ?DocumentRead  $for  The read the hold is for. What it already holds is not counted twice.
     * @param  ?User  $by  The person acting now, who reads the refusal. Null when that is $bookedTo.
     *                     When it is someone else, the refusal names whose budget is used up.
     *
     * @throws SpendRefused
     */
    public function assertRoom(Organization $organization, User $bookedTo, string $feature, string $credits, ?DocumentRead $for = null, ?User $by = null): void
    {
        $asked = Dec::of($credits);

        if (! $asked->isPositive()) {
            throw new InvalidArgumentException('A hold is for more than nothing.');
        }

        if (! $this->released()) {
            throw new SpendRefused(SpendRefused::NOT_RELEASED, 409, __('The readers are not switched on yet.'));
        }

        if (($period = $this->platformStop()) !== null) {
            throw new SpendRefused(SpendRefused::READER_PAUSED, 409, $period === 'monthly'
                ? __('The reader is paused for this month.')
                : __('The reader is paused for today. Try again tomorrow.'), ['period' => $period]);
        }

        if (($limit = $this->companyStop($organization, $for === null, $for)) !== null) {
            throw new SpendRefused(SpendRefused::COMPANY_LIMIT, 409, $this->companyStopWords($limit), ['limit' => $limit]);
        }

        $own = $this->budget($organization, 'user', $feature);

        if ($own !== null && $this->spentToday($organization, $feature, $bookedTo, $for)->plus($asked)->isGreaterThan(Dec::of($own))) {
            // An owner acting on someone's own read is told it is that person's budget, not their own.
            $theirs = $by !== null && (int) $by->id !== (int) $bookedTo->id;

            throw new SpendRefused(SpendRefused::DAILY_BUDGET, 402, $theirs
                ? __(':name has used their AI budget for :feature today, and this document counts against that budget. The budget starts again tomorrow.', ['name' => $bookedTo->name, 'feature' => self::label($feature)])
                : __('You have used your AI budget for :feature today. It starts again tomorrow.', ['feature' => self::label($feature)]),
                ['feature' => $feature, 'scope' => 'user']);
        }

        foreach ([$feature, 'total'] as $key) {
            $shared = $this->budget($organization, 'company', $key);

            if ($shared !== null && $this->spentToday($organization, $key, null, $for)->plus($asked)->isGreaterThan(Dec::of($shared))) {
                throw new SpendRefused(SpendRefused::DAILY_BUDGET, 402,
                    __('Your company has used its AI budget for :feature today. An owner or admin can change the budget in Settings.', ['feature' => self::label($key)]),
                    ['feature' => $key, 'scope' => 'company']);
            }
        }
    }

    /**
     * A daily budget in credits, or null when there is none. The company's own figure wins over the default.
     *
     * @param  string  $scope  'user' or 'company'
     * @param  string  $feature  A reader kind, a text purpose, or 'total' (company scope only).
     */
    public function budget(Organization $organization, string $scope, string $feature): ?string
    {
        $usable = fn (mixed $value) => is_numeric($value) && ! Dec::of((string) $value)->isNegative();
        $own = $this->settingsOf($organization)?->budgets[$scope][$feature] ?? null;
        // A figure of the company's that cannot be read as an amount never removes a limit: the default stands.
        $value = $usable($own) ? $own : config("modules.document_reading.budgets.{$scope}.{$feature}");

        return $usable($value) ? (string) Dec::of((string) $value)->toScale(4, RoundingMode::Down) : null;
    }

    /**
     * Credits counted against a daily budget so far: charged since midnight in India, plus what is on hold now.
     *
     * Each page call counts on the day it was made, for the person its read was booked to then: a page
     * read again today does not bring yesterday's charge into today.
     *
     * @param  string  $feature  'total' counts every feature.
     * @param  ?User  $user  Only what is booked to this person.
     * @param  ?DocumentRead  $except  A read whose present hold is about to be replaced.
     */
    public function spentToday(Organization $organization, string $feature, ?User $user = null, ?DocumentRead $except = null): BigDecimal
    {
        $feature = $feature === 'total' ? null : $feature;
        $from = $this->dayStart();

        $total = Dec::of(DB::table('document_read_attempts')
            ->where('organization_id', $organization->id)
            ->where('finished_at', '>=', $from)
            ->when($feature, fn ($query) => $query->where('kind', $feature))
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->sum('credits'));

        $total = $total->plus(Dec::of(DB::table('document_reads')
            ->where('organization_id', $organization->id)
            ->where('status', DocumentRead::RUNNING)
            ->when($feature, fn ($query) => $query->where('kind', $feature))
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->when($except, fn ($query) => $query->where('id', '!=', $except->id))
            ->sum('credits_held')));

        if ($this->has(self::TEXT_CALLS)) {
            $calls = fn () => DB::table(self::TEXT_CALLS)
                ->where('organization_id', $organization->id)
                ->when($feature, fn ($query) => $query->where('purpose', $feature))
                ->when($user, fn ($query) => $query->where('user_id', $user->id));

            $total = $total
                ->plus(Dec::of($calls()->where('created_at', '>=', $from)->sum('credits')))
                ->plus(Dec::of($calls()->whereIn('status', ['queued', 'running'])->sum('max_credits')));
        }

        return $total->toScale(4, RoundingMode::HalfUp);
    }

    /** The most a text call that is waiting or running may still cost this company. Part of what the wallet holds back. */
    public function heldByTextCalls(Organization $organization): BigDecimal
    {
        if (! $this->has(self::TEXT_CALLS)) {
            return BigDecimal::zero();
        }

        return Dec::of(DB::table(self::TEXT_CALLS)
            ->where('organization_id', $organization->id)
            ->whereIn('status', ['queued', 'running'])
            ->sum('max_credits'));
    }

    /**
     * Run after credits were held or charged. Tells the owners once a day at each step of a company
     * budget, and once a day when little is left in the wallet. It runs after the transaction commits
     * and can never undo the charge that caused it.
     *
     * @param  string  $free  The wallet's free balance after the hold or the charge.
     */
    public function afterSpend(Organization $organization, string $feature, string $free): void
    {
        $this->notifier()->whenCommitted(function () use ($organization, $feature, $free) {
            foreach ([$feature, 'total'] as $key) {
                $this->tellOwnersAboutBudget($organization, $key);
            }

            $low = Dec::of(config('modules.document_reading.low_balance_credits'));

            if (Dec::of($free)->isLessThan($low) && $this->firstToday("ai.low_balance:{$organization->id}")) {
                $this->tellOwners($organization, fn (string $locale) => new NotificationMessage(
                    event: 'ai.low_credits',
                    title: __('AI credits are running low', [], $locale),
                    // A phone shows this as a push, so it never says buy and carries no link to the page that sells
                    // credits (App Store guideline 3.1.1; see PlanWording). It names no reader before one is offered.
                    body: __('Fewer than :credits AI credits are free to use. When none are left, AI work stops until more credits are added.', ['credits' => Dec::indian($low, 0)], $locale),
                ));
            }
        });
    }

    /** On a trial, with the allowance not lifted by the platform admin. */
    public function onTrial(Organization $organization): bool
    {
        return $organization->plan_status === 'trial' && $this->settingsOf($organization)?->quota_lifted_at === null;
    }

    /** Reader pages a trial company may still send, or null when no allowance applies. A page read in two passes or two halves counts once. */
    public function trialPagesLeft(Organization $organization): ?int
    {
        if (! $this->onTrial($organization)) {
            return null;
        }

        $sent = DB::query()->fromSub(
            DB::table('document_read_pages')->select('document_read_id', 'page_no')->distinct()
                ->where('organization_id', $organization->id)->where('attempts', '>', 0),
            'sent',
        )->count();

        return max(0, (int) config('modules.document_reading.trial.pages') - $sent);
    }

    /**
     * The numbers of the pages of a read that have been sent to the reader at least once. They are
     * already counted in a trial's allowance, so finishing them takes nothing more from it.
     *
     * @return list<int>
     */
    public function pagesAlreadySent(DocumentRead $read): array
    {
        return DB::table('document_read_pages')
            ->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id)
            ->where('attempts', '>', 0)
            ->distinct()->pluck('page_no')->map(fn ($number) => (int) $number)->all();
    }

    /** Text calls a trial company may still make, or null when no allowance applies. */
    public function trialTextCallsLeft(Organization $organization): ?int
    {
        if (! $this->onTrial($organization)) {
            return null;
        }

        $made = $this->has(self::TEXT_CALLS) ? DB::table(self::TEXT_CALLS)->where('organization_id', $organization->id)->count() : 0;

        return max(0, (int) config('modules.document_reading.trial.text_calls') - $made);
    }

    /** A second try with the stronger, dearer model is never offered on a trial. */
    public function strongerModelAllowed(Organization $organization): bool
    {
        return ! $this->onTrial($organization);
    }

    /** The most one read of this company may cost: the platform's figure, lowered by the owner or by a trial. */
    public function maxCreditsPerRead(Organization $organization): string
    {
        $max = Dec::of(config('modules.document_reading.max_credits_per_read'));
        $own = $this->settingsOf($organization)?->max_credits_per_read;

        if ($own !== null && Dec::of($own)->isPositive() && Dec::of($own)->isLessThan($max)) {
            $max = Dec::of($own);
        }

        $trial = Dec::of(config('modules.document_reading.trial.max_credits_per_read'));

        if ($this->onTrial($organization) && $trial->isLessThan($max)) {
            $max = $trial;
        }

        return (string) $max->toScale(4, RoundingMode::Down);
    }

    /** The platform admin lets a company go on after the brake: answers before this moment no longer count. */
    public function liftBrake(Organization $organization): void
    {
        Cache::put($this->brakeKey($organization), now()->getTimestamp(), now()->addDay());
    }

    /**
     * Unusable answers this company caused in the last 24 hours: page calls the model answered badly,
     * and text the guards refused. Each page call counts when it was made, so a page that failed
     * earlier and is read again now brings none of its earlier answers into the count.
     */
    public function unusableAnswers(Organization $organization): int
    {
        $since = now()->subDay();
        $lifted = Cache::get($this->brakeKey($organization));

        // Everything up to and including the second of the lift is forgiven.
        if (is_int($lifted) && $lifted >= $since->getTimestamp()) {
            $since = Carbon::createFromTimestamp($lifted + 1, config('app.timezone'));
        }

        $count = DB::table('document_read_attempts')
            ->where('organization_id', $organization->id)
            ->where('finished_at', '>=', $since)
            ->where('behaviour_failure', true)
            ->count();

        if ($this->has(self::TEXT_CALLS)) {
            $count += DB::table(self::TEXT_CALLS)
                ->where('organization_id', $organization->id)
                ->where('status', 'rejected')
                ->where('created_at', '>=', $since)
                ->count();
        }

        return $count;
    }

    /**
     * Provider cost, in US dollars, this company caused today without being charged for it. A page
     * call counts on the day it was made, whatever day its read started or moves again; a text call
     * counts on the day it was made, with every attempt nobody paid for and what its charge did not cover.
     */
    public function absorbedToday(Organization $organization): BigDecimal
    {
        $from = $this->dayStart();

        $total = Dec::of(DB::table('document_read_attempts')
            ->where('organization_id', $organization->id)
            ->where('finished_at', '>=', $from)
            ->sum('absorbed_usd'));

        if ($this->has(self::TEXT_CALLS)) {
            $total = $total->plus(Dec::of(DB::table(self::TEXT_CALLS)
                ->where('organization_id', $organization->id)
                ->where('created_at', '>=', $from)
                ->sum('absorbed_usd')));
        }

        return $total;
    }

    /** What every company's readers and text calls have cost the platform today, in US dollars. */
    public function platformCostToday(): BigDecimal
    {
        return $this->remembered('day:'.now('Asia/Kolkata')->toDateString(), fn () => $this->costToday(null));
    }

    /**
     * What the provider has been asked for this calendar month, in US dollars: agents, readers, text
     * calls and the admin's own dry runs. The provider counts its month in UTC, so this does too.
     * Each page call counts in the month it was made, so a page read again does not bring last
     * month's cost into this one.
     */
    public function platformCostThisMonth(): BigDecimal
    {
        return $this->remembered('month:'.now('UTC')->format('Y-m'), function () {
            $from = now('UTC')->startOfMonth()->setTimezone(config('app.timezone'));

            $total = Dec::of(DB::table('agent_steps')->where('created_at', '>=', $from)->sum('cost_usd'))
                ->plus(Dec::of(DB::table('document_read_attempts')->where('finished_at', '>=', $from)->sum('cost_usd')));

            // A text call is dated by when it was made. A dry-run page is uploaded first and sent later, so it is dated by when it was sent.
            foreach ([self::TEXT_CALLS => 'created_at', self::TRIAL_PAGES => 'finished_at'] as $table => $dated) {
                if ($this->has($table)) {
                    $total = $total->plus(Dec::of(DB::table($table)->where($dated, '>=', $from)->sum('cost_usd')));
                }
            }

            return $total;
        });
    }

    /**
     * What the platform admin's own dry runs may cost in a day, in US dollars. It stands apart from
     * the companies' daily limit: a dry run uses none of theirs, and they use none of this. Zero
     * switches dry runs off.
     */
    public function trialDailyAllowance(): BigDecimal
    {
        $saved = $this->settings->get(self::TRIAL_ALLOWANCE);

        return is_numeric($saved) && ! Dec::of((string) $saved)->isNegative()
            ? Dec::of((string) $saved)
            : Dec::of(config('modules.document_reading.ceilings.trial_daily_usd'));
    }

    /** What the dry runs have cost since midnight in India, in US dollars, estimated cost of unanswered calls included. */
    public function trialCostToday(): BigDecimal
    {
        if (! $this->has(self::TRIAL_PAGES)) {
            return BigDecimal::zero();
        }

        return Dec::of(DB::table(self::TRIAL_PAGES)->where('finished_at', '>=', $this->dayStart())->sum('cost_usd'));
    }

    /**
     * Why the platform admin's dry runs may not send anything now, or null: 'trial_allowance' (their own
     * daily allowance is used) or 'monthly' (the month's limit for the readers is reached; a dry run holds
     * to it as every reader does, so the agents keep their part). The companies' daily limit does not
     * apply: a dry run uses none of it. The platform admin hears at each step of either limit.
     */
    public function trialStop(): ?string
    {
        $today = $this->trialCostToday();
        $allowance = $this->trialDailyAllowance();
        $this->tellAdmins('trial', now('Asia/Kolkata')->toDateString(), $today, $allowance);

        if ($today->isGreaterThanOrEqualTo($allowance)) {
            return 'trial_allowance';
        }

        $limit = $this->monthlyLimit();

        if ($limit === null) {
            return null;
        }

        $month = $this->platformCostThisMonth();
        $this->tellAdmins('monthly', now('UTC')->format('Y-m'), $month, $limit);

        return $month->isGreaterThanOrEqualTo($limit) ? 'monthly' : null;
    }

    public function dailyCeiling(): BigDecimal
    {
        return $this->positive($this->settings->get(self::DAILY_LIMIT)) ?? Dec::of(config('modules.document_reading.ceilings.daily_usd'));
    }

    /** The provider's monthly cap as the platform admin typed it, or null when it has not been entered. */
    public function monthlyCap(): ?BigDecimal
    {
        return $this->positive($this->settings->get(self::MONTHLY_CAP));
    }

    /** The part of the monthly cap the readers may bring the month's total up to, or null without a cap. */
    public function monthlyLimit(): ?BigDecimal
    {
        $share = $this->positive($this->settings->get(self::MONTH_SHARE)) ?? Dec::of(config('modules.document_reading.ceilings.month_share'));

        return $this->monthlyCap()?->multipliedBy($share);
    }

    /** How a feature is named in a notice or a refusal. */
    public static function label(string $feature, ?string $locale = null): string
    {
        return match ($feature) {
            'boq' => __('scanned BOQ pages', [], $locale),
            'slip' => __('challan photos', [], $locale),
            'bill' => __('supplier bill photos', [], $locale),
            'tender' => __('tender documents', [], $locale),
            'voice' => __('voice reports', [], $locale),
            'wording' => __('letters and explanations', [], $locale),
            'rate_match' => __('rate suggestions', [], $locale),
            'hindrance_groups' => __('hindrance grouping', [], $locale),
            'translate' => __('translations', [], $locale),
            'total' => __('all AI features', [], $locale),
            default => $feature,
        };
    }

    private function companyStopWords(string $limit): string
    {
        return match ($limit) {
            'blocked' => __('The readers are switched off for your company. Please contact support.'),
            'brake' => __('Too many pages could not be read in the last day, so the readers are paused for your company. Try again tomorrow or contact support.'),
            'trial_pages', 'trial_text_calls' => __('The AI allowance of your trial is used up.'),
            default => __('Your company has reached today\'s limit for the readers. Try again tomorrow.'),
        };
    }

    /** Whether this page, or any page of this read, has been with the reader before. */
    private function alreadySent(Organization $organization, DocumentRead|DocumentReadPage|null $for): bool
    {
        if ($for === null) {
            return false;
        }

        return DB::table('document_read_pages')
            ->where('organization_id', $organization->id)
            ->where('attempts', '>', 0)
            ->when($for instanceof DocumentReadPage,
                fn ($query) => $query->where('document_read_id', $for->document_read_id)->where('page_no', $for->page_no),
                fn ($query) => $query->where('document_read_id', $for->id))
            ->exists();
    }

    private function absorbedCap(Organization $organization): BigDecimal
    {
        return Dec::of(config('modules.document_reading.absorbed_usd_per_day.'.($organization->plan_status === 'trial' ? 'trial' : 'paying')));
    }

    /** Page calls and text calls made since midnight in India, for one company or for all. */
    private function costToday(?int $organizationId): BigDecimal
    {
        $from = $this->dayStart();

        $total = Dec::of(DB::table('document_read_attempts')
            ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
            ->where('finished_at', '>=', $from)
            ->sum('cost_usd'));

        if ($this->has(self::TEXT_CALLS)) {
            $total = $total->plus(Dec::of(DB::table(self::TEXT_CALLS)
                ->when($organizationId, fn ($query) => $query->where('organization_id', $organizationId))
                ->where('created_at', '>=', $from)
                ->sum('cost_usd')));
        }

        return $total;
    }

    private function tellOwnersAboutBudget(Organization $organization, string $feature): void
    {
        $budget = $this->budget($organization, 'company', $feature);

        if ($budget === null || ! Dec::of($budget)->isPositive()) {
            return;
        }

        $spent = $this->spentToday($organization, $feature);
        $steps = config('modules.document_reading.budget_notice_percents');
        rsort($steps);

        foreach ($steps as $index => $percent) {
            if ($spent->multipliedBy(100)->isLessThan(Dec::of($budget)->multipliedBy($percent))) {
                continue;
            }

            $first = $this->firstToday("ai.budget_notice:{$organization->id}:{$feature}:{$percent}");

            // A lower step that was jumped over is not announced after the higher one.
            foreach (array_slice($steps, $index + 1) as $lower) {
                $this->firstToday("ai.budget_notice:{$organization->id}:{$feature}:{$lower}");
            }

            if ($first) {
                $this->tellOwners($organization, fn (string $locale) => $this->budgetNotice($feature, (int) $percent, (string) $spent, $budget, $locale));
            }

            return;
        }
    }

    private function budgetNotice(string $feature, int $percent, string $spent, string $budget, string $locale): NotificationMessage
    {
        $words = ['feature' => self::label($feature, $locale), 'spent' => Dec::indian($spent, 0), 'budget' => Dec::indian($budget, 0), 'percent' => $percent];

        if ($percent >= 100) {
            return new NotificationMessage(
                event: 'ai.budget_notice',
                title: __('Today\'s AI budget for :feature is used up', $words, $locale),
                body: __('The :budget credits set for today are used. This starts again tomorrow, or an owner or admin can change the budget in Settings.', $words, $locale),
            );
        }

        return new NotificationMessage(
            event: 'ai.budget_notice',
            title: __(':percent% of today\'s AI budget for :feature is used', $words, $locale),
            body: __('About :spent of the :budget credits set for today are used or on hold. When the budget is used up, this stops until tomorrow.', $words, $locale),
        );
    }

    /** @param  Closure(string): NotificationMessage  $write  Given a language, writes the notice in it. */
    private function tellOwners(Organization $organization, Closure $write): void
    {
        $owners = User::where('organization_id', $organization->id)->where('role', 'owner')->whereNull('custom_role_id')->where('is_active', true)->orderBy('id')->get();

        foreach ($owners as $owner) {
            $this->notifier()->send($owner, $write);
        }
    }

    /**
     * Platform admins hear at each step of a limit, and when it stops the readers: once per step and
     * period. Nothing is marked as told unless the surrounding work commits.
     */
    private function tellAdmins(string $which, string $period, BigDecimal $spent, BigDecimal $limit): void
    {
        $steps = [100, ...config('modules.document_reading.ceiling_notice_percents')];
        rsort($steps);

        $reached = array_values(array_filter($steps, fn ($percent) => $limit->isPositive() && $spent->multipliedBy(100)->isGreaterThanOrEqualTo($limit->multipliedBy($percent))));

        if ($reached === []) {
            return;
        }

        $this->notifier()->whenCommitted(function () use ($which, $period, $spent, $limit, $reached) {
            $first = false;

            // The highest step reached is the one announced. A lower one that was jumped over is not sent after it.
            foreach ($reached as $index => $percent) {
                $added = Cache::add("ai.ceiling_notice:{$which}:{$period}:{$percent}", 1, now()->addDays(32));
                $first = $index === 0 ? $added : $first;
            }

            if ($first) {
                $this->sendToAdmins($which, (int) $reached[0], $spent, $limit);
            }
        });
    }

    private function sendToAdmins(string $which, int $percent, BigDecimal $spent, BigDecimal $limit): void
    {
        $admins = User::where('is_active', true)
            ->where(fn ($query) => $query->where('is_platform_admin', true)->orWhereIn('email', config('buildos.platform_admins')))
            ->orderBy('id')->get()->filter->isPlatformAdmin();

        $words = ['percent' => $percent, 'spent' => (string) $spent->toScale(2, RoundingMode::HalfUp), 'limit' => (string) $limit->toScale(2, RoundingMode::HalfUp)];

        foreach ($admins as $admin) {
            $this->notifier()->send($admin, function (string $locale) use ($which, $percent, $words) {
                $title = match (true) {
                    $percent >= 100 && $which === 'trial' => __('Dry runs have stopped: today\'s allowance for them is used', [], $locale),
                    $which === 'trial' => __('Dry runs have used :percent% of today\'s allowance', $words, $locale),
                    $percent >= 100 && $which === 'monthly' => __('The AI readers have stopped: this month\'s spending limit is reached', [], $locale),
                    $percent >= 100 => __('The AI readers have stopped: today\'s spending limit is reached', [], $locale),
                    $which === 'monthly' => __('AI spending has reached :percent% of this month\'s limit', $words, $locale),
                    default => __('AI reader spending has reached :percent% of today\'s limit', $words, $locale),
                };

                return new NotificationMessage(
                    event: 'ai.platform_limit',
                    title: $title,
                    body: $which === 'trial'
                        ? __('Spent on dry runs today: :spent US dollars of :limit. At the allowance no dry-run page is sent until tomorrow; companies\' reads are not affected.', $words, $locale)
                        : __('Spent so far: :spent US dollars of :limit. At the limit no new page or text call is sent; the agents keep running.', $words, $locale),
                    url: Route::has('ai-reader.admin.index') ? route('ai-reader.admin.index') : null,
                    critical: $percent >= 100,
                );
            });
        }
    }

    private function firstToday(string $key): bool
    {
        return Cache::add($key.':'.now('Asia/Kolkata')->toDateString(), 1, now()->addDays(2));
    }

    private function brakeKey(Organization $organization): string
    {
        return "ai.brake_lifted:{$organization->id}";
    }

    private function settingsOf(Organization $organization): ?AiDocumentSettings
    {
        return AiDocumentSettings::withoutGlobalScopes()->where('organization_id', $organization->id)->first();
    }

    /** Midnight in India, in the timezone the database rows are written in. */
    private function dayStart(): Carbon
    {
        return now('Asia/Kolkata')->startOfDay()->setTimezone(config('app.timezone'));
    }

    private function positive(mixed $value): ?BigDecimal
    {
        return is_numeric($value) && Dec::of((string) $value)->isPositive() ? Dec::of((string) $value) : null;
    }

    /** Totals over every company are scanned at most once in a short while; a limit that is a minute late is still a limit. */
    private function remembered(string $key, callable $sum): BigDecimal
    {
        $seconds = (int) config('modules.document_reading.ceilings.cache_seconds');

        if ($seconds <= 0) {
            return $sum();
        }

        return Dec::of(Cache::remember("ai.platform_cost:{$key}", $seconds, fn () => (string) $sum()));
    }

    /** A table from a later part of the module. Once seen it is not looked for again in this request or job. */
    private function has(string $table): bool
    {
        $known = "document_reading.has_table.{$table}";

        if (app()->bound($known)) {
            return true;
        }

        if (! Schema::hasTable($table)) {
            return false;
        }

        app()->instance($known, true);

        return true;
    }

    private function notifier(): Notifier
    {
        return app(Notifier::class);
    }
}
