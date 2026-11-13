<?php

namespace App\Services\DocumentReading;

use App\Models\Organization;
use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use App\Services\Agents\ModelGateway;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures on the platform admin's AI reader page: whether the worker and the scheduler are
 * alive, what the provider has been asked for against the limits, what each company used, and what
 * was measured for each reader.
 *
 * It reads across every company, so it is for platform admin routes only. It reads counts, tokens,
 * costs and times; never a page, a result or a file name.
 */
class ReaderDashboard
{
    /** The measured figures look back this many days. */
    public const DAYS = 30;

    private const MOST_COMPANIES = 50;

    private const MOST_TRIAL_PAGES = 5000;

    /** The scheduler runs every minute. A round this late means it is not running. */
    private const TEND_LATE_SECONDS = 180;

    /** A page queued this long has no worker reading its queue. */
    private const QUEUE_LATE_SECONDS = 300;

    public function __construct(
        private AiSpend $spend,
        private ReadKinds $kinds,
        private ReadQuote $quote,
        private ReadQueues $queues,
        private ModelGateway $gateway,
    ) {}

    /**
     * Whether pages can be sent at all, and whether the worker and the scheduler are doing their part.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $lastTend = ReadTending::lastRun();
        $oldest = collect([ReadTending::oldestQueuedSince(), TextCallRunner::oldestQueuedSince(), TrialRunner::oldestQueuedSince()])->filter()->min();

        return [
            'connected' => $this->gateway->isConnected(),
            'stop' => ProviderBlock::current(),
            'stop_until' => ProviderBlock::until(),
            'last_tend' => $lastTend,
            'tend_late' => $lastTend === null || $lastTend->lt(now()->subSeconds(self::TEND_LATE_SECONDS)),
            'oldest_queued' => $oldest,
            'queue_late' => $oldest !== null && $oldest->lt(now()->subSeconds(self::QUEUE_LATE_SECONDS)),
            'queues' => collect(ReadQueues::LANES)->mapWithKeys(fn (string $lane) => [$lane => $this->queues->name($lane)])->all(),
            'can_redraw' => function_exists('imagecreatefromstring') && function_exists('imagejpeg'),
        ];
    }

    /**
     * What the provider has been asked for, in US dollars, against each limit.
     *
     * @return array{month: string, cap: ?string, limit: ?string, today: string, daily: string, trial_today: string, trial_allowance: string}
     */
    public function spend(): array
    {
        $usd = fn ($amount) => $amount === null ? null : (string) $amount->toScale(2, RoundingMode::HalfUp);

        return [
            'month' => $usd($this->spend->platformCostThisMonth()),
            'cap' => $usd($this->spend->monthlyCap()),
            'limit' => $usd($this->spend->monthlyLimit()),
            'today' => $usd($this->spend->platformCostToday()),
            'daily' => $usd($this->spend->dailyCeiling()),
            'trial_today' => $usd($this->spend->trialCostToday()),
            'trial_allowance' => $usd($this->spend->trialDailyAllowance()),
        ];
    }

