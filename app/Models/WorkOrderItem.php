<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderItem extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'workOrder';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'rate' => 'decimal:2'];
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class)->withTrashed();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
