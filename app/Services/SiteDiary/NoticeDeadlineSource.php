<?php

namespace App\Services\SiteDiary;

use App\Models\Alert;
use App\Models\Hindrance;
use App\Models\Project;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use Carbon\CarbonInterface;

/**
 * Reminds people of a notice date that is close. A reminder is raised for each notice of a confirmed
 * hindrance that counts, when the date worked out from the notice periods entered under Contract
 * terms is near or has just passed, until a finalised letter that gives that notice cites the entry
 * or the entry it corrects (NoticeDeadlines::given). A notice of delay leaves the reminder for the extension
 * of time open. A request for extension of time closes both, since a notice of delay cannot cite an entry
 * that has ended. With no confirmed notice period there is no date and so no reminder.
 *
 * How early the reminder opens and how long it stays after the date are the app's own settings in
 * config/modules/site_diary.php. They are not periods from any agreement.
 *
 * Dates and days only, no money: anyone who may open the register sees the alert, and the people
 * who keep the register are told. Runs from the scheduler with nobody signed in, so every query
 * names the company and the project itself.
 */
class NoticeDeadlineSource implements AlertSource
{
    public const TYPE = 'site_diary.notice_deadline';

    /** Notice => title while the date is ahead, title once it has passed. */
    private const TITLES = [
        'eot_notice' => ['Extension of time notice: date coming up', 'Extension of time notice: date passed'],
        'delay_notice' => ['Notice of delay: date coming up', 'Notice of delay: date passed'],
    ];

    private const OTHER_TITLES = ['Notice date coming up', 'Notice date passed'];

    /** The date is certain or only an upper limit => wording while it is ahead, wording once it has passed. */
    private const MESSAGES = [
        NoticeDeadline::KNOWN => [
            'For the hindrance that started on :start, the date worked out from the notice period you entered is :date. Check it against your agreement.',
            'For the hindrance that started on :start, the date worked out from the notice period you entered was :date. Check it against your agreement.',
        ],
        NoticeDeadline::NOT_LATER_THAN => [
            'For the hindrance that started on :start, the notice periods you entered give a date no later than :date. One of them cannot be worked out yet, so the date may come sooner. Check it against your agreement.',
            'For the hindrance that started on :start, the notice periods you entered gave a date no later than :date. One of them cannot be worked out yet. Check it against your agreement.',
        ],
    ];

    public function __construct(private Hindrances $hindrances, private NoticeDeadlines $deadlines) {}

    public function types(): array
    {
        return [self::TYPE];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        $before = max(0, (int) config('modules.site_diary.remind_days_before'));
        $after = max(0, (int) config('modules.site_diary.remind_days_after'));

        // Locked entries and corrections too: a letter of one kind gives one notice, and once an entry is locked
        // its end date can be recorded only on a correction, which the next notice is often counted from.
        $entries = $this->hindrances->standing($project)->get();

        if ($entries->isEmpty()) {
            return;
        }

        $found = $this->deadlines->forMany($project, $entries);
        $letters = $this->deadlines->letters($project, $entries);

        foreach ($entries as $entry) {
            foreach ($found[$entry->id] as $deadline) {
                $left = $deadline->daysLeft($today);

                if ($left === null || $left > $before || $left < -$after || NoticeDeadlines::given($deadline->code, $letters[$entry->id])) {
                    continue;
                }

                yield $this->finding($project, $entry, $deadline, $left);
            }
        }
    }

    /** @return list<string> Every title and message this source can raise. Each is translated when shown. */
    public static function wording(): array
    {
        return [...array_merge(...array_values(self::TITLES)), ...self::OTHER_TITLES, ...array_merge(...array_values(self::MESSAGES))];
    }

    private function finding(Project $project, Hindrance $entry, NoticeDeadline $deadline, int $left): Finding
    {
        $passed = $left < 0 ? 1 : 0;

        return new Finding(
            type: self::TYPE,
            title: (self::TITLES[$deadline->code] ?? self::OTHER_TITLES)[$passed],
            message: self::MESSAGES[$deadline->state][$passed],
            params: ['start' => $entry->started_on->format('d M Y'), 'date' => $deadline->date->format('d M Y')],
            reference: 'hindrance:'.$entry->id.':'.$deadline->code,
            severity: $left <= 0 ? Alert::CRITICAL : Alert::WARNING,
            data: [
                'hindrance_id' => (int) $entry->id,
                'notice' => $deadline->code,
                'state' => $deadline->state,
                'date' => $deadline->date->toDateString(),
                'days_left' => $left,
                'started_on' => $entry->started_on->toDateString(),
                'ended_on' => $entry->ended_on?->toDateString(),
                'category' => $entry->category,
            ],
            needs: 'site_diary.view',
            notify: 'site_diary.manage',
            url: route('site-diary.hindrances.index', $project).'#hindrance-'.$entry->id,
        );
    }
}
