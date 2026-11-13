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
}
