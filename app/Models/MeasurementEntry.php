<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MeasurementEntry extends Model
{
    use Approvable, Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'measured_on' => 'date',
            'nos' => 'decimal:3',
            'length' => 'decimal:3',
            'breadth' => 'decimal:3',
            'depth' => 'decimal:3',
            'qty' => 'decimal:3',
            'is_deduction' => 'boolean',
            'deviation' => 'boolean',
            'client_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $entry) {
            if (! $entry->exists || $entry->isDirty(['nos', 'length', 'breadth', 'depth', 'is_deduction'])) {
                $entry->qty = self::computeQty(
                    $entry->nos, $entry->length, $entry->breadth, $entry->depth, (bool) $entry->is_deduction
                );
            }
        });

        // The foreign key would remove the rows but leave their files behind.
        static::deleting(fn (self $entry) => $entry->attachments()->get()->each->delete());
    }

    /** Nos × L × B × D. Blank dimensions are skipped; deductions come out negative. */
    public static function computeQty(mixed $nos, mixed $length, mixed $breadth, mixed $depth, bool $deduction = false): string
    {
        $qty = Dec::of($nos === null || $nos === '' ? 1 : $nos);

        foreach ([$length, $breadth, $depth] as $dimension) {
            if ($dimension !== null && $dimension !== '') {
                $qty = $qty->multipliedBy(Dec::of($dimension));
            }
        }

        return Dec::qty($deduction ? $qty->negated() : $qty);
    }

    public function approvalDocType(): string
    {
        return 'measurement';
    }

    public function approvalTitle(): string
    {
        return 'Measurement · '.$this->boqItem->code.' · '.fmt_qty($this->qty).' '.$this->boqItem->unit;
    }

    public function mutableAfterApproval(): array
    {
        return ['billed_client_bill_id', 'billed_sub_bill_id'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class)->withTrashed();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MeasurementAttachment::class)->orderBy('id');
    }

    public function isBilled(): bool
    {
        return $this->billed_client_bill_id !== null || $this->billed_sub_bill_id !== null;
    }
}
