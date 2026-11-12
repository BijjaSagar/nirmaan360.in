<?php

namespace App\Services\RateHelper\Sources;

use App\Models\BoqItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\RateHelper\CandidatePool;
use App\Services\RateHelper\RateFigure;
use App\Services\RateHelper\UnitGate;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;

/**
 * BOQ lines of the company's other projects. BoqItem carries a project_id, so someone limited to
 * chosen projects is offered lines from those projects only, and reads a rate from those only.
 * A rate here is as entered on that BOQ, and its date is when that line was last edited.
 */
class OwnBoqSource extends TableSource
{
    public const KEY = 'own_boq';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'BOQs of your other projects';
    }

    public function isConnected(Organization $organization): bool
    {
        return true;
    }

    public function allows(User $viewer): bool
    {
        return $viewer->hasPermission('boq.view') || $viewer->hasPermission('boq.manage');
    }

    protected function poolKey(BoqItem $item): string
    {
        return 'not-project-'.$item->project_id;
    }

    protected function pool(BoqItem $item): CandidatePool
    {
        $pool = new CandidatePool;
        $rows = BoqItem::query()
            ->where('project_id', '!=', $item->project_id)
            ->where('rate', '>', 0)
            ->whereHas('project')
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
        return BoqItem::query()->whereKey($rowIds)->where('rate', '>', 0)->whereHas('project')->with('project')->get();
    }

    protected function figure(Model $row): ?RateFigure
    {
        /** @var BoqItem $row */
        if (UnitGate::key($row->unit) === null || $row->project === null) {
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
            origin: trim($row->project->code.' · '.$row->project->name, ' ·'),
        );
    }
}
