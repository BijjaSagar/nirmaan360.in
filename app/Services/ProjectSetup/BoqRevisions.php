<?php

namespace App\Services\ProjectSetup;

use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\BoqSection;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * The BOQ is never locked, so every change to a line is written down: who, when, what it was,
 * what it became, why, and what the whole BOQ was worth either side of it. The BoqItem model
 * calls record() itself, so every way of changing a line is covered, from the screen to an import.
 *
 * It writes only to boq_revisions. Measurements, daily reports and bills are never touched, and
 * a bill keeps the rate it was made with.
 */
final class BoqRevisions
{
    /** Columns of a BOQ line that are kept in the history, and the name each goes by in it. */
    private const FIELDS = [
        'boq_section_id' => 'section',
        'code' => 'code',
        'description' => 'description',
        'unit' => 'unit',
        'qty' => 'qty',
        'rate' => 'rate',
        'category' => 'category',
        'details' => 'details',
    ];

    /** Fields that carry money. They are hidden from anyone without money.view. */
    public const MONEY_FIELDS = ['rate'];

    /** @var array{reason:?string,source:?string,batch:?string,valuations:array<int,string>}|null */
    private static ?array $context = null;

    /**
     * Run a change and attach a reason (and where it came from) to everything it writes.
     * With $batch, all the lines changed share one batch id and the value of the BOQ is
     * carried along instead of being added up again for each line.
     */
    public static function with(?string $reason, ?string $source, callable $callback, bool $batch = false): mixed
    {
        $previous = self::$context;
        self::$context = [
            'reason' => filled($reason) ? trim($reason) : null,
            'source' => $source,
            'batch' => $batch ? (string) Str::uuid() : null,
            'valuations' => [],
        ];

        try {
            return $callback();
        } finally {
            self::$context = $previous;
        }
    }

    public static function record(BoqItem $item, string $action): void
    {
        $changes = self::changes($item, $action);

        if ($changes === []) {
            return;
        }

        $amount = Dec::of($item->amount());
        $delta = match ($action) {
            'edited' => $amount->minus(self::originalAmount($item)),
            'deleted' => $amount->negated(),
            default => $amount,
        };

        self::write($item, $action, $changes, $delta);
    }

    /**
     * A change that is not a column of the line, such as its concrete mix. The value of the BOQ
     * does not move.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    public static function note(BoqItem $item, array $changes): void
    {
        if ($changes !== []) {
            self::write($item, 'edited', $changes, BigDecimal::zero());
        }
    }

    /** What the whole BOQ of a project is worth: the rounded amount of every line, added up. */
    public static function valuation(int $projectId): string
    {
        $total = BigDecimal::zero();

        BoqItem::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->get(['qty', 'rate'])
            ->each(function (BoqItem $line) use (&$total) {
                $total = $total->plus(Dec::of(Dec::money(Dec::of($line->qty)->multipliedBy(Dec::of($line->rate)))));
            });

        return Dec::money($total);
    }

    /** @param  array<string, array{0: mixed, 1: mixed}>  $changes */
    private static function write(BoqItem $item, string $action, array $changes, BigDecimal $delta): void
    {
        $projectId = (int) $item->project_id;
        $cached = self::$context['valuations'][$projectId] ?? null;
        $after = $cached !== null ? Dec::of($cached)->plus($delta) : Dec::of(self::valuation($projectId));

        if (self::$context !== null && self::$context['batch'] !== null) {
            self::$context['valuations'][$projectId] = Dec::money($after);
        }

        BoqRevision::withoutGlobalScopes()->create([
            'organization_id' => $item->organization_id,
            'project_id' => $projectId,
            'boq_item_id' => $item->id,
            'batch_uuid' => self::$context['batch'] ?? null,
            'action' => $action,
            'source' => self::$context['source'] ?? null,
            'item_code' => (string) $item->code,
            'item_description' => Str::limit((string) $item->description, 500, ''),
            'changes' => $changes,
            'reason' => self::$context['reason'] ?? null,
            'valuation_before' => Dec::money($after->minus($delta)),
            'valuation_after' => Dec::money($after),
            'user_id' => Auth::id(),
        ]);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    private static function changes(BoqItem $item, string $action): array
    {
        $changes = [];

        foreach (self::FIELDS as $column => $name) {
            $old = $new = null;

            if ($action === 'edited') {
                if (! $item->wasChanged($column)) {
                    continue;
                }

                $old = self::value($column, $item->getOriginal($column));
                $new = self::value($column, $item->getAttribute($column));
            } elseif ($action === 'deleted') {
                $old = self::value($column, $item->getAttribute($column));
            } else {
                $new = self::value($column, $item->getAttribute($column));
            }

            if ($old === $new) {
                continue;
            }

            $changes[$name] = [$old, $new];
        }

        return $changes;
    }

    /** Quantities and rates are written the same way every time, so "100" and "100.000" are not a change. */
    private static function value(string $column, mixed $value): ?string
    {
        if ($column === 'boq_section_id') {
            return $value ? BoqSection::withoutGlobalScopes()->whereKey($value)->value('name') : null;
        }

        if ($value === null || (is_string($value) && trim($value) === '' && ! in_array($column, ['qty', 'rate'], true))) {
            return null;
        }

        return match ($column) {
            'qty' => Dec::qty($value),
            'rate' => Dec::money($value),
            default => trim((string) $value),
        };
    }

    private static function originalAmount(BoqItem $item): BigDecimal
    {
        return Dec::of(Dec::money(Dec::of($item->getOriginal('qty'))->multipliedBy(Dec::of($item->getOriginal('rate')))));
    }
}
