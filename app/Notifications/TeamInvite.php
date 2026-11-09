<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvite extends Notification
{
    public function __construct(
        public string $organizationName,
        public string $roleLabel,
        public string $link,
        public int $validDays,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('You are invited to join :company', ['company' => $this->organizationName]))
            ->line(__(':company has invited you to its team on :brand as :role.', [
                'company' => $this->organizationName,
                'brand' => config('buildos.brand'),
                'role' => __($this->roleLabel),
            ]))
            ->action(__('Accept invitation'), $this->link)
            ->line(__('This link works once and expires in :days days.', ['days' => $this->validDays]));
    }
}
