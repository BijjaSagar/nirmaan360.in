<?php

namespace App\Services\ProjectSetup;

use App\Models\BoqCurrentRate;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use App\Services\BoqService;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The approved budget against the BOQ, and against the BOQ at the rates people have typed in
 * as today's market rates. There is no price feed behind those rates: they are only as fresh as
 * the last person who entered them.
 *
 * Every figure here is money, so the screens that show it need money.overview.
 */
class BoqBudget
{
    public function __construct(
        private WorkProgress $progress,
        private PermitService $permits,
        private BoqService $boq,
    ) {}

    public function rateRules(): array
    {
        return [
            'rates' => ['required', 'array'],
            'rates.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'noted_on' => ['required', 'date', 'before_or_equal:today'],
            'source' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function swapRules(): array
    {
        return [
            'boq_item_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:2000'],
            'rate' => ['required', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
        ];
    }

    /**
     * Each BOQ line with its BOQ rate, the newest rate typed in for it, and what the difference
     * does to the budget over the whole quantity. Lines nobody has priced have no impact.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lines(Project $project, ?string $category = null): Collection
    {
        $latest = BoqCurrentRate::where('project_id', $project->id)
            ->orderByDesc('noted_on')->orderByDesc('id')
            ->get()->unique('boq_item_id')->keyBy('boq_item_id');

        return BoqItem::where('project_id', $project->id)
            ->when($category, fn ($query) => $query->where('category', $category))
            ->orderBy('sort')->orderBy('id')->get()
            ->map(function (BoqItem $item) use ($latest) {
                $current = $latest->get($item->id);
                $difference = $current ? Dec::of($current->rate)->minus(Dec::of($item->rate)) : null;

                return [
                    'item' => $item,
                    'amount' => $item->amount(),
                    'current_rate' => $current ? Dec::money($current->rate) : null,
                    'noted_on' => $current?->noted_on,
                    'source' => $current?->source,
                    'difference' => $difference === null ? null : Dec::money($difference),
                    'impact' => $difference === null ? null : Dec::money($difference->multipliedBy(Dec::of($item->qty))),
                ];
            })->values();
    }

    /**
     * The headline figures: budget, the margin allowed over it, the BOQ, the variance, and the
     * same again with the BOQ priced at the current rates.
     *
     * @return array<string, mixed>
     */
    public function summary(Project $project, ?Collection $lines = null): array
    {
        $lines ??= $this->lines($project);
        $boq = $lines->reduce(fn (BigDecimal $sum, array $line) => $sum->plus(Dec::of($line['amount'])), BigDecimal::zero());
        $impact = $lines->reduce(fn (BigDecimal $sum, array $line) => $sum->plus(Dec::of($line['impact'])), BigDecimal::zero());
        $budget = filled($project->budget_amount) && Dec::of($project->budget_amount)->isPositive() ? Dec::of($project->budget_amount) : null;
        $margin = Dec::of($project->budget_margin_pct ?? 5);
        $ceiling = $budget ? $budget->plus(Dec::of(Dec::percentOf($budget, $margin))) : null;
        $projected = $boq->plus($impact);
        $permitFees = Dec::of($this->permits->totalFee($project));

        return [
            'budget' => $budget ? Dec::money($budget) : null,
            'margin_pct' => Dec::money($margin),
            'ceiling' => $ceiling ? Dec::money($ceiling) : null,
            'boq' => Dec::money($boq),
            'variance' => $budget ? Dec::money($boq->minus($budget)) : null,
            'variance_pct' => $budget ? $this->progress->percent($boq->minus($budget), $budget) : null,
            'status' => $this->status($boq, $budget, $ceiling),
            'impact' => Dec::money($impact),
            'priced_lines' => $lines->whereNotNull('current_rate')->count(),
            'line_count' => $lines->count(),
            'projected' => Dec::money($projected),
            'projected_variance' => $budget ? Dec::money($projected->minus($budget)) : null,
            'projected_variance_pct' => $budget ? $this->progress->percent($projected->minus($budget), $budget) : null,
            'projected_status' => $this->status($projected, $budget, $ceiling),
            'permit_fees' => Dec::money($permitFees),
            'permits' => $this->permits->counts($project),
            // The BOQ and the permission fees together, shown beside the budget and never mixed into it.
            'boq_with_permits' => Dec::money($boq->plus($permitFees)),
        ];
    }

    /**
     * RCC and brick work in detail, because that is where most of the money is lost: how big they
     * are, how far the work has got, what today's rates do to them, and where more has been done
     * than the BOQ allowed for.
     *
     * @return list<array<string, mixed>>
     */
    public function watched(Project $project, Collection $lines, string $boqTotal): array
    {
        return collect(BoqCategories::WATCHED)->map(function (string $category) use ($lines, $boqTotal) {
            $own = $lines->filter(fn (array $line) => $line['item']->category === $category);
            $value = BigDecimal::zero();
            $impact = BigDecimal::zero();
            $earned = BigDecimal::zero();
            $over = BigDecimal::zero();
            $overLines = 0;

            foreach ($own as $line) {
                /** @var BoqItem $item */
                $item = $line['item'];
                $rate = Dec::of($item->rate);
                $qty = Dec::of($item->qty);
                $done = $this->progress->completedQty($item->id);

                $value = $value->plus(Dec::of($line['amount']));
                $impact = $impact->plus(Dec::of($line['impact']));
                $earned = $earned->plus(($done->isGreaterThan($qty) ? $qty : ($done->isNegative() ? BigDecimal::zero() : $done))->multipliedBy($rate));

                if ($done->isGreaterThan($qty)) {
                    $overLines++;
                    $over = $over->plus($done->minus($qty)->multipliedBy($rate));
                }
            }

            return [
                'category' => $category,
                'lines' => $own->count(),
                'value' => Dec::money($value),
                'share_pct' => $this->progress->percent($value, $boqTotal),
                'impact' => Dec::money($impact),
                'priced' => $own->whereNotNull('current_rate')->count(),
                'done_pct' => $this->progress->percent($earned, $value),
                'over_lines' => $overLines,
                'over_value' => Dec::money($over),
            ];
        })->all();
    }

    /**
     * Type in today's market rates. Only a rate that differs from the newest one is saved, so
     * pressing save twice adds nothing. Returns how many were saved.
     *
     * @param  array<int|string, mixed>  $rates  BOQ line id => rate. Blank means no change.
     */
    public function recordRates(Project $project, array $rates, string $notedOn, ?string $source, User $user): int
    {
        $rates = array_filter($rates, fn ($rate) => $rate !== null && $rate !== '');
        $ids = array_map('intval', array_keys($rates));
        $own = BoqItem::where('project_id', $project->id)->whereIn('id', $ids)->pluck('id')->all();

        if (array_diff($ids, $own) !== []) {
            throw ValidationException::withMessages(['rates' => __('One of these items is not in this project.')]);
        }

        return DB::transaction(function () use ($project, $rates, $notedOn, $source, $user) {
            $latest = $this->lines($project)->keyBy(fn (array $line) => $line['item']->id);
            $saved = 0;

            foreach ($rates as $id => $rate) {
                $rate = Dec::money($rate);

                if (($latest[(int) $id]['current_rate'] ?? null) === $rate) {
                    continue;
                }

                BoqCurrentRate::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'boq_item_id' => (int) $id,
                    'rate' => $rate,
                    'noted_on' => $notedOn,
                    'source' => filled($source) ? trim($source) : null,
                    'noted_by' => $user->id,
                ]);
                $saved++;
            }

            return $saved;
        });
    }

