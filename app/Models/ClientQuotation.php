<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A quotation a contractor gives a client for chosen BOQ lines, with its payment stages.
 *
 * Once issued it never changes: a change is a new revision under the same number. Only the share
 * link may still be made, replaced or withdrawn. Audit rows are written by the service, so the
 * share token and the fact sheet are not copied into the audit trail.
 */
class ClientQuotation extends Model
{
    use BelongsToOrganization, Immutable;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    /** The languages a quotation is written in. */
    public const LANGUAGES = ['en', 'hi', 'mr'];

    /** What may still change on an issued quotation: its share link. */
    public const SHARE_COLUMNS = ['share_token', 'share_expires_at', 'share_revoked_at'];

    protected $guarded = [];

    /** The token opens the shared page, so it stays out of arrays and JSON. */
    protected $hidden = ['share_token'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'gst_pct' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
            'slots' => 'array',
            'facts' => 'array',
            'number_ticks' => 'array',
            'share_expires_at' => 'datetime',
            'share_revoked_at' => 'datetime',
            'issued_at' => 'datetime',
        ];
    }

    /** @return list<string> */
    public function lockedStatuses(): array
    {
        return [self::ISSUED];
    }

    /** @return list<string> */
    public function mutableAfterApproval(): array
    {
        return self::SHARE_COLUMNS;
    }

    /** Shown when someone tries to change an issued quotation, its lines or its stages. */
    public static function lockedMessage(): string
    {
        return __('This quotation has been issued and cannot be changed. Make a revision instead.');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isIssued(): bool
    {
        return $this->status === self::ISSUED;
    }

    /** Whether a link to this quotation is open now. */
    public function isShared(): bool
    {
        return $this->share_token !== null
            && $this->share_revoked_at === null
            && $this->share_expires_at !== null
            && $this->share_expires_at->isFuture();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClientQuotationItem::class, 'quotation_id')->orderBy('sort')->orderBy('id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ClientPaymentStage::class, 'quotation_id')->orderBy('sort')->orderBy('id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
