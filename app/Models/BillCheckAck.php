<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person's acknowledgement of one finding of one check, with the remark they typed. Written once, never changed. */
class BillCheckAck extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(BillCheck::class, 'bill_check_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