    /**
     * What swapping one item would do: the Jaguar basin that becomes a Kohler one. Nothing is
     * saved. The BOQ either side of the swap is compared with the budget and its margin.
     *
     * @return array<string, mixed>
     */
    public function swapPreview(Project $project, BoqItem $item, ?string $description, string $rate): array
    {
        $oldAmount = Dec::of($item->amount());
        $newAmount = Dec::of(Dec::money(Dec::of($item->qty)->multipliedBy(Dec::of($rate))));
        $change = $newAmount->minus($oldAmount);
        $summary = $this->summary($project);
        $budget = $summary['budget'] ? Dec::of($summary['budget']) : null;
        $ceiling = $summary['ceiling'] ? Dec::of($summary['ceiling']) : null;
        $boqBefore = Dec::of($summary['boq']);
        $boqAfter = $boqBefore->plus($change);

        return [
            'item' => $item,
            'old_description' => $item->description,
            'new_description' => filled($description) ? trim($description) : $item->description,
            'old_rate' => Dec::money($item->rate),
            'new_rate' => Dec::money($rate),
            'old_amount' => Dec::money($oldAmount),
            'new_amount' => Dec::money($newAmount),
            'change' => Dec::money($change),
            'change_pct' => $oldAmount->isZero() ? null : $this->progress->percent($change, $oldAmount),
            'boq_before' => Dec::money($boqBefore),
            'boq_after' => Dec::money($boqAfter),
            'budget' => $summary['budget'],
            'margin_pct' => $summary['margin_pct'],
            'variance_after' => $budget ? Dec::money($boqAfter->minus($budget)) : null,
            'variance_after_pct' => $budget ? $this->progress->percent($boqAfter->minus($budget), $budget) : null,
            'status_before' => $summary['status'],
            'status_after' => $this->status($boqAfter, $budget, $ceiling),
        ];
    }

    /** Make the swap: a revision of the line, with the reason, like any other change to the BOQ. */
    public function swap(BoqItem $item, ?string $description, string $rate, string $reason): BoqItem
    {
        $data = ['rate' => $rate];

        if (filled($description)) {
            $data['description'] = trim($description);
        }

        return $this->boq->update($item, $data, $reason);
    }

    /** within: at or under the budget. margin: over it but inside the margin allowed. over: beyond the margin. */
    private function status(BigDecimal $value, ?BigDecimal $budget, ?BigDecimal $ceiling): ?string
    {
        if ($budget === null || $ceiling === null) {
            return null;
        }

        return match (true) {
            $value->isLessThanOrEqualTo($budget) => 'within',
            $value->isLessThanOrEqualTo($ceiling) => 'margin',
            default => 'over',
        };
    }
}
