<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person an announcement was sent to, in that person's own company. The app's list reads these,
 * so nobody sees an announcement that was not sent to them. Not audited: the announcement's own
 * audit row records each run and its counts.
 */
class AnnouncementRecipient extends Model
{
    use BelongsToOrganization;

    /** A notice to people, not a project record: who it reached was decided when it was sent. */
    public const COMPANY_WIDE = true;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['first_sent_at' => 'datetime', 'last_sent_at' => 'datetime'];
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
