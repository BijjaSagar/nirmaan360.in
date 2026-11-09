<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One approval a project needs before building starts, such as a fire NOC or a sanctioned plan. */
class ProjectPermit extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = ['not_started', 'applied', 'approved', 'rejected'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['fee' => 'decimal:2', 'applied_on' => 'date', 'approved_on' => 'date'];
    }

    protected static function booted(): void
    {
        // The foreign key would remove the rows but leave their files behind.
        static::deleting(fn (self $permit) => $permit->files()->get()->each->delete());
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProjectPermitFile::class)->orderBy('id');
    }
}
