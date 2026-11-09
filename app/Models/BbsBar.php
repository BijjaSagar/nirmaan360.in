<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BbsBar extends Model
{
    use Auditable, BelongsToOrganization;

    public const PROJECT_VIA = 'schedule';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dims' => 'array',
            'hook_allowance_mm' => 'decimal:3',
            'bend_allowance_mm' => 'decimal:3',
            'raw_length_mm' => 'decimal:3',
            'cutting_length_mm' => 'decimal:3',
            'total_length_m' => 'decimal:3',
            'unit_weight_kg_m' => 'decimal:3',
            'total_weight_kg' => 'decimal:3',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BbsSchedule::class, 'bbs_schedule_id');
    }
}
