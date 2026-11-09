<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DprWorkItem extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'dpr';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3'];
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    public function dpr(): BelongsTo
    {
        return $this->belongsTo(Dpr::class);
    }
}
