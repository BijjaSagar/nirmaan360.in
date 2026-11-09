<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in a lead, tender or company timeline. */
class CrmActivity extends Model
{
    use BelongsToOrganization;

    public const COMPANY_WIDE = true;

    public const KINDS = ['note', 'call', 'meeting', 'email', 'whatsapp', 'stage', 'system'];

    /** The kinds a person can log by hand. */
    public const MANUAL_KINDS = ['note', 'call', 'meeting', 'email', 'whatsapp'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
