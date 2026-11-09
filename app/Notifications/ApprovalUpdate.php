<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ApprovalUpdate extends Notification
{
    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => $this->url];
    }
}
