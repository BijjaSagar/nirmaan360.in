<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A broadcast or reminder pushed to the field app. See App\Services\Notify\AnnouncementService.
 *
 * Not a tenant model on purpose: platform staff write ones that belong to no company (organization_id
 * null) and reach every company, and the tenant scope would hide those and fill in the writer's own
 * company on create. So every query names the company itself: forCompany() on a company's pages, and
 * the recipients table, which is tenant scoped, for what a person's app lists. Writes are recorded with
 * AuditLog::record in the service rather than through Auditable, so the scheduler's bookkeeping is not.
 */
class Announcement extends Model
{
    public const AUDIENCES = ['all', 'company', 'roles', 'project'];

    public const SCHEDULES = ['now', 'once', 'daily', 'weekly'];

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const PARTIAL = 'partial';

    /**
     * Hours after its last run starts that an announcement can still be stopped: a copy can wait up to a
     * whole day of quiet hours, plus the time the run takes to queue.
     */
    public const STOP_WINDOW_HOURS = 26;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'translations' => 'array',
            'mentions_money' => 'boolean',
            'roles' => 'array',
            'repeat_until' => 'date',
            'scheduled_for' => 'datetime',
            'next_run_at' => 'datetime',
            'run_started_at' => 'datetime',
            'first_batch_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function targetOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'target_organization_id');
    }

    /** Read without the tenant scope: a platform announcement can name a project of any company. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScopes();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class)->withoutGlobalScopes();
    }

    /** One company's own announcements. Never the platform's, which that company cannot change. */
    public function scopeForCompany(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', self::SCHEDULED)->whereNotNull('next_run_at')->where('next_run_at', '<=', now());
    }

    public function isPlatform(): bool
    {
        return $this->organization_id === null;
    }

    public function repeats(): bool
    {
        return in_array($this->schedule, ['daily', 'weekly'], true);
    }

    /**
     * Scheduled ones, running reminders, and anything that went out in the last day: until then some
     * copies may still be waiting for people's quiet hours to end, and stopping drops them.
     */
    public function canBeCancelled(): bool
    {
        // A run queued but not started yet can always be stopped, however old the announcement is.
        return $this->status === self::SCHEDULED
            || ($this->status === self::SENDING && $this->run_started_at === null)
            || (in_array($this->status, self::STOPPABLE_AFTER_SENDING, true) && $this->sentRecently());
    }

    public const STOPPABLE_AFTER_SENDING = [self::SENDING, self::SENT, self::PARTIAL];

    /**
     * A failed or partly sent one-off can be sent again under the same run: whoever already got it is
     * passed over, for as long as their claim on the run lasts (the push dedupe window, counted from
     * when it first queued phones, which later runs do not move). A failed one reached nobody and can
     * go at any time.
     */
    public function canBeRetried(): bool
    {
        if ($this->repeats()) {
            return false;
        }

        $window = (int) config('modules.notify.push.dedupe_hours', 24) - 1;

        return $this->status === self::FAILED
            || ($this->status === self::PARTIAL && $this->first_batch_at !== null && $this->first_batch_at->greaterThanOrEqualTo(now()->subHours($window)));
    }

    /** Whether its last run started recently enough that some copies may still be waiting for quiet hours to end. */
    public function sentRecently(): bool
    {
        $when = collect([$this->run_started_at, $this->last_sent_at, $this->scheduled_for, $this->created_at])->filter()->max();

        return $when !== null && $when->greaterThanOrEqualTo(now()->subHours(self::STOP_WINDOW_HOURS));
    }

    /** The title in this language, or the English one when none was written for it. */
    public function titleIn(string $locale): string
    {
        return filled($text = $this->translations[$locale]['title'] ?? null) ? (string) $text : $this->title;
    }

    public function bodyIn(string $locale): string
    {
        return filled($text = $this->translations[$locale]['body'] ?? null) ? (string) $text : $this->body;
    }

    /** The public address of the picture, which Firebase and the app fetch without signing in. */
    public function imageUrl(): ?string
    {
        return $this->image_token === null ? null : route('announcements.image', $this->image_token);
    }
}
