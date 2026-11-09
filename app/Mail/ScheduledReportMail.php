<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A report from a schedule, sent with the file attached. */
class ScheduledReportMail extends Mailable
{
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $organizationName,
        public readonly int $rowCount,
        public readonly string $filename,
        public readonly string $mime,
        public readonly string $bytes,
        public readonly string $manageUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title.' · '.$this->organizationName);
    }

    public function content(): Content
    {
        return new Content(view: 'modules.reports.mail');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->bytes, $this->filename)->withMime($this->mime)];
    }
}