    /**
     * What each company's readers and text calls did this calendar month (the provider's month, in
     * UTC), and what the platform admin has allowed or stopped for it. Companies with use come
     * first; a company found by the search is added even with none.
     *
     * @param  ?string  $search  A company's name, or part of it, or its number.
     * @return Collection<int, array<string, mixed>>
     */
    public function companies(?string $search = null): Collection
    {
        $from = now('UTC')->startOfMonth()->setTimezone(config('app.timezone'));

        // Pages by how they ended this month; what they cost, charged and absorbed by each call made this
        // month, the way the limits count it (AiSpend), so a page read again does not bring an old cost in.
        $pages = DB::table('document_read_pages')->where('finished_at', '>=', $from)->groupBy('organization_id')
            ->selectRaw("organization_id, COUNT(*) as pages, SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_pages, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_pages")
            ->get()->keyBy('organization_id');

        $attempts = DB::table('document_read_attempts')->where('finished_at', '>=', $from)->groupBy('organization_id')
            ->selectRaw('organization_id, SUM(cost_usd) as cost_usd, SUM(credits) as credits, SUM(absorbed_usd) as absorbed_usd')
            ->get()->keyBy('organization_id');

        $calls = DB::table('ai_text_calls')->where('created_at', '>=', $from)->groupBy('organization_id')
            ->selectRaw('organization_id, COUNT(*) as calls, SUM(cost_usd) as cost_usd, SUM(credits) as credits, SUM(absorbed_usd) as absorbed_usd')
            ->get()->keyBy('organization_id');

        $settings = DB::table('ai_document_settings')
            ->where(fn ($query) => $query->where('enabled', true)->orWhere('platform_block', true)->orWhereNotNull('quota_lifted_at'))
            ->get(['organization_id', 'enabled', 'platform_block', 'quota_lifted_at'])->keyBy('organization_id');

        // The marks that mean "anything" in a search pattern are taken out, so what was typed is looked for as it stands.
        $search = trim(str_replace(['%', '_', '\\'], '', (string) $search));
        $found = $search === '' ? collect() : Organization::query()
            ->where(fn ($query) => $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])->when(ctype_digit($search), fn ($inner) => $inner->orWhere('id', (int) $search)))
            ->orderBy('name')->limit(10)->pluck('id');

        $ids = $found->merge($pages->keys())->merge($attempts->keys())->merge($calls->keys())->merge($settings->keys())->map(fn ($id) => (int) $id)->unique();
        $organizations = Organization::query()->whereIn('id', $ids->all())->get()->keyBy('id');
        $figure = fn ($value) => Dec::of((string) ($value ?? 0));

        $rows = $ids->filter(fn (int $id) => $organizations->has($id))->map(function (int $id) use ($organizations, $pages, $attempts, $calls, $settings, $figure, $found) {
            $organization = $organizations[$id];
            $page = $pages[$id] ?? null;
            $attempt = $attempts[$id] ?? null;
            $call = $calls[$id] ?? null;
            $own = $settings[$id] ?? DB::table('ai_document_settings')->where('organization_id', $id)->first(['organization_id', 'enabled', 'platform_block', 'quota_lifted_at']);
            $cost = $figure($attempt?->cost_usd)->plus($figure($call?->cost_usd));

            return [
                'organization' => $organization,
                'searched' => $found->contains($id),
                'pages' => (int) ($page?->pages ?? 0),
                'read_pages' => (int) ($page?->read_pages ?? 0),
                'failed_pages' => (int) ($page?->failed_pages ?? 0),
                'text_calls' => (int) ($call?->calls ?? 0),
                'cost' => $cost,
                'cost_usd' => (string) $cost->toScale(4, RoundingMode::HalfUp),
                'credits' => (string) $figure($attempt?->credits)->plus($figure($call?->credits))->toScale(2, RoundingMode::HalfUp),
                'absorbed_usd' => (string) $figure($attempt?->absorbed_usd)->plus($figure($call?->absorbed_usd))->toScale(4, RoundingMode::HalfUp),
                'consent' => (bool) ($own?->enabled ?? false),
                'blocked' => (bool) ($own?->platform_block ?? false),
                'quota_lifted' => ($own?->quota_lifted_at ?? null) !== null,
                'on_trial' => $organization->plan_status === 'trial',
                'trial_pages_left' => $this->spend->trialPagesLeft($organization),
                'unusable_answers' => $this->spend->unusableAnswers($organization),
            ];
        });

        // A company that was searched for first, then by what it cost, then by name.
        return $rows->sort(fn (array $a, array $b) => ($b['searched'] <=> $a['searched'])
            ?: ($b['cost']->compareTo($a['cost']) ?: strcmp($a['organization']->name, $b['organization']->name)))
            ->take(self::MOST_COMPANIES)->values();
    }

    /**
     * For every pass of every deployed reader: the figures it runs with, what a call usually
     * writes, and what companies' pages of the last days showed. These are the numbers the dry run
     * is there to replace with measured ones.
     *
     * @return list<array<string, mixed>>
     */
    public function readers(): array
    {
        $since = now()->subDays(self::DAYS);

        $measured = DB::table('document_read_pages as p')
            ->join('document_reads as r', 'r.id', '=', 'p.document_read_id')
            ->where('p.finished_at', '>=', $since)
            ->groupBy('r.kind', 'p.pass')
            ->selectRaw("r.kind as kind, p.pass as pass, COUNT(*) as pages, SUM(CASE WHEN p.status = 'read' THEN 1 ELSE 0 END) as read_pages, SUM(CASE WHEN p.stop_reason = 'max_tokens' OR p.fail_reason = 'cut_off' THEN 1 ELSE 0 END) as cut_offs, SUM(CASE WHEN p.status = 'read' AND p.latency_ms > 0 THEN p.output_tokens ELSE 0 END) as written, SUM(CASE WHEN p.status = 'read' AND p.latency_ms > 0 THEN p.latency_ms ELSE 0 END) as milliseconds")
            ->get()->keyBy(fn ($row) => $row->kind.'/'.$row->pass);

        $rows = [];

        foreach ($this->kinds->all() as $key => $kind) {
            foreach ($kind->passes() as $pass) {
                $figures = ReaderRequest::figures($key, $pass);
                $typical = $this->quote->typical($key, $pass);
                $seen = $measured[$key.'/'.$pass] ?? null;

                $rows[] = [
                    'kind' => $key,
                    'pass' => $pass,
                    'model' => (string) $figures['model'],
                    'max_tokens' => (int) $figures['max_tokens'],
                    'timeout' => (int) $figures['timeout'],
                    'prompt_tokens' => $this->quote->promptTokens($key, $pass),
                    'typical_output' => $typical['output'],
                    'typical_seconds' => $typical['seconds'],
                    'typical_measured' => $typical['measured'],
                    'pages' => (int) ($seen?->pages ?? 0),
                    'read_pages' => (int) ($seen?->read_pages ?? 0),
                    'cut_offs' => (int) ($seen?->cut_offs ?? 0),
                    'tokens_per_second' => self::perSecond((int) ($seen?->written ?? 0), (int) ($seen?->milliseconds ?? 0)),
                ];
            }
        }

        return $rows;
    }

    /**
     * What the dry runs of the last days measured, for each reader pass or text call and each model
     * it was tried with: how many answers, how many were cut off or refused, what a usable answer
     * usually wrote and took, and what it cost.
     *
     * @return list<array<string, mixed>>
     */
    public function trialFigures(): array
    {
        $pages = DB::table('reader_trial_pages as p')
            ->join('reader_trials as t', 't.id', '=', 'p.reader_trial_id')
            ->where('p.finished_at', '>=', now()->subDays(self::DAYS))
            ->orderByDesc('p.id')->limit(self::MOST_TRIAL_PAGES)
            ->get(['t.kind', 't.purpose', 'p.pass', 'p.model', 'p.status', 'p.fail_reason', 'p.stop_reason', 'p.refused', 'p.output_tokens', 'p.latency_ms', 'p.cost_usd']);

        return $pages->groupBy(fn ($row) => implode('|', [$row->kind ?? '', $row->purpose ?? '', $row->pass, $row->model]))
            ->map(function (Collection $group) {
                $first = $group->first();
                $read = $group->where('status', ReaderTrialPage::READ)->filter(fn ($row) => (int) $row->latency_ms > 0);
                $cost = $group->reduce(fn ($sum, $row) => $sum->plus(Dec::of((string) $row->cost_usd)), Dec::of(0));

                return [
                    'kind' => $first->kind,
                    'purpose' => $first->purpose,
                    'pass' => $first->pass,
                    'model' => $first->model,
                    'pages' => $group->count(),
                    'read_pages' => $group->where('status', ReaderTrialPage::READ)->count(),
                    'failed_pages' => $group->where('status', ReaderTrialPage::FAILED)->count(),
                    'cut_offs' => $group->filter(fn ($row) => $row->stop_reason === 'max_tokens' || $row->fail_reason === PageOutcome::CUT_OFF)->count(),
                    'refused' => (int) $group->sum(fn ($row) => (int) $row->refused),
                    'typical_output' => self::median($read->map(fn ($row) => (int) $row->output_tokens)->all()),
                    'typical_seconds' => (int) ceil(self::median($read->map(fn ($row) => (int) $row->latency_ms)->all()) / 1000),
                    'tokens_per_second' => self::perSecond((int) $read->sum(fn ($row) => (int) $row->output_tokens), (int) $read->sum(fn ($row) => (int) $row->latency_ms)),
                    'cost_per_page_usd' => (string) $cost->dividedBy($group->count(), 4, RoundingMode::HalfUp),
                ];
            })
            ->sortBy(fn (array $row) => [$row['kind'] ?? '~', $row['purpose'] ?? '', $row['pass'], $row['model']])->values()->all();
    }

    /** @return Collection<int, ReaderTrial> The latest dry runs that were not deleted. */
    public function recentTrials(int $most = 20): Collection
    {
        return ReaderTrial::query()->where('status', '!=', ReaderTrial::DISCARDED)->with('user:id,name')->latest('id')->limit($most)->get();
    }

    /** Pages of dry runs that were read, by reader or text call, for the line beside each release tick. Counts stay after the files are gone. */
    public function trialPagesRead(): array
    {
        return DB::table('reader_trial_pages as p')
            ->join('reader_trials as t', 't.id', '=', 'p.reader_trial_id')
            ->where('p.status', ReaderTrialPage::READ)
            ->selectRaw('COALESCE(t.kind, t.purpose) as feature, COUNT(*) as pages')
            ->groupByRaw('COALESCE(t.kind, t.purpose)')
            ->pluck('pages', 'feature')->map(fn ($count) => (int) $count)->all();
    }

    /** Output tokens a second over the given answers, to one decimal, or null when there were none. A roll-up for display only. */
    private static function perSecond(int $tokens, int $milliseconds): ?string
    {
        return $tokens > 0 && $milliseconds > 0 ? number_format($tokens * 1000 / $milliseconds, 1, '.', '') : null;
    }

    /** @param  list<int>  $values */
    private static function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);
    }
}
