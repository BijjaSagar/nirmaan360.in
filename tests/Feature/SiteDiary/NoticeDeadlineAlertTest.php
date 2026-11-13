<?php

namespace Tests\Feature\SiteDiary;

use App\Models\Alert;
use App\Models\ContractTerm;
use App\Models\Hindrance;
use App\Models\Project;
use App\Services\SiteAlerts\AlertEngine;
use App\Services\SiteDiary\NoticeDeadlineSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The reminder of a notice date. The notice periods (11 and 4 days) are invented for the test; the
 * reminder window is the app's own setting in config/modules/site_diary.php.
 */
class NoticeDeadlineAlertTest extends SiteDiaryTestCase
{
    private function scan(string $today, ?Project $project = null): void
    {
        app(AlertEngine::class)->scanProject(($project ?? $this->project)->fresh(), Carbon::parse($today.' 06:00:00', 'Asia/Kolkata'));
    }

    /** @return Collection<int, Alert> The reminders, open or not, of every company. */
    private function reminders(): Collection
    {
        return Alert::withoutGlobalScopes()->where('type', NoticeDeadlineSource::TYPE)->orderBy('id')->get();
    }

    private function open(): ?Alert
    {
        return $this->reminders()->firstWhere('status', Alert::OPEN);
    }

    public function test_a_reminder_opens_as_the_date_comes_near_turns_critical_on_the_day_and_closes_some_days_after(): void
    {
        // Started on 12 March and still going on. 11 days from the start gives 23 March.
        $entry = $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null, 'category' => 'site_handover']);
        $this->notice();
        $this->assertSame(7, config('modules.site_diary.remind_days_before'));
        $this->assertSame(7, config('modules.site_diary.remind_days_after'));

        $this->scan('2026-03-15');
        $this->assertCount(0, $this->reminders(), 'Eight days ahead is too early.');

        $this->scan('2026-03-16');
        $alert = $this->reminders()->sole();

        $this->assertSame([$this->org->id, $this->project->id], [$alert->organization_id, $alert->project_id]);
        $this->assertSame('hindrance:'.$entry->id.':delay_notice', $alert->reference);
        $this->assertSame([Alert::OPEN, Alert::WARNING], [$alert->status, $alert->severity]);
        $this->assertSame(['site_diary.view', 'site_diary.manage'], [$alert->needs_permission, $alert->notify_permission]);
        $this->assertSame('Notice of delay: date coming up', $alert->titleIn('en'));
        $this->assertSame('For the hindrance that started on 12 Mar 2026, the date worked out from the notice period you entered is 23 Mar 2026. Check it against your agreement.', $alert->messageIn('en'));
        $this->assertSame([
            'hindrance_id' => $entry->id, 'notice' => 'delay_notice', 'state' => 'known', 'date' => '2026-03-23', 'days_left' => 7,
            'started_on' => '2026-03-12', 'ended_on' => null, 'category' => 'site_handover',
        ], $alert->data);
        $this->assertStringEndsWith('/projects/'.$this->project->id.'/hindrances#hindrance-'.$entry->id, $alert->url);

        $this->scan('2026-03-22');
        $this->assertSame([Alert::WARNING, 1], [$this->open()->severity, $this->open()->data['days_left']]);

        $this->scan('2026-03-23');
        $this->assertSame([Alert::CRITICAL, 0, 'Notice of delay: date coming up'], [$this->open()->severity, $this->open()->data['days_left'], $this->open()->title]);

        $this->scan('2026-03-24');
        $passed = $this->open();
        $this->assertSame([Alert::CRITICAL, -1, 'Notice of delay: date passed'], [$passed->severity, $passed->data['days_left'], $passed->title]);
        $this->assertSame('For the hindrance that started on 12 Mar 2026, the date worked out from the notice period you entered was 23 Mar 2026. Check it against your agreement.', $passed->messageIn('en'));

        $this->scan('2026-03-30');
        $this->assertSame(-7, $this->open()->data['days_left']);

        $this->scan('2026-03-31');
        $this->assertNull($this->open());
        $this->assertSame(Alert::RESOLVED, $this->reminders()->sole()->status, 'One alert for one cause, from first to last.');
    }

    public function test_the_window_is_the_apps_own_setting(): void
    {
        $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null]);
        $this->notice();
        config(['modules.site_diary.remind_days_before' => 2, 'modules.site_diary.remind_days_after' => 0]);

        $this->scan('2026-03-20');
        $this->assertNull($this->open());

        $this->scan('2026-03-21');
        $this->assertNotNull($this->open());

        $this->scan('2026-03-23');
        $this->assertNotNull($this->open());

        $this->scan('2026-03-24');
        $this->assertNull($this->open());
    }

    public function test_there_is_no_reminder_without_a_confirmed_period(): void
    {
        $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null]);

        $this->scan(self::TODAY);
        $this->assertCount(0, $this->reminders());

        // Typed, not confirmed: it does not count.
        $term = $this->notice(['confirmed_by' => null, 'confirmed_at' => null]);
        $this->scan(self::TODAY);
        $this->assertCount(0, $this->reminders());

        $term->update(['confirmed_by' => $this->owner->id, 'confirmed_at' => now()]);
        $this->scan(self::TODAY);
        $this->assertNotNull($this->open());

        // Taking the confirmation away, as a change to the term does, closes the reminder again.
        $term->update(['confirmed_by' => null, 'confirmed_at' => null]);
        $this->scan(self::TODAY);
        $this->assertNull($this->open());
    }

    public function test_only_a_confirmed_entry_that_no_letter_cites_is_reminded_of(): void
    {
        $this->notice();
        $dates = ['started_on' => '2026-03-12', 'ended_on' => null];
        $unconfirmed = ['confirmed_by' => null, 'confirmed_at' => null];

        $this->hindrance([...$dates, ...$unconfirmed, 'status' => Hindrance::PROPOSED, 'proposed_by' => Hindrance::BY_AI]);
        $this->hindrance([...$dates, ...$unconfirmed, 'status' => Hindrance::DISMISSED]);
        $this->hindrance($dates)->delete();
        // A finalised letter cites this one, and the next is the correction that stands in its place.
        $cited = $this->hindrance([...$dates, 'locked_at' => now()]);
        $this->hindrance([...$dates, 'supersedes_id' => $cited->id]);
        // The date of this one is still far away, and this one's passed long ago.
        $this->hindrance(['started_on' => '2026-03-19', 'ended_on' => null]);
        $this->hindrance(['started_on' => '2026-01-05', 'ended_on' => '2026-01-09']);

        $this->scan(self::TODAY);
        $this->assertCount(0, $this->reminders());

        $entry = $this->hindrance($dates);
        $this->scan(self::TODAY);
        $this->assertSame('hindrance:'.$entry->id.':delay_notice', $this->reminders()->sole()->reference);

        // Dismissing the entry closes its reminder.
        $entry->update(['status' => Hindrance::DISMISSED]);
        $this->scan(self::TODAY);
        $this->assertNull($this->open());

        // Back in the register, it is reminded of again, until a letter cites it.
        $entry->update(['status' => Hindrance::CONFIRMED]);
        $this->scan(self::TODAY);
        $this->assertNotNull($this->open());

        $entry->update(['locked_at' => now()]);
        $this->scan(self::TODAY);
        $this->assertNull($this->open());
    }

    public function test_each_notice_of_one_entry_has_its_own_reminder(): void
    {
        $entry = $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => '2026-03-18']);
        $this->notice();
        $this->notice(['code' => 'eot_notice', 'label' => 'Notice for extension of time', 'days' => 4, 'counted_from' => 'hindrance_end']);

        $this->scan(self::TODAY);

        $this->assertSame([
            'hindrance:'.$entry->id.':eot_notice' => ['Extension of time notice: date coming up', '2026-03-22'],
            'hindrance:'.$entry->id.':delay_notice' => ['Notice of delay: date coming up', '2026-03-23'],
        ], $this->reminders()->mapWithKeys(fn (Alert $alert) => [$alert->reference => [$alert->title, $alert->data['date']]])->all());
    }

    public function test_a_date_that_is_only_an_upper_limit_is_worded_as_one(): void
    {
        // Still going on, so the period counted from the end cannot be worked out. Whichever comes first: not later than 23 March.
        $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null]);
        $this->notice(['combine' => 'earliest', 'combine_group' => 'N1']);
        $this->notice(['days' => 4, 'counted_from' => 'hindrance_end', 'combine' => 'earliest', 'combine_group' => 'N1']);

        $this->scan(self::TODAY);
        $alert = $this->open();
        $this->assertSame('not_later_than', $alert->data['state']);
        $this->assertSame('For the hindrance that started on 12 Mar 2026, the notice periods you entered give a date no later than 23 Mar 2026. One of them cannot be worked out yet, so the date may come sooner. Check it against your agreement.', $alert->messageIn('en'));

        $this->scan('2026-03-25');
        $this->assertSame('For the hindrance that started on 12 Mar 2026, the notice periods you entered gave a date no later than 23 Mar 2026. One of them cannot be worked out yet. Check it against your agreement.', $this->open()->messageIn('en'));

        // Whichever comes later cannot be told at all yet, so nothing is said.
        ContractTerm::query()->update(['combine' => 'latest']);
        $this->scan('2026-03-25');
        $this->assertNull($this->open());
    }

    public function test_the_daily_scan_runs_with_nobody_signed_in_and_keeps_companies_apart(): void
    {
        $mine = $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null]);
        $this->notice();

        // Another company has an entry on the same days but entered no notice period.
        [$rivalOrg, , $rivalProject] = $this->rival();
        $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null], $rivalProject);
        // And a second project of ours has a period but no entry.
        $this->notice(['days' => 4], $this->makeProject($this->org, ['code' => 'SG2']));

        Auth::forgetGuards();
        app(AlertEngine::class)->scanAll(Carbon::parse(self::TODAY.' 06:00:00', 'Asia/Kolkata'));

        $alert = $this->reminders()->sole();
        $this->assertSame([$this->org->id, $this->project->id, 'hindrance:'.$mine->id.':delay_notice'], [$alert->organization_id, $alert->project_id, $alert->reference]);

        // Once they enter and confirm their own period, they get their own reminder with their own date.
        $this->notice(['days' => 4], $rivalProject);
        app(AlertEngine::class)->scanAll(Carbon::parse('2026-03-14 06:00:00', 'Asia/Kolkata'));

        $theirs = $this->reminders()->where('organization_id', $rivalOrg->id)->sole();
        $this->assertSame([$rivalProject->id, '2026-03-16'], [$theirs->project_id, $theirs->data['date']]);
        $this->assertSame('2026-03-23', $this->reminders()->where('organization_id', $this->org->id)->sole()->data['date']);
    }

    public function test_the_reminder_goes_to_those_who_keep_the_register_and_is_hidden_from_those_who_cannot_open_it(): void
    {
        $entry = $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null]);
        $this->notice();
        $accounts = $this->member($this->org, 'accounts');
        $viewer = $this->member($this->org, 'viewer');
        $elsewhere = $this->limitTo($this->member($this->org, 'pm'), $this->makeProject($this->org, ['code' => 'SG2']));
        $this->pm->update(['locale' => 'hi']);

        $this->scan(self::TODAY);
        $this->scan('2026-03-21');
        $alert = $this->open();

        // Told once: owner, project manager and site engineer, who may change the register.
        foreach ([$this->owner, $this->pm, $this->engineer] as $user) {
            $this->assertSame(1, $user->notifications()->count(), $user->role);
        }

        // The estimator reads the register but does not keep it; accounts, a viewer and someone on another project have no part in it.
        foreach ([$this->qs, $accounts, $viewer, $elsewhere] as $user) {
            $this->assertSame(0, $user->notifications()->count(), $user->role);
        }

        $english = $this->engineer->notifications()->sole()->data;
        $this->assertSame('Notice of delay: date coming up · Lake View Residency', $english['title']);
        $this->assertStringContainsString('is 23 Mar 2026', $english['body']);
        $this->assertStringEndsWith('#hindrance-'.$entry->id, $english['url']);

        $hindi = $this->pm->notifications()->sole()->data;
        $this->assertSame(__('Notice of delay: date coming up', [], 'hi').' · Lake View Residency', $hindi['title']);
        $this->assertSame(1, preg_match('/\p{Devanagari}/u', $hindi['body']));
        $this->assertStringContainsString('23 Mar 2026', $hindi['body']);

        foreach ([$this->owner, $this->pm, $this->engineer, $this->qs] as $user) {
            $this->assertTrue(Alert::withoutGlobalScopes()->visibleTo($user)->whereKey($alert->id)->exists(), $user->role);
        }

        foreach ([$accounts, $viewer] as $user) {
            $this->assertFalse(Alert::withoutGlobalScopes()->visibleTo($user)->whereKey($alert->id)->exists(), $user->role);
            $this->actingAs($user)->get(route('alerts.index', $this->project))->assertOk()->assertDontSee('Notice of delay')->assertDontSee('23 Mar 2026');
        }

        $this->actingAs($this->engineer)->get(route('alerts.index', $this->project))->assertOk()
            ->assertSee('Notice of delay: date coming up')
            ->assertSee('For the hindrance that started on 12 Mar 2026, the date worked out from the notice period you entered is 23 Mar 2026.');
        $this->actingAs($elsewhere)->get(route('alerts.index', $this->project))->assertNotFound();
    }

    public function test_the_source_is_registered_and_names_every_line_it_can_raise(): void
    {
        $this->assertContains(NoticeDeadlineSource::class, config('modules.site_diary.alert_sources'));
        $this->assertContains(NoticeDeadlineSource::class, array_map(fn ($source) => $source::class, app(AlertEngine::class)->sources()));
        $this->assertSame([NoticeDeadlineSource::TYPE], app(NoticeDeadlineSource::class)->types());

        $wording = NoticeDeadlineSource::wording();
        $this->assertCount(10, $wording);
        $this->assertSame($wording, array_values(array_unique($wording)));

        // Every notice a person can enter a period for has its own pair of titles.
        foreach (array_keys(config('modules.contract_terms.codes.notice')) as $code) {
            $entry = $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null]);
            $this->notice(['code' => $code, 'label' => 'Typed name']);
            $this->scan(self::TODAY);

            $title = $this->reminders()->firstWhere('reference', 'hindrance:'.$entry->id.':'.$code)->title;
            $this->assertContains($title, $wording);
            $this->assertNotSame('Notice date coming up', $title, $code);
        }
    }
}
