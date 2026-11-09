<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    protected $guarded = [];

    /** Changes with the photo, so a browser or phone never keeps showing an old one. */
    public function imageVersion(): ?string
    {
        return $this->image_path === null ? null : substr(md5($this->image_path), 0, 8);
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'deadline_date' => 'date',
            'budget_amount' => 'decimal:2',
            'budget_margin_pct' => 'decimal:2',
            'contract_value' => 'decimal:2',
            'retention_pct' => 'decimal:2',
            'tds_pct' => 'decimal:2',
            'gst_pct' => 'decimal:2',
            'advance_amount' => 'decimal:2',
            'advance_recovery_pct' => 'decimal:2',
            'measurement_tolerance_pct' => 'decimal:2',
            'deduction_order' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'client_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function boqSections(): HasMany
    {
        return $this->hasMany(BoqSection::class)->orderBy('sort');
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(BoqItem::class);
    }

    public function dprs(): HasMany
    {
        return $this->hasMany(Dpr::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(MeasurementEntry::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function raBills(): HasMany
    {
        return $this->hasMany(RaBill::class);
    }

    public function pettyCashWallets(): HasMany
    {
        return $this->hasMany(PettyCashWallet::class);
    }

    public function billingTerms(): array
    {
        return [
            'retention_pct' => $this->retention_pct,
            'tds_pct' => $this->tds_pct,
            'gst_pct' => $this->gst_pct,
            'advance_amount' => $this->advance_amount,
            'advance_recovery_pct' => $this->advance_recovery_pct,
            'order' => $this->deduction_order ?: config('buildos.default_deduction_order'),
            'basis' => $this->deduction_basis,
        ];
    }
}
