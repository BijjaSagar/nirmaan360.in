<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialSupplierRecord extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'marked' => 'boolean', 'marked_at' => 'datetime'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id')->withTrashed();
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
