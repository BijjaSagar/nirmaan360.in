<?php

namespace Tests\Feature\SiteDiary;

use App\Models\Hindrance;
use App\Models\HindranceSource;
use App\Services\SiteDiary\HindranceRegister;
use App\Services\SiteDiary\Hindrances;
use App\Services\SiteDiary\NoticeDeadline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** What the register hands to the screens and, later, to the letters: figures worked out from dates and reports alone. */
class HindranceRegisterServiceTest extends SiteDiaryTestCase
{
    private function today(): Carbon
    {
        return Carbon::parse(self::TODAY.' 09:00:00', 'Asia/Kolkata');
    }

    public function test_the_figures_of_an_entry_are_counts_of_days_and_reports(): void
    {
        // 2 to 6 March. Reports on the 2nd (approved), the 3rd (returned) and the 9th (rejected, outside the period).
        $reports = [
            $this->dpr('2026-03-02', 'No drawings', ['status' => 'APPROVED']),
            $this->dpr('2026-03-03', 'Still no drawings', ['status' => 'RETURNED']),
            $this->dpr('2026-03-09', 'Drawings were a week late', ['status' => 'REJECTED']),
        ];
        // A report of the 5th that says nothing about the hindrance, and one on another project on the 4th.
        $this->dpr('2026-03-05', null);
        $this->dpr('2026-03-04', 'Elsewhere', [], $this->makeProject($this->org, ['code' => 'SG2']));
        $entry = $this->cite($this->hindrance(), ...$reports);

        $figures = app(HindranceRegister::class)->figures($entry->fresh(), $this->today());

        $this->assertSame(5, $figures['days']);
        $this->assertSame('2026-03-06', $figures['counted_to']->toDateString());
        $this->assertFalse($figures['ongoing']);
        $this->assertSame(2, $figures['days_with_cited_report']);
        $this->assertSame(2, $figures['days_without_report'], 'The 4th and the 6th have no report of this project.');
        $this->assertSame(['cited' => 3, 'approved' => 1, 'editable' => 1, 'changed' => 0, 'gone' => 0, 'outside' => 1, 'first' => '2026-03-02', 'last' => '2026-03-09'], $figures['reports']);
        $this->assertSame(['APPROVED', 'RETURNED', 'REJECTED'], array_column($figures['sources'], 'status'));
        $this->assertSame([true, false, false], array_column($figures['sources'], 'approved'));
        $this->assertSame([false, true, false], array_column($figures['sources'], 'editable'));

        // The status shown is the report's status today, not the one copied when it was cited.
        $reports[1]->update(['status' => 'APPROVED']);
        $this->assertSame(2, app(HindranceRegister::class)->figures($entry->fresh(), $this->today())['reports']['approved']);
        $this->assertSame('RETURNED', $entry->sources()->get()[1]->dpr_status);
    }

    public function test_an_entry_still_going_on_is_counted_up_to_the_day_asked_about(): void
    {
        $entry = $this->hindrance(['started_on' => '2026-03-16', 'ended_on' => null]);
        $register = app(HindranceRegister::class);

        $this->assertSame([5, true, '2026-03-20'], [$register->figures($entry, $this->today())['days'], $register->figures($entry, $this->today())['ongoing'], $register->figures($entry, $this->today())['counted_to']->toDateString()]);
        $this->assertSame(1, $register->figures($entry, Carbon::parse('2026-03-16'))['days']);
        $this->assertSame([0, null], [$register->figures($entry, Carbon::parse('2026-03-10'))['days'], $register->figures($entry, Carbon::parse('2026-03-10'))['counted_to']]);
    }

    public function test_the_total_counts_a_shared_day_once(): void
    {
        $entries = [
            $this->hindrance(['started_on' => '2026-03-02', 'ended_on' => '2026-03-06']),
            $this->hindrance(['started_on' => '2026-03-05', 'ended_on' => '2026-03-09']),
            $this->hindrance(['started_on' => '2026-03-18', 'ended_on' => null]),
        ];

        $total = app(HindranceRegister::class)->total($entries, $this->today());

        // 2 to 9 March is 8 days, 18 to 20 March is 3: 11 days, where adding each entry's own days would give 13.
        $this->assertSame(11, $total['days']);
        $this->assertSame([['from' => '2026-03-02', 'to' => '2026-03-09', 'days' => 8], ['from' => '2026-03-18', 'to' => '2026-03-20', 'days' => 3]], $total['periods']);
        $this->assertSame([3, 1], [$total['entries'], $total['ongoing']]);

        $this->assertSame(['days' => 0, 'periods' => [], 'entries' => 0, 'ongoing' => 0], app(HindranceRegister::class)->total([], $this->today()));
    }

