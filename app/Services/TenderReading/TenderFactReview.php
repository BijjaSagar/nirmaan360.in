<?php

namespace App\Services\TenderReading;

use App\Models\AuditLog;
use App\Models\CrmTenderFact;
use App\Models\DocumentRead;
use App\Models\User;
use App\Services\DocumentReading\ReadAudit;
use App\Services\DocumentReading\ReadRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a person does with a fact on the tender sheet: correct its value, confirm it with its page
 * drawn beside it, or reject it. A confirmed fact is not edited any more; it can still be rejected
 * until it is applied to the tender.
 *
 * The audit rows say who did what to which field on which page, never the value: what a document
 * says stays out of the audit log, as it does for every read.
 */
class TenderFactReview
{
    public const NOT_PROPOSED = 'not_proposed';

    public function __construct(private TenderFactBuilder $builder, private TenderFactDiff $diff) {}

    /** @throws ReadRefused when the fact is no longer a proposal */
    public function edit(CrmTenderFact $fact, string $value, User $by): CrmTenderFact
    {
        return $this->change($fact, $by, [CrmTenderFact::PROPOSED], function (CrmTenderFact $locked) use ($value) {
            $before = ['value_sha256' => hash('sha256', $locked->value_raw)];
            $value = trim($value);

            $locked->forceFill([
                'value_raw' => $value,
                'value_norm' => TenderValue::normalise($locked->value_kind, $value),
                'edited' => true,
            ])->save();

            return ['tender_fact.edited', $before, ['value_sha256' => hash('sha256', $value)]];
        });
    }

    /**
     * Confirms a fact. Only while its page is drawn beside it: the screen sends "viewed" once the page
     * has been drawn, and the server keeps that the page was viewed.
     *
     * When another document, such as a corrigendum, gave a different value that was confirmed before,
     * confirming this one marks that one replaced (TenderFactDiff). Nothing on the tender changes.
     *
     * @throws ValidationException when the page was not viewed
     * @throws ReadRefused when the fact is no longer a proposal
     */
    public function confirm(CrmTenderFact $fact, bool $viewed, User $by): CrmTenderFact
    {
        if (! $viewed) {
            throw ValidationException::withMessages(['viewed' => [__('Open the page beside this fact and check it before you confirm it.')]]);
        }

        return $this->change($fact, $by, [CrmTenderFact::PROPOSED], function (CrmTenderFact $locked) use ($by) {
            $locked->forceFill([
                'status' => CrmTenderFact::CONFIRMED,
                'page_viewed' => true,
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
            ])->save();

            $this->diff->supersede($locked, $by);

            return ['tender_fact.confirmed', ['status' => CrmTenderFact::PROPOSED], ['status' => CrmTenderFact::CONFIRMED, 'page_viewed' => true]];
        });
    }

    /**
     * Rejects a fact. A confirmed fact that had replaced a value from another document gives that value
     * back: it stands again, confirmed, as it was before (TenderFactDiff::restore).
     *
     * @throws ReadRefused when the fact was already rejected, replaced or applied
     */
    public function reject(CrmTenderFact $fact, User $by): CrmTenderFact
    {
        return $this->change($fact, $by, CrmTenderFact::STANDING, function (CrmTenderFact $locked) use ($by) {
            if ($locked->applied_to !== null) {
                throw $this->refused($locked);
            }

            $before = ['status' => $locked->status];
            $locked->forceFill(['status' => CrmTenderFact::REJECTED])->save();

            $restored = $before['status'] === CrmTenderFact::CONFIRMED ? $this->diff->restore($locked, $by) : [];

            return ['tender_fact.rejected', $before, ['status' => CrmTenderFact::REJECTED, ...($restored === [] ? [] : ['restored' => $restored])]];
        });
    }

    /**
     * @param  list<string>  $from  The statuses the fact may be in.
     * @param  callable(CrmTenderFact): array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}  $step
     */
    private function change(CrmTenderFact $fact, User $by, array $from, callable $step): CrmTenderFact
    {
        return DB::transaction(function () use ($fact, $by, $from, $step) {
            // The read first, then the fact: the order the page reader takes them in when it stages facts.
            $read = $fact->document_read_id === null ? null
                : DocumentRead::withoutGlobalScopes()->where('organization_id', $fact->organization_id)->lockForUpdate()->find($fact->document_read_id);
            $locked = CrmTenderFact::withoutGlobalScopes()->where('organization_id', $fact->organization_id)->lockForUpdate()->findOrFail($fact->id);

            if (! in_array($locked->status, $from, true)) {
                throw $this->refused($locked);
            }

            [$action, $before, $after] = $step($locked);

            AuditLog::record($action, $locked, $before, [
                ...$after,
                'field' => $locked->field,
                'page' => (int) $locked->page,
                'source' => $locked->source,
                'quote_found' => $locked->quote_found,
                'edited' => (bool) $locked->edited,
            ], $by, (int) $locked->organization_id);

            $this->builder->regroup((int) $locked->organization_id, (int) $locked->tender_id);
            if ($read !== null) {
                $this->settleRead($read, $by);
            }

            return $locked->refresh();
        });
    }

    /**
     * A person working through the sheet keeps its read open. When no fact of a finished read is left
     * to look at, the read counts as confirmed, and its files are cleared at the usual time after that.
     */
    private function settleRead(DocumentRead $read, User $by): void
    {
        $facts = fn () => CrmTenderFact::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('document_read_id', $read->id);

        if ($read->status !== DocumentRead::READY || $facts()->where('status', CrmTenderFact::PROPOSED)->exists()) {
            $read->touch();

            return;
        }

        $read->forceFill(['status' => DocumentRead::CONFIRMED])->save();

        ReadAudit::confirmed($read, $by, [
            'rows_one_by_one' => $facts()->where('status', CrmTenderFact::CONFIRMED)->count(),
            'cells_typed' => $facts()->where('edited', true)->count(),
        ]);
    }

    private function refused(CrmTenderFact $fact): ReadRefused
    {
        return new ReadRefused(self::NOT_PROPOSED, __('This fact was already dealt with. Reload the page to see where it stands.'), ['status' => $fact->status], 409);
    }
}
