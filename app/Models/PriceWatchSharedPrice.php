<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the shared price list the platform admin keeps in /admin. It belongs to no
 * company; a company adopts it into its own dated list, where it is marked as shared.
 */
class PriceWatchSharedPrice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rate' => 'decimal:2', 'priced_on' => 'date'];
    }
}
