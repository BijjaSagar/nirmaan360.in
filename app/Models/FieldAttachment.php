<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A private photo or document on an NCR, incident, RFI or quality test. */
class FieldAttachment extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA_MORPH = 'attachable';

    protected $guarded = [];

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
