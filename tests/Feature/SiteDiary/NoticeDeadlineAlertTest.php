<?php

namespace Tests\Feature\SiteDiary;

use App\Models\Alert;
use App\Models\ContractTerm;
use App\Models\Hindrance;
use App\Models\Project;
use App\Models\SiteLetter;
use App\Models\SiteLetterHindrance;
use App\Services\SiteAlerts\AlertEngine;
use App\Services\SiteDiary\HindranceRegister;
use App\Services\SiteDiary\Hindrances;
use App\Services\SiteDiary\LetterFacts;
use App\Services\SiteDiary\NoticeDeadlineSource;
use App\Services\SiteDiary\SiteLetters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The reminder of a notice date. The notice periods (11, 9, 7 and 4 days) are invented for the test;
 * the reminder window is the app's own setting in config/modules/site_diary.php.
 */
class NoticeDeadlineAlertTest extends SiteLetterTestCase
{
    /** A letter of this kind citing these entries, prepared and finalised by the project manager as the letters page does. */
    private function letter(string $type, Hindrance ...$entries): SiteLetter
    {
        $letter = $this->draft($type, ['hindrance_ids' => array_map(fn (Hindrance $entry) => $entry->id, $entries)]);

        return app(SiteLetters::class)->finalise($letter, $this->ticks($letter), $this->pm);
    }

    /** @return list<string> The references of the reminders that are open. */
    private function openReferences(): array
    {
        return $this->reminders()->where('status', Alert::OPEN)->pluck('reference')->values()->all();
    }

    private function extensionNotice(array $attributes = []): void
    {
        $this->notice(['code' => 'eot_notice', 'label' => 'Notice for extension of time', ...$attributes]);
    }

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

    public function test_only_a_confirmed_entry_whose_notice_no_finalised_letter_gave_is_reminded_of(): void
    {
        $this->notice();
        $dates = ['started_on' => '2026-03-12', 'ended_on' => null];
        $unconfirmed = ['confirmed_by' => null, 'confirmed_at' => null];

        $this->hindrance([...$dates, ...$unconfirmed, 'status' => Hindrance::PROPOSED, 'proposed_by' => Hindrance::BY_AI]);
        $this->hindrance([...$dates, ...$unconfirmed, 'status' => Hindrance::DISMISSED]);
        $this->hindrance($dates)->delete();
        // A finalised notice of delay cites this one. The next is the correction that stands in its place: it is
        // the same hindrance, so the notice of delay was given for it too.
        $cited = $this->entry('2026-03-12', null);
        $this->letter(self::DELAY, $cited);
        $this->hindrance([...$dates, 'supersedes_id' => $cited->id]);
        // The date of this one is still far away, and this one's passed long ago.
        $this->hindrance(['started_on' => '2026-03-19', 'ended_on' => null]);
        $this->hindrance(['started_on' => '2026-01-05', 'ended_on' => '2026-01-09']);

        $this->scan(self::TODAY);
        $this->assertCount(0, $this->reminders());

        $entry = $this->entry('2026-03-12', null);
        $this->scan(self::TODAY);
        $this->assertSame('hindrance:'.$entry->id.':delay_notice', $this->reminders()->sole()->reference);

        // Dismissing the entry closes its reminder.
        $entry->update(['status' => Hindrance::DISMISSED]);
        $this->scan(self::TODAY);
        $this->assertNull($this->open());

        // Back in the register, it is reminded of again, until a finalised letter cites it. A draft gives no notice.
        $entry->update(['status' => Hindrance::CONFIRMED]);
        $draft = $this->draft(self::DELAY, ['hindrance_ids' => [$entry->id]]);
        $this->scan(self::TODAY);
        $this->assertNotNull($this->open());

        app(SiteLetters::class)->finalise($draft, $this->ticks($draft), $this->pm);
        $this->scan(self::TODAY);
        $this->assertNull($this->open());
    }

    public function test_a_correction_that_records_the_end_of_an_entry_a_notice_of_delay_cites_is_reminded_of_the_extension_of_time(): void
    {
        // A notice of delay counted from the start, a notice for extension of time counted from the end.
        $this->agreement();
        $this->notice();
        $this->extensionNotice(['days' => 7, 'counted_from' => 'hindrance_end']);

        // Still going on: the notice of delay falls on 23 March, the other cannot be worked out yet.
        $entry = $this->entry('2026-03-12', null);
        $this->scan(self::TODAY);
        $this->assertSame(['hindrance:'.$entry->id.':delay_notice'], $this->openReferences());

        // The notice of delay goes out and locks the entry.
        $this->letter(self::DELAY, $entry);
        $this->assertNotNull($entry->refresh()->locked_at);
        $this->scan(self::TODAY);
        $this->assertSame([], $this->openReferences());

        // The hindrance ended on 19 March. The locked entry cannot take its end date, so a correction records it, as the register's form does.
        $correction = app(Hindrances::class)->create($this->project, $this->form([$entry->sources->first()->dpr_id], [
            'supersedes_id' => $entry->id, 'started_on' => '2026-03-12', 'ended_on' => '2026-03-19',
        ]), $this->pm);

        // Seven days from the end gives 26 March. The notice of delay was given for this hindrance, so it is not asked for again.
        $this->scan(self::TODAY);
        $alert = $this->open();
        $this->assertSame(['hindrance:'.$correction->id.':eot_notice'], $this->openReferences());
        $this->assertSame(['Extension of time notice: date coming up', '2026-03-26', 6], [$alert->title, $alert->data['date'], $alert->data['days_left']]);

        // The register shades only the notice that was given.
        $row = app(HindranceRegister::class)->entries($this->project, today())->firstWhere('hindrance.id', $correction->id);
        $this->assertSame(['delay_notice'], $row['given']);

        // The request for extension of time cites the correction, and the reminder closes.
        $this->letter(self::EOT, $correction);
        $this->scan(self::TODAY);
        $this->assertSame([], $this->openReferences());
        $row = app(HindranceRegister::class)->entries($this->project, today())->firstWhere('hindrance.id', $correction->id);
        $this->assertSame(['eot_notice', 'delay_notice'], $row['given']);
    }

