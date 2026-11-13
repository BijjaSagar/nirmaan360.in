<?php

namespace App\Services\TenderReading;

use App\Models\AuditLog;
use App\Models\CrmTenderFact;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What a later document, such as a corrigendum, changes on the tender sheet: for each field, the value
 * a person confirmed and the different value another document gives, each with its own document and
 * page. Code does not decide which one stands. A person confirms the new value with its page beside
 * it, and only then is the old one marked replaced.
 *
 * Two different values in one document are not a change but a contradiction: both stay side by side
 * until a person rejects the one that does not stand.
 */
class TenderFactDiff
{
    /**
     * The changes on one tender's sheet, field by field (and label by label for a field that repeats).
     *
     * @param  Collection<int, CrmTenderFact>  $facts  Every fact of the tender.
     * @return list<array{topic: string, field: string, old: Collection<int, CrmTenderFact>, new: Collection<int, CrmTenderFact>}>
     */
    public static function changes(Collection $facts): array
    {
        $changes = [];

        foreach ($facts->filter->isStanding()->groupBy(fn (CrmTenderFact $fact) => TenderFactBuilder::topic($fact->field, $fact->label)) as $topic => $group) {
            $confirmed = $group->where('status', CrmTenderFact::CONFIRMED)->values();
            $new = $group->where('status', CrmTenderFact::PROPOSED)
                ->filter(fn (CrmTenderFact $fact) => self::replaces($fact, $confirmed)->isNotEmpty())->values();

            if ($new->isEmpty()) {
                continue;
            }

            $changes[] = [
                'topic' => (string) $topic,
                'field' => $group->first()->field,
                // Each confirmed fact once, however many new values replace it.
                'old' => $new->flatMap(fn (CrmTenderFact $fact) => self::replaces($fact, $confirmed))->keyBy('id')->values(),
                'new' => $new,
            ];
        }

        return $changes;
    }

    /**
     * The confirmed facts this fact replaces once it is confirmed: of the same field (and label, for a
     * field that repeats), from another document, with another value.
     *
     * @param  Collection<int, CrmTenderFact>  $others
     * @return Collection<int, CrmTenderFact>
     */
    public static function replaces(CrmTenderFact $fact, Collection $others): Collection
    {
        $topic = TenderFactBuilder::topic($fact->field, $fact->label);
        $value = TenderValue::compareKey($fact->value_kind, $fact->value_raw, $fact->value_norm);

        return $others->filter(fn (CrmTenderFact $old) => $old->id !== $fact->id
            && $old->status === CrmTenderFact::CONFIRMED
            && TenderFactBuilder::topic($old->field, $old->label) === $topic
            && (int) $old->document_id !== (int) $fact->document_id
            && TenderValue::compareKey($old->value_kind, $old->value_raw, $old->value_norm) !== $value)->values();
    }

    /**
     * Marks as replaced the confirmed facts that a fact just confirmed replaces. Runs inside the
     * confirmation's transaction. A value already put on the tender stays there: the new one is put on
     * the tender by a person, like any other.
     *
     * @return list<int> The ids of the facts it replaced.
     */
    public function supersede(CrmTenderFact $confirmed, User $by): array
    {
        $others = CrmTenderFact::withoutGlobalScopes()
            ->where('organization_id', $confirmed->organization_id)
            ->where('tender_id', $confirmed->tender_id)
            ->where('field', $confirmed->field)
            ->where('status', CrmTenderFact::CONFIRMED)
            ->whereKeyNot($confirmed->id)
            ->lockForUpdate()
            ->get();

        $replaced = self::replaces($confirmed, $others);

        foreach ($replaced as $old) {
            $old->forceFill(['status' => CrmTenderFact::SUPERSEDED])->save();

            // Who replaced which field on which page, and by which fact. Never the values.
            AuditLog::record('tender_fact.superseded', $old, ['status' => CrmTenderFact::CONFIRMED], [
                'status' => CrmTenderFact::SUPERSEDED,
                'field' => $old->field,
                'page' => (int) $old->page,
                'replaced_by' => (int) $confirmed->id,
                'applied' => $old->applied_to !== null,
            ], $by, (int) $old->organization_id);
        }

        return $replaced->modelKeys();
    }

