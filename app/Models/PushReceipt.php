<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Proof that one thing was already pushed to one person, kept so a scan or a retry that tells
 * them again sends nothing. Written by PushDispatcher::claim() and removed by housekeeping.
 */
class PushReceipt extends Model
{
    use BelongsToOrganization;

    public const COMPANY_WIDE = true;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime'];
    }
}
