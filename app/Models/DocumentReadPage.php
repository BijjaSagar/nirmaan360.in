<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One call to the model for a read: a page picture, the top or bottom half of one, or a run of
 * text pages (page_no to page_to).
 *
 * The company and the project always come from the read. A page left without its read's project
 * would be a company-level row, which people limited to other projects can open.
 */
class DocumentReadPage extends Model
{
    use BelongsToOrganization;

    public const WAITING = 'waiting';

    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const SUPERSEDED = 'superseded';

    /** The model answered, but not usably. These count toward the retry limit and the daily brake. */
    public const BEHAVIOUR_FAILURES = ['invalid_output', 'cut_off', 'declined'];

    /** The call itself went wrong. Nobody is at fault, so these do not count against the company. */
    public const INFRASTRUCTURE_FAILURES = ['timeout', 'unavailable', 'worker_lost', 'rejected', 'internal_error'];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $page) {
            $read = DocumentRead::withoutGlobalScopes()->find($page->document_read_id)
                ?? throw new LogicException('A page belongs to a read that exists.');

            $page->organization_id = $read->organization_id;
            $page->project_id = $read->project_id;
        });
    }

    protected function casts(): array
    {
        return [
            'needs_picture' => 'boolean',
            'cost_estimated' => 'boolean',
            'cost_usd' => 'decimal:6',
            'credits' => 'decimal:4',
            'result' => 'array',
            'injection_flags' => 'array',
            'claimed_at' => 'datetime',
            'not_before' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function failedByBehaviour(): bool
    {
        return $this->status === self::FAILED && in_array($this->fail_reason, self::BEHAVIOUR_FAILURES, true);
    }

    public function documentRead(): BelongsTo
    {
        return $this->belongsTo(DocumentRead::class);
    }
}
