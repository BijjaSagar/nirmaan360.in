<?php

namespace App\Services\DocumentReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agents\CostMeter;
use App\Services\Agents\CreditService;
use App\Services\Agents\ModelGateway;
use App\Services\Agents\ModelGatewayException;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * What a read is likely to cost, before it runs. The figure shown is an estimate, not a price: each
 * page is charged for what the model really reads and writes, up to the limit the read starts with.
 *
 * One page and pass costs its picture (or its text) plus the fixed instructions going in, and what
 * the model writes coming out. The estimate uses a typical amount of writing, the worst case the
 * most the call may write.
 */
class ReadQuote
{
    /** @var array<string, array{prompt:int, typical:array{output:int, seconds:int, measured:bool}}> Looked up once per pass while one quote is worked out. */
    private array $figures = [];

    public function __construct(
        private CostMeter $meter,
        private CreditService $credits,
        private AiSpend $spend,
        private ModelGateway $gateway,
    ) {}

    /**
     * The quote for the pages of a read that are still waiting, optionally only those in a range.
     *
     * @return array{pages:int, calls:int, estimate:string, worst:string, cap:string, cap_floor:string, company_max:string, rupees:string, minutes:int, free:?string, enough:bool, pages_that_fit:int, basis:string}
     */
    public function forRead(DocumentRead $read, User $viewer, ?int $pageFrom = null, ?int $pageTo = null): array
    {
        $pages = DocumentReadPage::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id)
            ->where('status', DocumentReadPage::WAITING)
            ->when($pageFrom !== null, fn ($query) => $query->where('page_no', '>=', $pageFrom))
            ->when($pageTo !== null, fn ($query) => $query->where('page_no', '<=', $pageTo))
            ->orderBy('page_no')->orderBy('id')
            ->get();

