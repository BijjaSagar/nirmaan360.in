<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a tender says, as the reader found it on one page. A proposal until a person confirms it
 * with the page drawn beside it; rejected when the person says it is wrong; superseded when a later
 * reading of a corrigendum replaces it. Nothing here changes the tender by itself.
 *
 * Tenders belong to the company, not to a project, as the tender's BOQ lines do.
 */
class CrmTenderFact extends Model
{
    use BelongsToOrganization;

    public const COMPANY_WIDE = true;

    public const PROPOSED = 'proposed';

    public const CONFIRMED = 'confirmed';

    public const REJECTED = 'rejected';

    public const SUPERSEDED = 'superseded';

    /** Read from the PDF's text layer. */
    public const FROM_TEXT = 'text';

    /** Read from a picture of the page. */
    public const FROM_IMAGE = 'image';

    /** Still standing: shown on the sheet and compared with the other values of its field. */
    public const STANDING = [self::PROPOSED, self::CONFIRMED];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quote_found' => 'boolean',
            'edited' => 'boolean',
            'page_viewed' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(CrmTender::class, 'tender_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CrmTenderDocument::class, 'document_id');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isStanding(): bool
    {
        return in_array($this->status, self::STANDING, true);
    }
}
