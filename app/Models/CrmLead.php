<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmLead extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const SOURCES = ['website', 'meta_ads', 'referral', 'walk_in', 'tender_portal', 'cold_call', 'other'];

    public const STATUSES = ['new', 'contacted', 'qualified', 'converted', 'lost'];

    public const OPEN_STATUSES = ['new', 'contacted', 'qualified'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expected_value' => 'decimal:2',
            'converted_at' => 'datetime',
            'tags' => 'array',
            'custom_fields' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
