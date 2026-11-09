<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    use BelongsToOrganization;

    protected $table = 'attendance';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['attendance_date' => 'date', 'ot_hours' => 'decimal:1'];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
