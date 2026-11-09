<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class DprManpower extends Model
{
    use BelongsToOrganization;

    protected $table = 'dpr_manpower';

    protected $guarded = [];
}
