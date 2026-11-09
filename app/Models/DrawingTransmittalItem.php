<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrawingTransmittalItem extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'transmittal';

    protected $guarded = [];

    public function transmittal(): BelongsTo
    {
        return $this->belongsTo(DrawingTransmittal::class, 'drawing_transmittal_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DrawingVersion::class, 'drawing_version_id');
    }
}
