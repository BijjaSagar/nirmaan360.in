<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class DprMachinery extends Model
{
    use BelongsToOrganization;

    protected $table = 'dpr_machinery';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['hours' => 'decimal:1'];
    }
}
