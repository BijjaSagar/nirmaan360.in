<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoqSection extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(BoqItem::class);
    }
}