        return $this->forPages($read, $pages, $viewer);
    }

    /**
     * The quote for chosen page rows of a read, in the order given: one page for a retry, or the lot.
     *
     * - estimate: credits with a typical amount of writing. worst: with every call writing its maximum.
     * - cap: the limit to offer. cap_floor: the lowest limit the read may start with.
     * - company_max: the most one read of this company may cost.
     * - enough: the whole of it can start now, for this person. When false, pages_that_fit says how
     *   many pages from the front could.
     * - free: the company's free balance, or null for someone who may not see the credits page.
     * - basis: 'measured' when every figure comes from pages already read, 'default' when none does, else 'mixed'.
     *
     * @param  iterable<DocumentReadPage>  $pages
     * @return array{pages:int, calls:int, estimate:string, worst:string, cap:string, cap_floor:string, company_max:string, rupees:string, minutes:int, free:?string, enough:bool, pages_that_fit:int, basis:string}
     */
    public function forPages(DocumentRead $read, iterable $pages, User $viewer): array
    {
        if ($viewer->organization_id !== $read->organization_id) {
            throw new LogicException('A read is quoted for someone of its own company.');
        }

        $this->figures = [];
        $organization = Organization::findOrFail($read->organization_id);
        $max = Dec::of($this->spend->maxCreditsPerRead($organization));
        $room = Dec::of($this->credits->room($organization, $viewer, $read));
        $reach = $max->isLessThan($room) ? $max : $room;
        $trialLeft = $this->spend->trialPagesLeft($organization);
        // A page that has been with the reader once is already in a trial's count.
        $counted = $trialLeft === null ? [] : $this->spend->pagesAlreadySent($read);

        $estimate = BigDecimal::zero();
        $worst = BigDecimal::zero();
        $seconds = 0;
        $calls = 0;
        $covered = [];
        $fit = 0;
        $stillFits = true;
        $measured = [];
        $newPages = 0;

        foreach ($this->byPageNumber($pages) as $rows) {
            foreach ($rows as $page) {
                if ($page->document_read_id !== $read->id) {
                    throw new LogicException('A page is quoted with its own read.');
                }

                foreach ($this->callsFor($read->kind, $page) as $call) {
                    $estimate = $estimate->plus($call['estimate']);
                    $worst = $worst->plus($call['worst']);
                    $seconds += $call['seconds'];
                    $measured[] = $call['measured'];
                    $calls++;
                }

                foreach (range($page->page_no, max($page->page_no, (int) $page->page_to)) as $number) {
                    $covered[$number] = true;
                }
            }

            $newPages += in_array((int) $rows[0]->page_no, $counted, true) ? 0 : 1;

            // Pages are taken from the front for as long as their floor fits; one that does not ends the run.
            $stillFits = $stillFits
                && $this->floor($estimate, $worst)->isLessThanOrEqualTo($reach)
                && ($trialLeft === null || $newPages <= $trialLeft);

            if ($stillFits) {
                $fit = count($covered);
            }
        }

        $pageCount = count($covered);
        $floor = $this->floor($estimate, $worst);
        $enough = $pageCount > 0 && $fit === $pageCount;
        $cap = $this->smallest($worst, $max, $this->wholeCredits($estimate->multipliedBy(Dec::of(config('modules.document_reading.cap_factor')))));

        if ($enough && $room->isLessThan($cap)) {
            // Never offer more than can be held. The floor fits, so this is still at or above it.
            $cap = $room;
        }

        if ($cap->isLessThan($floor)) {
            $cap = $floor;
        }

        return [
            'pages' => $pageCount,
            'calls' => $calls,
            'estimate' => $this->credits4($estimate),
            'worst' => $this->credits4($worst),
            'cap' => $this->credits4($cap),
            'cap_floor' => $this->credits4($floor),
            'company_max' => $this->credits4($max),
            'rupees' => Dec::money($estimate->multipliedBy(Dec::of(config('modules.agents.credit_inr')))),
            'minutes' => $pageCount === 0 ? 0 : max(1, (int) ceil($seconds / max(1, (int) config('modules.document_reading.parallel_pages')) / 60)),
            'free' => $viewer->hasPermission('agents.credits') ? $this->credits->free($organization) : null,
            'enough' => $enough,
            'pages_that_fit' => $fit,
            'basis' => match (true) {
                $measured === [] || ! in_array(true, $measured, true) => 'default',
                ! in_array(false, $measured, true) => 'measured',
                default => 'mixed',
            },
        ];
    }

    /**
     * The limit a read starts with. The person may only lower the offered limit, and never below
     * the floor; anything else, or nothing, gives the offered limit.
     *
     * @param  array{cap:string, cap_floor:string}  $quote  A quote made again at the moment of starting.
     */
    public function capFor(array $quote, ?string $asked): string
    {
        $cap = Dec::of($quote['cap']);
        $floor = Dec::of($quote['cap_floor']);

        if ($asked === null || ! is_numeric($asked)) {
            return $this->credits4($cap);
        }

        $asked = Dec::of($asked);

        return $this->credits4(match (true) {
            $asked->isGreaterThan($cap) => $cap,
            $asked->isLessThan($floor) => $floor,
            default => $asked,
        });
    }

    /**
     * The most one call can cost, in credits: every token of the prompt and the most the model may write.
     * Text calls use it for their maximum, with the tokens of their own request.
     */
    public function worst(string $model, int $inputTokens, int $maxTokens): string
    {
        return $this->creditsFor($model, $inputTokens, $maxTokens);
    }

    /**
     * For a one-shot action's "Uses up to :n credits": one call of this kind and pass with the
     * largest picture that is accepted, writing its maximum, rounded up to a whole credit.
     *
     * @param  ?string  $model  Another model than the pass normally uses, for a second try with the stronger one.
     */
    public function upTo(string $kind, string $pass, ?string $model = null): string
    {
        $input = ImageTokens::MAX_TOKENS + $this->promptTokens($kind, $pass);
        $worst = Dec::of($this->creditsFor($model ?? $this->pass($kind, $pass)['model'], $input, (int) $this->pass($kind, $pass)['max_tokens']));

        return $this->credits4($this->wholeCredits($worst));
    }

    /**
     * The tokens of a kind's fixed instructions and form: what one call sends besides its page.
     * The counted figure when there is one, otherwise the configured assumption.
     */
    public function promptTokens(string $kind, string $pass): int
    {
        $known = Cache::get($this->promptKey($kind, $pass));

        if (is_array($known) && is_int($known['tokens'] ?? null)) {
            return $known['tokens'];
        }

        return (int) ($this->pass($kind, $pass)['prompt_tokens'] ?? config('modules.document_reading.prompt_tokens'));
    }

    /**
     * Counts the prompt of a kind and pass with the provider, once for each wording of it. Counting
     * spends no tokens. Without a saved key, or when the count fails, the configured assumption stands.
     *
     * @param  array<string, mixed>  $body  The request as it will be sent, without the page picture or page text.
     */
    public function measurePrompt(string $kind, string $pass, array $body): int
    {
        $key = $this->promptKey($kind, $pass);
        $hash = hash('sha256', (string) json_encode(Arr::only($body, ['model', 'system', 'messages', 'output_config'])));
        $known = Cache::get($key);

        if (is_array($known) && ($known['hash'] ?? null) === $hash && is_int($known['tokens'] ?? null)) {
            return $known['tokens'];
        }

        // A figure counted for an earlier wording says nothing about this one.
        Cache::forget($key);

        if (! $this->gateway->isConnected()) {
            return $this->promptTokens($kind, $pass);
        }

        try {
            $tokens = $this->gateway->countTokens($body);
        } catch (ModelGatewayException) {
            return $this->promptTokens($kind, $pass);
        }

        Cache::forever($key, ['hash' => $hash, 'tokens' => $tokens]);

        return $tokens;
    }

    /**
     * What a call of this kind and pass usually writes and how long it takes: the median of the last
     * pages read, across every company, once enough exist. Until then the configured assumptions.
     * Only token counts and seconds are looked at, never what a page said.
     *
     * @return array{output:int, seconds:int, measured:bool}
     */
    public function typical(string $kind, string $pass): array
    {
        $config = $this->pass($kind, $pass);
        $window = (int) config('modules.document_reading.typical.window');
        $minimum = (int) config('modules.document_reading.typical.minimum');
        $seconds = (int) config('modules.document_reading.typical.cache_seconds');

        $measure = function () use ($kind, $pass, $window, $minimum) {
            $rows = DB::table('document_read_pages as p')
                ->join('document_reads as r', 'r.id', '=', 'p.document_read_id')
                ->where('r.kind', $kind)
                ->where('p.pass', $pass)
                ->where('p.status', DocumentReadPage::READ)
                ->orderByDesc('p.id')
                ->limit($window)
                ->get(['p.output_tokens', 'p.latency_ms']);

            if ($rows->count() < $minimum) {
                return ['measured' => false];
            }

            return [
                'measured' => true,
                'output' => $this->median($rows->pluck('output_tokens')->map(fn ($value) => (int) $value)->all()),
                'seconds' => (int) ceil($this->median($rows->pluck('latency_ms')->map(fn ($value) => (int) $value)->all()) / 1000),
            ];
        };

        $found = $seconds > 0 ? Cache::remember("ai.read_quote.typical:{$kind}:{$pass}", $seconds, $measure) : $measure();

        if (! $found['measured']) {
            return ['output' => (int) $config['typical_output'], 'seconds' => (int) $config['typical_seconds'], 'measured' => false];
        }

        // More than the call may write cannot be typical, whatever the rows say.
        return ['output' => min($found['output'], (int) $config['max_tokens']), 'seconds' => max(1, $found['seconds']), 'measured' => true];
    }

    /**
     * Every call a waiting page row will cause: its own pass, then the passes that normally follow it.
     *
     * @return list<array{estimate:BigDecimal, worst:BigDecimal, seconds:int, measured:bool}>
     */
    private function callsFor(string $kind, DocumentReadPage $page): array
    {
        $calls = [];

        foreach ([$page->pass, ...$this->following($kind, $page->pass)] as $index => $pass) {
            $config = $this->pass($kind, $pass);
            // The row's own model may be the stronger one someone asked for. A pass that follows uses its usual model.
            $model = $index === 0 && filled($page->model) ? $page->model : $config['model'];
            $figures = $this->figures[$pass] ??= ['prompt' => $this->promptTokens($kind, $pass), 'typical' => $this->typical($kind, $pass)];
            $input = $this->inputTokens($page, $figures['prompt']);
            $typical = $figures['typical'];

            $calls[] = [
                'estimate' => Dec::of($this->creditsFor($model, $input, $typical['output'])),
                'worst' => Dec::of($this->creditsFor($model, $input, (int) $config['max_tokens'])),
                'seconds' => $typical['seconds'],
                'measured' => $typical['measured'],
            ];
        }

        return $calls;
    }

    private function inputTokens(DocumentReadPage $page, int $prompt): int
    {
        if ($page->image_width && $page->image_height) {
            return ImageTokens::count((int) $page->image_width, (int) $page->image_height) + $prompt;
        }

        if ($page->text_chars !== null) {
            // A text run counted beforehand carries the count of its whole request.
            if ((int) $page->input_tokens > 0) {
                return (int) $page->input_tokens;
            }

            $perCharacter = Dec::of(config('modules.document_reading.text_tokens_per_character'));

            return Dec::of((int) $page->text_chars)->multipliedBy($perCharacter)->toScale(0, RoundingMode::Up)->toInt() + $prompt;
        }

        // Nothing stored yet to measure: price it as the largest picture that is accepted.
        return ImageTokens::MAX_TOKENS + $prompt;
    }

    /** @return list<string> The passes expected after this one on the same page, in order. */
    private function following(string $kind, string $pass): array
    {
        $found = [];
        $next = $this->pass($kind, $pass)['then'] ?? [];

        while ($next !== []) {
            $name = array_shift($next);

            if ($name === $pass || in_array($name, $found, true)) {
                continue;
            }

            $found[] = $name;
            $next = [...$next, ...($this->pass($kind, $name)['then'] ?? [])];
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function pass(string $kind, string $pass): array
    {
        return config("modules.document_reading.kinds.{$kind}.passes.{$pass}")
            ?? throw new RuntimeException("No reader figures are set for {$kind} / {$pass}.");
    }

    /**
     * @param  iterable<DocumentReadPage>  $pages
     * @return list<list<DocumentReadPage>> Rows of the same page number together, in the order first seen.
     */
    private function byPageNumber(iterable $pages): array
    {
        $groups = [];

        foreach ($pages as $page) {
            $groups[$page->page_no][] = $page;
        }

        return array_values($groups);
    }

    /** The lowest limit a read of this size may start with. */
    private function floor(BigDecimal $estimate, BigDecimal $worst): BigDecimal
    {
        return $this->smallest($worst, $this->wholeCredits($estimate->multipliedBy(Dec::of(config('modules.document_reading.floor_factor')))));
    }

    private function creditsFor(string $model, int $inputTokens, int $outputTokens): string
    {
        return $this->meter->credits($this->meter->usd($model, ['input' => $inputTokens, 'output' => $outputTokens, 'cache_read' => 0, 'cache_write' => 0]));
    }

    private function wholeCredits(BigDecimal $credits): BigDecimal
    {
        return $credits->toScale(0, RoundingMode::Ceiling);
    }

    private function credits4(BigDecimal $credits): string
    {
        return (string) $credits->toScale(4, RoundingMode::Up);
    }

    private function smallest(BigDecimal ...$values): BigDecimal
    {
        $min = array_shift($values);

        foreach ($values as $value) {
            $min = $value->isLessThan($min) ? $value : $min;
        }

        return $min;
    }

    /** @param  non-empty-list<int>  $values */
    private function median(array $values): int
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle] + 1, 2);
    }

    private function promptKey(string $kind, string $pass): string
    {
        return "ai.prompt_tokens:{$kind}:{$pass}";
    }
}