    public function test_a_request_for_extension_of_time_on_an_entry_that_has_ended_closes_the_notice_of_delay_reminder(): void
    {
        // Eleven days from the start gives 23 March. The hindrance ran from 12 to 15 March and was entered after it ended.
        $this->agreement();
        $this->notice();
        $entry = $this->entry('2026-03-12', '2026-03-15');

        $this->scan(self::TODAY);
        $this->assertSame(['hindrance:'.$entry->id.':delay_notice'], $this->openReferences());

        // It has ended, so no notice of delay can cite it. A request for extension of time can.
        $this->assertFalse(app(LetterFacts::class)->citable($this->project, self::DELAY, today())->contains('id', $entry->id));
        $this->assertTrue(app(LetterFacts::class)->citable($this->project, self::EOT, today())->contains('id', $entry->id));

        $this->letter(self::EOT, $entry);
        $this->scan(self::TODAY);
        $this->assertSame([], $this->openReferences());
        $this->assertSame(Alert::RESOLVED, $this->reminders()->sole()->status);

        // Nor is it raised again on the day or once the date has passed, and the register shows the notice as given.
        $this->scan('2026-03-23');
        $this->scan('2026-03-25');
        $this->assertSame([], $this->openReferences());
        $this->assertCount(1, $this->reminders());

        $row = app(HindranceRegister::class)->entries($this->project, today())->firstWhere('hindrance.id', $entry->id);
        $this->assertSame(['delay_notice'], $row['given']);
    }

    public function test_a_notice_of_delay_leaves_the_extension_of_time_open_and_a_request_for_it_closes_both(): void
    {
        // Both counted from the start: 21 March for the extension of time, 23 March for the notice of delay.
        $this->agreement();
        $this->notice();
        $this->extensionNotice(['days' => 9]);
        $entry = $this->entry('2026-03-12', null);

        $this->scan(self::TODAY);
        $this->assertSame(['hindrance:'.$entry->id.':eot_notice', 'hindrance:'.$entry->id.':delay_notice'], $this->openReferences());

        // A notice of delay asks for no more time.
        $this->letter(self::DELAY, $entry);
        $this->scan(self::TODAY);
        $this->assertSame(['hindrance:'.$entry->id.':eot_notice'], $this->openReferences(), 'The entry is locked, and its extension of time is still to be asked for.');

        $this->letter(self::EOT, $entry);
        $this->scan(self::TODAY);
        $this->assertSame([], $this->openReferences());

        // A request for extension of time sent first gives both notices at once.
        $first = $this->entry('2026-03-12', null);
        $this->scan(self::TODAY);
        $this->assertSame(['hindrance:'.$first->id.':eot_notice', 'hindrance:'.$first->id.':delay_notice'], $this->openReferences());
        $this->letter(self::EOT, $first);
        $this->scan(self::TODAY);
        $this->assertSame([], $this->openReferences());

        // Another company's letter gives no notice here, even with a citation row that names our entry.
        $other = $this->entry('2026-03-12', null);
        [$rivalOrg, $rivalOwner, $rivalProject] = $this->rival();
        $this->notice([], $rivalProject);
        $theirs = $this->entry('2026-03-12', null, [], null, $rivalProject);
        $letter = $this->draft(self::DELAY, ['hindrance_ids' => [$theirs->id]], $rivalOwner, $rivalProject);
        app(SiteLetters::class)->finalise($letter, $this->ticks($letter), $rivalOwner);
        SiteLetterHindrance::withoutGlobalScopes()->create(['organization_id' => $rivalOrg->id, 'project_id' => $rivalProject->id, 'site_letter_id' => $letter->id, 'hindrance_id' => $other->id]);

        Auth::forgetGuards();
        $this->scan(self::TODAY);
        $this->assertSame(['hindrance:'.$other->id.':eot_notice', 'hindrance:'.$other->id.':delay_notice'], $this->openReferences());
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
