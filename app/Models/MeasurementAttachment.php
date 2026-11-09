<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MeasurementAttachment extends Model
{
    use Auditable, BelongsToOrganization;

    public const KINDS = ['photo', 'sketch'];

    public const PROJECT_VIA = 'entry';

    protected $guarded = [];

    // A new attachment must reach devices on the next pull, which watches measurement_entries.updated_at.
    protected $touches = ['entry'];

    protected static function booted(): void
    {
        $guard = function (self $attachment) {
            $entry = MeasurementEntry::withoutGlobalScopes()->find($attachment->measurement_entry_id);

            if ($entry) {
                self::assertChangeable($entry);
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
        static::deleted(fn (self $attachment) => Storage::delete($attachment->path));
    }

    /** An attachment is part of the record an approver signs, so it locks with the entry. */
    public static function assertChangeable(MeasurementEntry $entry): void
    {
        if ($entry->status === 'APPROVED') {
            throw new ImmutableRecordException;
        }

        if (! $entry->isEditable()) {
            throw new ImmutableRecordException(__('This entry is under review and its attachments cannot be changed.'));
        }
    }

    public function isImage(): bool
    {
        return ! str_ends_with(strtolower($this->path), '.pdf');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(MeasurementEntry::class, 'measurement_entry_id');
    }
}