    /**
     * The facts this fact replaced when it was confirmed and that are still marked replaced by it. The
     * audit row written when each was replaced is the record of which fact replaced it; a fact replaced
     * again later names the later fact instead.
     *
     * @return Collection<int, CrmTenderFact>
     */
    public static function replacedBy(CrmTenderFact $fact, bool $lock = false): Collection
    {
        [$superseded, $by] = self::superseded($fact, $lock);

        return $superseded->filter(fn (CrmTenderFact $old) => ($by[$old->id] ?? 0) === (int) $fact->id)->values();
    }

    /**
     * Every fact this fact replaced, and the facts those had replaced in turn, nearest first: a value
     * that a first corrigendum replaced, and a second corrigendum replaced again, is still an earlier
     * value of the second one.
     *
     * @return Collection<int, CrmTenderFact>
     */
    public static function replacedChain(CrmTenderFact $fact): Collection
    {
        [$superseded, $by] = self::superseded($fact);
        $chain = collect();
        $seen = [(int) $fact->id => true];
        $level = [(int) $fact->id];

        while ($level !== []) {
            $next = [];

            foreach ($superseded as $old) {
                if (! isset($seen[$old->id]) && in_array($by[$old->id] ?? 0, $level, true)) {
                    $seen[$old->id] = true;
                    $chain->push($old);
                    $next[] = (int) $old->id;
                }
            }

            $level = $next;
        }

        return $chain;
    }

    /**
     * The confirmed fact that stands now in place of a replaced one, following each replacement
     * forward, or null when none does.
     */
    public static function standingAfter(CrmTenderFact $old): ?CrmTenderFact
    {
        [$superseded, $by] = self::superseded($old);
        $ids = $superseded->modelKeys();
        $id = $by[$old->id] ?? null;
        $seen = [(int) $old->id => true];

        while ($id !== null && in_array($id, $ids, true) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $id = $by[$id] ?? null;
        }

        if ($id === null || isset($seen[$id])) {
            return null;
        }

        return CrmTenderFact::withoutGlobalScopes()->where('organization_id', $old->organization_id)
            ->where('tender_id', $old->tender_id)->where('status', CrmTenderFact::CONFIRMED)->find($id);
    }

    /**
     * The replaced facts of this fact's tender and field, with the fact that last replaced each, from
     * the audit row written when it was replaced.
     *
     * @return array{0: Collection<int, CrmTenderFact>, 1: array<int, int>}
     */
    private static function superseded(CrmTenderFact $fact, bool $lock = false): array
    {
        $superseded = CrmTenderFact::withoutGlobalScopes()
            ->where('organization_id', $fact->organization_id)
            ->where('tender_id', $fact->tender_id)
            ->where('field', $fact->field)
            ->where('status', CrmTenderFact::SUPERSEDED)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get();

        if ($superseded->isEmpty()) {
            return [$superseded, []];
        }

        $by = AuditLog::withoutGlobalScopes()
            ->where('organization_id', $fact->organization_id)
            ->where('action', 'tender_fact.superseded')
            ->where('auditable_type', $fact->getMorphClass())
            ->whereIn('auditable_id', $superseded->modelKeys())
            ->orderByDesc('id')
            ->get(['id', 'auditable_id', 'new_values'])
            ->uniqueStrict('auditable_id')
            ->mapWithKeys(fn (AuditLog $row) => [(int) $row->auditable_id => (int) ($row->new_values['replaced_by'] ?? 0)])
            ->all();

        return [$superseded, $by];
    }

    /**
     * Gives back the confirmed value that a fact replaced, when that fact is rejected after all: the
     * value it replaced stands again, confirmed as before. Runs inside the rejection's transaction.
     *
     * @return list<int> The ids of the facts that stand again.
     */
    public function restore(CrmTenderFact $rejected, User $by): array
    {
        $restored = self::replacedBy($rejected, lock: true);

        foreach ($restored as $old) {
            $old->forceFill(['status' => CrmTenderFact::CONFIRMED])->save();

            // Which fact stands again on which page, and the rejected fact that had replaced it. Never the values.
            AuditLog::record('tender_fact.restored', $old, ['status' => CrmTenderFact::SUPERSEDED], [
                'status' => CrmTenderFact::CONFIRMED,
                'field' => $old->field,
                'page' => (int) $old->page,
                'replaced_by_rejected' => (int) $rejected->id,
            ], $by, (int) $old->organization_id);
        }

        return $restored->modelKeys();
    }
}
