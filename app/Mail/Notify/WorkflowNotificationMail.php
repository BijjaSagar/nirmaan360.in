<?php

namespace App\Mail\Notify;

use App\Services\Notify\NotificationMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Not queued itself: DeliverNotification is already running on the queue. */
class WorkflowNotificationMail extends Mailable
{
    public function __construct(public NotificationMessage $notice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notice->title);
    }

    public function content(): Content
    {
        return new Content(view: 'modules.notify.mail');
    }
}
