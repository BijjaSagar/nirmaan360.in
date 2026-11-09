<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A machine, vehicle or other plant item. Retired assets stay on file for their history. */
class Asset extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'hire_rate' => 'decimal:2',
            'min_hours_per_month' => 'decimal:3',
            'hire_from' => 'date',
            'hire_to' => 'date',
        ];
    }

    public function isHired(): bool
    {
        return $this->ownership === 'hired';
    }

    public function isRetired(): bool
    {
        return $this->status === 'retired';
    }

    /** What the QR tag opens. Scanning needs a sign-in, so the tag itself reveals nothing. */
    public function tagUrl(): string
    {
        return route('assets.scan', $this->tag);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'vendor_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class)->latest('moved_on')->latest('id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MachineLog::class);
    }

    public function fuelIssues(): HasMany
    {
        return $this->hasMany(FuelIssue::class);
    }

    public function breakdowns(): HasMany
    {
        return $this->hasMany(AssetBreakdown::class);
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(AssetServiceRecord::class);
    }

    public function hireBills(): HasMany
    {
        return $this->hasMany(AssetHireBill::class);
    }
}
