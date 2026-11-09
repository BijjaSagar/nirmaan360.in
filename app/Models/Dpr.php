<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Dpr extends Model
{
    use Approvable, Auditable, BelongsToOrganization, Immutable, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['report_date' => 'date', 'client_updated_at' => 'datetime'];
    }

    public function approvalDocType(): string
    {
        return 'dpr';
    }

    public function approvalTitle(): string
    {
        return 'DPR '.$this->report_date->format('d M Y').' · '.$this->project->name;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function manpower(): HasMany
    {
        return $this->hasMany(DprManpower::class);
    }

    public function machinery(): HasMany
    {
        return $this->hasMany(DprMachinery::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(DprWorkItem::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DprPhoto::class);
    }
}
