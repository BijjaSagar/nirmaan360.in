<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The findings of one pre-check explained in plain words, in one language: English, Hindi or Marathi.
 *
 * It keeps only the figure-free sentences that say what each kind of finding means, either the app's
 * own (source "template") or a model's that passed every guard (source "ai"). The findings themselves,
 * with their figures, are never stored here: they are written again by the code from the check every
 * time the explanation is shown, in the form the reader may see. App\Services\BillCheck\Explain\
 * BillCheckExplanations is the only way in and out.
 */
class BillCheckExplanation extends Model
{
    use BelongsToOrganization;

    public const AI = 'ai';

    public const TEMPLATE = 'template';

    protected $guarded = [];

    protected $hidden = ['slots', 'fingerprint'];

    protected function casts(): array
    {
        return [
            'slots' => 'array',
            'drafted_at' => 'datetime',
        ];
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(BillCheck::class, 'bill_check_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
