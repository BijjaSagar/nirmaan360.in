<?php

namespace App\Services\Notify;

/** One update for one person, already written in that person's language. */
final readonly class NotificationMessage
{
    /**
     * @param  string  $event  For example approval.waiting or issue.assigned.
     * @param  bool  $critical  Emailed even if the person has switched email off.
     */
    public function __construct(
        public string $event,
        public string $title,
        public string $body = '',
        public ?string $url = null,
        public bool $critical = false,
    ) {}
}
