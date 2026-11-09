<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TakeoffMeasurement extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['points' => 'array', 'depth' => 'decimal:3', 'qty' => 'decimal:3'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(DrawingSheet::class, 'drawing_sheet_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(TakeoffLayer::class, 'takeoff_layer_id');
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }
}
