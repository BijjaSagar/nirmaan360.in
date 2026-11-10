<?php

namespace App\Services\Notify;

/** One update for one person, already written in that person's language. */
final readonly class NotificationMessage
{
    /**
     * @param  string  $event  For example approval.waiting or issue.assigned.
     * @param  bool  $critical  Emailed even if the person has switched email off, and pushed during quiet hours.
     * @param  ?PushTarget  $push  What the push opens in the app and which switch it obeys. Read it as
     *                             ($message->push ?? null): a job queued before this field existed has none.
     */
    public function __construct(
        public string $event,
        public string $title,
        public string $body = '',
        public ?string $url = null,
        public bool $critical = false,
        public ?PushTarget $push = null,
    ) {}
}
