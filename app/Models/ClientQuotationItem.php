<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\LockedWithQuotation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One BOQ line as it stood when the quotation was prepared: description, quantity, rate and amount. */
class ClientQuotationItem extends Model
{
    use BelongsToOrganization, LockedWithQuotation;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
            'rate' => 'decimal:2',
            'amount' => 'decimal:2',
            'translation_accepted_at' => 'datetime',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(ClientQuotation::class, 'quotation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function boqItem(): BelongsTo
    {
        return $this->belongsTo(BoqItem::class);
    }

    /** Who accepted the translation of this line, so that it is printed beside the description. */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'translation_accepted_by');
    }
}
