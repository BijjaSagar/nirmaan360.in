<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\RecordsBoqRevisions;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoqItem extends Model
{
    use Auditable, BelongsToOrganization, RecordsBoqRevisions, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'rate' => 'decimal:2'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(BoqSection::class, 'boq_section_id');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(MeasurementEntry::class);
    }

    public function dprWorkItems(): HasMany
    {
        return $this->hasMany(DprWorkItem::class);
    }

    public function billItems(): HasMany
    {
        return $this->hasMany(RaBillItem::class);
    }

    /** Adds executed, measured, approved and billed quantities for each BOQ line. */
    public function scopeWithLedger(Builder $query): Builder
    {
        return $query
            ->withSum('dprWorkItems as executed_qty', 'qty')
            ->withSum(['measurements as measured_qty' => fn ($q) => $q->where('status', '!=', 'REJECTED')], 'qty')
            ->withSum(['measurements as approved_qty' => fn ($q) => $q->where('status', 'APPROVED')], 'qty')
            ->withSum(['billItems as billed_qty' => fn ($q) => $q->whereHas(
                'bill',
                fn ($bill) => $bill->where('type', 'client')->where('status', '!=', 'CANCELLED')
            )], 'current_qty');
    }

    public function amount(): string
    {
        return Dec::money(Dec::of($this->qty)->multipliedBy(Dec::of($this->rate)));
    }

    /** BOQ quantity minus everything measured so far (rejected entries excluded). */
    public function balanceQty(): string
    {
        return Dec::qty(Dec::of($this->qty)->minus(Dec::of($this->measured_qty ?? 0)));
    }
}
