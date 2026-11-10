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
     * @param  ?string  $image  A public HTTPS picture shown in the notification. Read it as ($message->image ?? null).
     */
    public function __construct(
        public string $event,
        public string $title,
        public string $body = '',
        public ?string $url = null,
        public bool $critical = false,
        public ?PushTarget $push = null,
        public ?string $image = null,
    ) {}

    /**
     * Queued jobs carry this message. Without a picture it is written exactly as before the picture
     * field existed, so a worker still running the older code (during a deploy, or after a rollback)
     * can read every push and email that has no picture.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        // get_object_vars leaves out a field never set, as on a message an older release queued.
        $data = get_object_vars($this);

        if (($data['image'] ?? null) === null) {
            unset($data['image']);
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    public function __unserialize(array $data): void
    {
        $this->event = $data['event'];
        $this->title = $data['title'];
        $this->body = $data['body'] ?? '';
        $this->url = $data['url'] ?? null;
        $this->critical = $data['critical'] ?? false;
        $this->push = $data['push'] ?? null;
        $this->image = $data['image'] ?? null;
    }
}
