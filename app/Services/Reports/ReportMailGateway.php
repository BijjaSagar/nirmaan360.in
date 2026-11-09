<?php

namespace App\Services\Reports;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

/**
 * Where scheduled reports leave the system. Email is only "connected" when the platform admin has set up
 * a real mail server. With the log or array mailer a message would go nowhere, so nothing is sent and
 * the screens say so rather than showing a schedule as live.
 */
class ReportMailGateway
{
    public function configured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /** @param  list<string>  $recipients */
    public function send(array $recipients, Mailable $mail): void
    {
        Mail::to($recipients)->send($mail);
    }
}
