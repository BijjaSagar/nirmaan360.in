<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A company rate: what an item of work costs per unit. Used to fill in a BOQ line. */
class RateLibraryItem extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    /** The optional rate analysis parts. */
    public const PARTS = ['material_rate', 'labour_rate', 'plant_rate', 'overhead_rate', 'profit_rate'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'material_rate' => 'decimal:2',
            'labour_rate' => 'decimal:2',
            'plant_rate' => 'decimal:2',
            'overhead_rate' => 'decimal:2',
            'profit_rate' => 'decimal:2',
        ];
    }

    public function hasSplit(): bool
    {
        foreach (self::PARTS as $part) {
            if ($this->{$part} !== null) {
                return true;
            }
        }

        return false;
    }
}
