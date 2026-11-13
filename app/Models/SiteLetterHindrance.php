<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One register entry cited by one letter. Written and replaced together with the letter. */
class SiteLetterHindrance extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    protected $guarded = [];

    public function letter(): BelongsTo
    {
        return $this->belongsTo(SiteLetter::class, 'site_letter_id');
    }

    public function hindrance(): BelongsTo
    {
        return $this->belongsTo(Hindrance::class)->withTrashed();
    }
}