    public function test_the_entries_that_count_are_the_confirmed_ones_no_later_entry_corrects(): void
    {
        $unconfirmed = ['confirmed_by' => null, 'confirmed_at' => null];
        $plain = $this->hindrance();
        $cited = $this->hindrance(['locked_at' => now()]);
        $corrected = $this->hindrance(['locked_at' => now()]);
        $correction = $this->hindrance(['supersedes_id' => $corrected->id]);
        $citedAgain = $this->hindrance(['locked_at' => now()]);
        $this->hindrance(['supersedes_id' => $citedAgain->id, 'status' => Hindrance::DISMISSED, ...$unconfirmed]);
        $this->hindrance(['status' => Hindrance::PROPOSED, ...$unconfirmed]);
        $this->hindrance(['status' => Hindrance::DISMISSED, ...$unconfirmed]);
        $this->hindrance()->delete();
        $this->hindrance([], $this->makeProject($this->org, ['code' => 'SG2']));
        [, , $rivalProject] = $this->rival();
        $this->hindrance([], $rivalProject);

        $expected = [$plain->id, $cited->id, $correction->id, $citedAgain->id];

        // The same answer for someone signed in and for the scheduler, where nobody is.
        $this->actingAs($this->pm);
        $this->assertSame($expected, app(Hindrances::class)->standing($this->project)->pluck('id')->all());
        Auth::forgetGuards();
        $this->assertSame($expected, app(Hindrances::class)->standing($this->project)->pluck('id')->all());

        $this->actingAs($this->pm);
        $entries = app(HindranceRegister::class)->entries($this->project, $this->today());
        $this->assertSame($expected, $entries->where('counts', true)->pluck('hindrance.id')->all());
        $this->assertSame($correction->id, $entries->firstWhere('hindrance.id', $corrected->id)['corrected_by']->id);
        $this->assertNull($entries->firstWhere('hindrance.id', $citedAgain->id)['corrected_by'], 'A dismissed correction corrects nothing.');
        // A notice counts as given only from a finalised letter that cites the entry or the entry it corrects, not from
        // a lock or from being a correction (NoticeDeadlineAlertTest goes through the letters).
        $this->notice();
        $entries = app(HindranceRegister::class)->entries($this->project, $this->today());
        $this->assertCount(4, $entries->where('counts', true)->filter(fn (array $row) => $row['deadlines'] !== []));
        $this->assertSame([], $entries->pluck('given')->flatten()->all());
    }

    public function test_only_entries_that_count_carry_notice_dates(): void
    {
        $this->notice();
        $counting = $this->hindrance();
        $proposed = $this->hindrance(['status' => Hindrance::PROPOSED, 'confirmed_by' => null, 'confirmed_at' => null]);

        $entries = app(HindranceRegister::class)->entries($this->project, $this->today())->keyBy('hindrance.id');

        $this->assertSame([NoticeDeadline::KNOWN], array_map(fn (NoticeDeadline $deadline) => $deadline->state, $entries[$counting->id]['deadlines']));
        $this->assertSame([], $entries[$proposed->id]['deadlines']);
    }

    public function test_the_reports_offered_come_with_the_entries_that_already_use_them(): void
    {
        $used = $this->dpr('2026-03-02', 'No drawings');
        $free = $this->dpr('2026-03-03', 'Rain');
        $dismissedOnly = $this->dpr('2026-03-04', 'Power cut');
        $one = $this->cite($this->hindrance(), $used);
        $two = $this->cite($this->hindrance(['category' => 'weather']), $used);
        $this->cite($this->hindrance(['status' => Hindrance::DISMISSED]), $dismissedOnly);

        $offer = app(HindranceRegister::class)->candidates($this->project);

        $this->assertSame([$dismissedOnly->id, $free->id, $used->id], $offer['reports']->pluck('id')->all(), 'Newest first.');
        $this->assertSame([$used->id => [$one->id, $two->id]], $offer['used']);
        $this->assertFalse($offer['more']);

        $this->assertSame([$free->id], app(HindranceRegister::class)->candidates($this->project, '2026-03-03', '2026-03-03')['reports']->pluck('id')->all());
    }

    public function test_the_service_refuses_what_the_form_rules_cannot_see(): void
    {
        $report = $this->dpr('2026-03-04', 'No power at site');
        $blank = $this->dpr('2026-03-05', null);
        $hindrances = app(Hindrances::class);
        $data = fn (array $overrides) => ['client_uuid' => (string) Str::uuid(), 'category' => 'weather', 'description' => 'Rain', 'dpr_ids' => [$report->id], ...$overrides];

        foreach ([
            'dpr_ids' => ['dpr_ids' => [$report->id, $blank->id]],
            'ended_on' => ['started_on' => '2026-03-10'],
            'supersedes_id' => ['supersedes_id' => $this->hindrance()->id],
        ] as $field => $overrides) {
            try {
                $hindrances->create($this->project, $data($overrides), $this->pm);
                $this->fail("Nothing was refused for {$field}.");
            } catch (ValidationException $exception) {
                $this->assertSame([$field], array_keys($exception->errors()));
            }
        }

        $this->assertSame(1, Hindrance::withoutGlobalScopes()->count(), 'A refused entry leaves nothing behind.');
        $this->assertSame(0, HindranceSource::withoutGlobalScopes()->count());

        $this->assertTrue(Hindrances::saysSomething('Rain'));
        $this->assertTrue(Hindrances::saysSomething('पाऊस'));
        $this->assertTrue(Hindrances::saysSomething('5'));

        foreach ([null, '', '   ', '--', '.', "\n", '—'] as $text) {
            $this->assertFalse(Hindrances::saysSomething($text), var_export($text, true));
        }
    }
}
