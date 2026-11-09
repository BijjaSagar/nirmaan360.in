<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reusable list of check points, such as a pour card. Each inspection copies its items. */
class ChecklistTemplate extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'is_active' => 'boolean'];
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }
}
