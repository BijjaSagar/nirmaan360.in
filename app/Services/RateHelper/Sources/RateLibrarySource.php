<?php

namespace App\Services\RateHelper\Sources;

use App\Models\BoqItem;
use App\Models\Organization;
use App\Models\RateLibraryItem;
use App\Models\User;
use App\Services\RateHelper\CandidatePool;
use App\Services\RateHelper\RateFigure;
use App\Services\RateHelper\UnitGate;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;

/**
 * The company's rate library. It is company-wide by design, so everyone who may read rates sees
 * the same rows. A rate here is as entered by a person, and its date is when it was last edited.
 */
class RateLibrarySource extends TableSource
{
    public const KEY = 'rate_library';

    /** The rate analysis columns and what each is called on the screen. */
    public const PARTS = [
        'material_rate' => 'Material',
        'labour_rate' => 'Labour',
        'plant_rate' => 'Plant',
        'overhead_rate' => 'Overhead',
        'profit_rate' => 'Profit',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Rate library';
    }

    public function isConnected(Organization $organization): bool
    {
        return $organization->allowsFeature('reports');
    }

    public function allows(User $viewer): bool
    {
        return $viewer->hasPermission('rate.view');
    }

    protected function pool(BoqItem $item): CandidatePool
    {
        $pool = new CandidatePool;

        foreach (RateLibraryItem::query()->where('rate', '>', 0)->orderByDesc('id')->limit($this->poolLimit())->get(['id', 'item', 'unit']) as $row) {
            $pool->add($row->id, $row->item, $row->unit);
        }

        return $pool;
    }

    protected function rows(array $rowIds): iterable
    {
        return RateLibraryItem::query()->whereKey($rowIds)->where('rate', '>', 0)->get();
    }

    protected function figure(Model $row): ?RateFigure
    {
        /** @var RateLibraryItem $row */
        if (UnitGate::key($row->unit) === null) {
            return null;
        }

        $parts = [];

        foreach (self::PARTS as $column => $label) {
            if ($row->{$column} !== null) {
                $parts[] = ['label' => $label, 'amount' => Dec::money($row->{$column}), 'note' => null];
            }
        }

        return new RateFigure(
            source: self::KEY,
            rowId: $row->id,
            description: $row->item,
            unit: $row->unit,
            rate: Dec::money($row->rate),
            date: $row->updated_at,
            dateLabel: 'last edited',
            basis: 'as entered',
            parts: $parts,
        );
    }
}
