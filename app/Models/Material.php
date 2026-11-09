<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['gst_pct' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(MaterialUnit::class, 'base_unit_id');
    }

    public function conversions(): HasMany
    {
        return $this->hasMany(MaterialUnitConversion::class);
    }

    public function label(): string
    {
        return $this->code.' · '.$this->name;
    }
}
