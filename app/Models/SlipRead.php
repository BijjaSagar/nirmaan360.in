<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo of a challan, a weighbridge slip or a supplier bill, read to fill a form before it is
 * saved. It belongs to the person who took it: only they, an owner or an admin may open it, and
 * only they may save a form with it.
 *
 * "fields", "lines", "warnings" and "suggestions" are what the code made of the reader's answer.
 * They are blanked when the read's files are deleted; "confirmed" holds field names only and stays.
 */
class SlipRead extends Model
{
    use BelongsToOrganization;

    public const CHALLAN = 'challan';

    public const BILL = 'bill';

    public const READING = 'reading';

    public const READY = 'ready';

    public const USED = 'used';

    public const DISCARDED = 'discarded';

    public const FAILED = 'failed';

    /** The document read kind that reads each kind of slip. Its figures are in config/modules/document_reading.php. */
    public const READ_KINDS = [self::CHALLAN => 'slip', self::BILL => 'bill'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'lines' => 'array',
            'warnings' => 'array',
            'suggestions' => 'array',
            'confirmed' => 'array',
            'rechecked_at' => 'datetime',
        ];
    }

    public function documentRead(): BelongsTo
    {
        return $this->belongsTo(DocumentRead::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
