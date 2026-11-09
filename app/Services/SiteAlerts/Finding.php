<?php

namespace App\Services\SiteAlerts;

use App\Models\Alert;

/**
 * One cause an alert source found on a project. The engine turns it into an open alert, or
 * refreshes the alert it already has for the same type and reference.
 */
final readonly class Finding
{
    /**
     * @param  string  $type  For example schedule.behind. Must be among the source's types().
     * @param  string  $title  English, translated when shown. May use :placeholders from $params.
     * @param  string  $message  English, translated when shown. Keep rupee figures out unless $needs is a money permission.
     * @param  array<string, scalar|null>  $params  Values for the :placeholders, already formatted for reading.
     * @param  string  $reference  What the alert is about inside the project: project, work_order:12, material:7, floor:3.
     * @param  array<string, mixed>  $data  The figures behind the message, returned as they are by the API.
     * @param  ?string  $needs  Permission needed to see the alert: null for everyone, money.view or money.overview for money.
     * @param  ?string  $notify  Permission of the people told when the alert opens. Null when the alert is for the
     *                           dashboard only, because the module that found the cause has already told people itself.
     * @param  ?string  $url  Where to act on it.
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $message,
        public array $params = [],
        public string $reference = 'project',
        public string $severity = Alert::WARNING,
        public array $data = [],
        public ?string $needs = null,
        public ?string $notify = 'alerts.manage',
        public ?string $url = null,
    ) {}

    /** Identifies one cause: a second finding with the same key never opens a second alert. */
    public function key(): string
    {
        return $this->type.'|'.$this->reference;
    }
}
