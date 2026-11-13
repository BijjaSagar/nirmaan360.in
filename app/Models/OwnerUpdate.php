<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A weekly update for the client of a project, made from the week's approved daily reports.
 *
 * It goes draft, approved, shared. Once a named person has approved it, it never changes: only its
 * share link may be made, replaced or withdrawn. Audit rows are written by the service, so the
 * share token and the fact sheet are not copied into the audit trail.
 */
class OwnerUpdate extends Model
{
    use BelongsToOrganization, Immutable;

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const SHARED = 'shared';

    /** The languages an update is written in. */
    public const LANGUAGES = ClientQuotation::LANGUAGES;

    /** What may still change on an approved update: its share link, and the step from approved to shared. */
    public const SHARE_COLUMNS = ['share_token', 'share_expires_at', 'share_revoked_at', 'shared_at', 'status'];

    protected $guarded = [];

    /** The token opens the shared page, so it stays out of arrays and JSON. */
    protected $hidden = ['share_token'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'prepared_on' => 'date',
            'show_money' => 'boolean',
            'received_amount' => 'decimal:2',
            'facts' => 'array',
            'slots' => 'array',
            'body_rendered' => 'array',
            'photo_ids' => 'array',
            'typed_numbers' => 'array',
            'number_ticks' => 'array',
            'share_expires_at' => 'datetime',
            'share_revoked_at' => 'datetime',
            'approved_at' => 'datetime',
            'shared_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The status of an approved update moves one way only: to shared.
        static::updating(function (self $update) {
            if (in_array($update->getOriginal('status'), $update->lockedStatuses(), true)
                && $update->isDirty('status')
                && ! ($update->getOriginal('status') === self::APPROVED && $update->status === self::SHARED)) {
                throw new ImmutableRecordException(self::lockedMessage());
            }
        });
    }

    /** @return list<string> */
    public function lockedStatuses(): array
    {
        return [self::APPROVED, self::SHARED];
    }

    /** @return list<string> */
    public function mutableAfterApproval(): array
    {
        return self::SHARE_COLUMNS;
    }

    /** Shown when someone tries to change an approved update. */
    public static function lockedMessage(): string
    {
        return __('This update has been approved and cannot be changed. Prepare a new update for the week instead.');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** Approved by a named person, whether or not a link was made since. */
    public function isApproved(): bool
    {
        return in_array($this->status, [self::APPROVED, self::SHARED], true);
    }

    /** Whether a link to this update is open now. */
    public function isShared(): bool
    {
        return $this->status === self::SHARED
            && $this->share_token !== null
            && $this->share_revoked_at === null
            && $this->share_expires_at !== null
            && $this->share_expires_at->isFuture();
    }

    /** @return list<int> The photos picked for the update, in the order they are shown. */
    public function photoIds(): array
    {
        return array_values(array_map('intval', array_filter((array) $this->photo_ids, 'is_numeric')));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(ClientQuotation::class, 'quotation_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
