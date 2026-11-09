<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DprPhoto extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    // A new photo must reach devices on the next pull, which watches dprs.updated_at.
    protected $touches = ['dpr'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime'];
    }

    public function dpr(): BelongsTo
    {
        return $this->belongsTo(Dpr::class);
    }
}
