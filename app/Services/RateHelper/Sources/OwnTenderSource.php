<?php

namespace App\Services\RateHelper\Sources;

use App\Models\BoqItem;
use App\Models\CrmTender;
use App\Models\CrmTenderBoqItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\RateHelper\CandidatePool;
use App\Services\RateHelper\RateFigure;
use App\Services\RateHelper\UnitGate;
use App\Support\Dec;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * BOQ lines the company priced for its past tenders: those that were submitted, won or lost. Only
 * people who may open tenders (crm.view) are offered them. Every query reaches a line through its
 * tender, so a tender that became a project outside a person's list stays out of their reach.
 */
class OwnTenderSource extends TableSource
{
    public const KEY = 'own_tender';

    /** Stages in which the tender's rates were what the company offered. An enquiry or a bid still being priced is left out. */
    public const PRICED_STAGES = ['submitted', 'won', 'lost'];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Your past tenders';
    }

    public function isConnected(Organization $organization): bool
    {
        return $organization->allowsFeature('crm');
    }

    public function allows(User $viewer): bool
    {
        return $viewer->hasPermission('crm.view');
    }

    protected function poolKey(BoqItem $item): string
    {
        return 'not-project-'.$item->project_id;
    }

    protected function pool(BoqItem $item): CandidatePool
    {
        $pool = new CandidatePool;
        $projectId = $item->project_id;

        // A tender that became this very project holds the lines this BOQ started from.
        $rows = CrmTenderBoqItem::query()
            ->where('rate', '>', 0)
            ->whereHas('tender', fn (Builder $tender) => $this->priced()($tender)->where(
                fn (Builder $query) => $query->whereNull('project_id')->orWhere('project_id', '!=', $projectId)
            ))
            ->orderByDesc('id')
            ->limit($this->poolLimit())
            ->get(['id', 'description', 'unit']);

        foreach ($rows as $row) {
            $pool->add($row->id, $row->description, $row->unit);
        }

        return $pool;
    }

    protected function rows(array $rowIds): iterable
    {
        return CrmTenderBoqItem::query()->whereKey($rowIds)->where('rate', '>', 0)->whereHas('tender', $this->priced())->with('tender')->get();
    }

    protected function figure(Model $row): ?RateFigure
    {
        /** @var CrmTenderBoqItem $row */
        if (UnitGate::key($row->unit) === null || $row->tender === null) {
            return null;
        }

        return new RateFigure(
            source: self::KEY,
            rowId: $row->id,
            description: $row->description,
            unit: $row->unit,
            rate: Dec::money($row->rate),
            date: $row->updated_at,
            dateLabel: 'last edited',
            basis: 'as entered',
            origin: trim(($row->tender->reference ? $row->tender->reference.' · ' : '').$row->tender->title),
        );
    }

    /** @return Closure(Builder<CrmTender>): Builder<CrmTender> */
    private function priced(): Closure
    {
        return fn (Builder $tender) => $tender->whereIn('stage', self::PRICED_STAGES);
    }
}
