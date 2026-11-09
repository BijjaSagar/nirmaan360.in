<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DprMachinery extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'dpr';

    protected $table = 'dpr_machinery';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['hours' => 'decimal:1'];
    }

    public function dpr(): BelongsTo
    {
        return $this->belongsTo(Dpr::class);
    }
}
