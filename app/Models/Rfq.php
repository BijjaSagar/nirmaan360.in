<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rfq extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function indent(): BelongsTo
    {
        return $this->belongsTo(Indent::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class)->orderBy('id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(RfqQuotation::class)->orderBy('id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'OPEN';
    }
}
