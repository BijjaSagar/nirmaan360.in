<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** A scan or photo kept with a permit. Stored privately and opened only through a signed-in route. */
class ProjectPermitFile extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleted(fn (self $file) => Storage::delete($file->path));
    }

    public function permit(): BelongsTo
    {
        return $this->belongsTo(ProjectPermit::class, 'project_permit_id');
    }
}
