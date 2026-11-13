<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing that held the work up on a project, entered from the daily reports that mention it:
 * what it was, the day it started and the day it ended, or no end while it is still going on.
 *
 * An entry counts once it is confirmed. Once a finalised letter cites it, locked_at is set and the
 * row can no longer be changed or removed; a correction is then a new entry that names this one in
 * supersedes_id, and the register counts the newer of the two.
 */
class Hindrance extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const PROPOSED = 'proposed';

    public const CONFIRMED = 'confirmed';

    public const DISMISSED = 'dismissed';

    public const STATUSES = [self::PROPOSED, self::CONFIRMED, self::DISMISSED];

    /** Who put the entry forward: a person on the register, the app's own code, or the AI. */
    public const BY_PERSON = 'person';

    public const BY_CODE = 'code';

    public const BY_AI = 'ai';

    public const PROPOSERS = [self::BY_PERSON, self::BY_CODE, self::BY_AI];

    protected $guarded = [];

    protected static function booted(): void
    {
        // Whoever writes the row, a locked entry stays as the letter read it.
        static::updating(function (Hindrance $hindrance) {
            if ($hindrance->getOriginal('locked_at') !== null) {
                throw new ImmutableRecordException(self::lockedMessage());
            }
        });

        static::deleting(function (Hindrance $hindrance) {
            if ($hindrance->getOriginal('locked_at') !== null) {
                throw new ImmutableRecordException(self::lockedMessage());
            }
        });
    }

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
            'locked_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public static function lockedMessage(): string
    {
        return __('A finalised letter cites this entry, so it cannot be changed. Make a correction as a new entry instead.');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class)->withTrashed();
    }

    public function sources(): HasMany
    {
        return $this->hasMany(HindranceSource::class)->orderBy('report_date')->orderBy('id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** The locked entry this one corrects. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** Corrections of this entry that still stand. A dismissed correction corrects nothing. */
    public function corrections(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id')->where('status', '!=', self::DISMISSED);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::CONFIRMED;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isOngoing(): bool
    {
        return $this->ended_on === null;
    }
}
