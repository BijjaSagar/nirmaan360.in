<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmTenderDocument extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const KINDS = ['nit', 'corrigendum', 'boq', 'drawing', 'eligibility', 'bid', 'receipt', 'other'];

    protected $guarded = [];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(CrmTender::class, 'tender_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
