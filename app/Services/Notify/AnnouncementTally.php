<?php

namespace App\Services\Notify;

use App\Models\Announcement;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counts what happened to each phone's copy of an announcement, as the push jobs finish:
 * Firebase took it, refused it, or it was not sent because the person switched it off or
 * signed out while it waited. Any other push is left alone. Counting never stops a delivery.
 */
final class AnnouncementTally
{
    public const SENT = 'sent_count';

    public const FAILED = 'failed_count';

    public const SKIPPED = 'skipped_count';

    public static function count(NotificationMessage $message, string $column): void
    {
        $target = $message->push ?? null;

        if ($target?->type !== 'announcement' || ! in_array($column, [self::SENT, self::FAILED, self::SKIPPED], true)) {
            return;
        }

        try {
            Announcement::whereKey((int) $target->id)->increment($column);
        } catch (Throwable $exception) {
            Log::warning('Announcement count not updated', ['announcement_id' => $target->id, 'error' => $exception->getMessage()]);
        }
    }
}
