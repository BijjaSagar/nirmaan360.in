<?php

namespace Tests\Feature\SiteDiary;

use App\Models\ContractTerm;
use App\Models\Hindrance;
use App\Models\Party;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\SiteDiary\NoticeDeadline;
use App\Services\SiteDiary\NoticeDeadlines;
use App\Services\SiteDiary\NoticePart;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;

/**
 * A notice date exists only where a person entered and confirmed a notice period under Contract
 * terms. The periods below (11, 4, 30 days and so on) are invented for these tests.
 */
class NoticeDeadlineTest extends SiteDiaryTestCase
{
    /** @return list<NoticeDeadline> */
    private function deadlines(Hindrance $entry): array
    {
        return app(NoticeDeadlines::class)->for($entry);
    }

    private function sole(Hindrance $entry): NoticeDeadline
    {
        $deadlines = $this->deadlines($entry);
        $this->assertCount(1, $deadlines);

        return $deadlines[0];
    }

    private function page(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->pm)->get(route('site-diary.hindrances.index', $this->project))->assertOk();
    }

    public function test_with_no_notice_period_entered_there_is_no_date_and_the_page_says_so(): void
    {
        $entry = $this->hindrance();

        $this->assertSame([], $this->deadlines($entry));
        $this->assertSame(['confirmed' => 0, 'unconfirmed' => 0], app(NoticeDeadlines::class)->entered($this->project));

        $this->page()
            ->assertSee('No notice period is entered for this contract. Enter it under Contract terms to see deadlines.')
            ->assertSee('None. No notice period is confirmed for this contract.')
            ->assertDontSee('Notice of delay')
            ->assertDontSee('in 0 days');
    }

    public function test_a_period_that_nobody_confirmed_does_not_count(): void
    {
        $entry = $this->hindrance();
        $this->notice(['confirmed_by' => null, 'confirmed_at' => null]);

        $this->assertSame([], $this->deadlines($entry));
        $this->assertSame(['confirmed' => 0, 'unconfirmed' => 1], app(NoticeDeadlines::class)->entered($this->project));

        $this->page()
            ->assertSee('A notice period is typed under Contract terms, but nobody has confirmed it, so it does not count. Confirm it there to see deadlines.')
            ->assertDontSee('No notice period is entered for this contract.')
            ->assertDontSee('13 Mar 2026');
    }

    public function test_a_subcontract_period_a_removed_period_and_another_projects_period_do_not_count(): void
    {
        $entry = $this->hindrance();
        $party = Party::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'type' => 'subcontractor', 'name' => 'Trimurti Shuttering']);
        $workOrder = WorkOrder::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'party_id' => $party->id, 'number' => 'WO-11', 'title' => 'Slab shuttering', 'status' => 'ACTIVE', 'rate_type' => 'item_rate']);

        $this->notice(['work_order_id' => $workOrder->id]);
        $this->notice()->delete();
        $this->notice([], $this->makeProject($this->org, ['code' => 'SG2']));
        [, , $rivalProject] = $this->rival();
        $this->notice([], $rivalProject);

        $this->assertSame([], $this->deadlines($entry));
        $this->assertSame(['confirmed' => 0, 'unconfirmed' => 0], app(NoticeDeadlines::class)->entered($this->project));
    }

    public function test_a_confirmed_period_is_added_to_the_day_the_person_said_it_counts_from(): void
    {
        // Started on 2 March. The term says 11 days counted from the day the hindrance started.
        $entry = $this->hindrance();
        $term = $this->notice();

        $deadline = $this->sole($entry);

        $this->assertSame(['delay_notice', NoticeDeadline::KNOWN, '2026-03-13', null], [$deadline->code, $deadline->state, $deadline->date->toDateString(), $deadline->combine]);
        $this->assertTrue($deadline->hasDate());
        $this->assertSame(-7, $deadline->daysLeft(self::TODAY));
        $this->assertSame(3, $deadline->daysLeft('2026-03-10'));
        $this->assertSame(0, $deadline->daysLeft('2026-03-13'));
        $this->assertSame([['term_id' => $term->id, 'days' => 11, 'counted_from' => 'hindrance_start', 'from' => '2026-03-02', 'date' => '2026-03-13', 'missing' => null]], $deadline->toArray()['parts']);

        // The number comes from the term: change what the person typed and the date follows.
        $term->update(['days' => 4]);
        $this->assertSame('2026-03-06', $this->sole($entry)->date->toDateString());

        // The answer is the same with nobody signed in, as on the scheduler.
        Auth::forgetGuards();
        $this->assertSame('2026-03-06', $this->sole($entry->fresh())->date->toDateString());
    }

    public function test_the_page_shows_the_date_with_the_period_it_came_from(): void
    {
        $entry = $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null]);
        $this->notice();

        $page = $this->page()
            ->assertSee('Notice of delay')
            ->assertSee('23 Mar 2026')
            ->assertSee('in 3 days')
            ->assertSee('11 days')
            ->assertSee('Counted from: The day the hindrance started')
            ->assertSee('Counted from 12 Mar 2026, this gives 23 Mar 2026.')
            ->assertSee('The app added the days of each period, as it was entered, to the day shown. It did not read your agreement. Check every date against the agreement before you rely on it.')
            ->assertDontSee('No notice period is entered for this contract.');

        // The project manager may open Contract terms, so is shown where the period was read and a way there.
        $page->assertSee('Sample agreement, part H')->assertSee(route('contract-terms.index', $this->project), false);

        // The site engineer sees the date and the period, but not the clause typed for it nor the link.
        $this->page($this->engineer)
            ->assertSee('23 Mar 2026')->assertSee('in 3 days')->assertSee('11 days')
            ->assertDontSee('Sample agreement, part H')
            ->assertDontSee(route('contract-terms.index', $this->project), false);

        foreach ([['2026-03-23', 'today'], ['2026-03-22', 'tomorrow'], ['2026-03-24', 'yesterday'], ['2026-03-30', '7 days ago']] as [$day, $words]) {
            $this->travelTo($day.' 09:00:00');
            $this->page()->assertSee('23 Mar 2026')->assertSee($words);
        }

        $this->assertNotNull($entry->id);
    }

    public function test_a_period_counted_from_the_end_waits_for_the_end(): void
    {
        $this->notice(['code' => 'eot_notice', 'label' => 'Notice for extension of time', 'days' => 4, 'counted_from' => 'hindrance_end']);
        $ongoing = $this->hindrance(['ended_on' => null]);
        $ended = $this->hindrance(['ended_on' => '2026-03-06']);

        $open = $this->sole($ongoing);
        $this->assertSame([NoticeDeadline::OPEN, null], [$open->state, $open->date]);
        $this->assertNull($open->daysLeft(self::TODAY));
        $this->assertSame([null, null, NoticePart::NO_END], [$open->parts[0]->from, $open->parts[0]->date, $open->parts[0]->missing]);

        $known = $this->sole($ended);
        $this->assertSame([NoticeDeadline::KNOWN, '2026-03-10'], [$known->state, $known->date->toDateString()]);
        $this->assertSame('2026-03-06', $known->parts[0]->from->toDateString());

        $this->page()
            ->assertSee('Notice for extension of time')
            ->assertSee('cannot be worked out yet')
            ->assertSee('The hindrance is still going on, so there is no day to count from yet.')
            ->assertSee('Counted from 06 Mar 2026, this gives 10 Mar 2026.');
    }

    public function test_a_period_counted_from_the_completion_date_needs_that_date_confirmed_too(): void
    {
        $entry = $this->hindrance();
        $this->notice(['code' => 'eot_notice', 'label' => 'Notice for extension of time', 'days' => 30, 'counted_from' => 'stipulated_completion']);

        $open = $this->sole($entry);
        $this->assertSame([NoticeDeadline::OPEN, null, NoticePart::NO_COMPLETION_DATE], [$open->state, $open->date, $open->parts[0]->missing]);
        $this->page()->assertSee('No confirmed stipulated completion date covers the day this hindrance started, so there is no day to count from.');

        // Typed but not confirmed: still nothing to count from.
        $date = $this->completionDate('2026-06-30', ['confirmed_by' => null, 'confirmed_at' => null]);
        $this->assertSame(NoticeDeadline::OPEN, $this->sole($entry)->state);

        $date->update(['confirmed_by' => $this->owner->id, 'confirmed_at' => now()]);
        $known = $this->sole($entry);
        $this->assertSame([NoticeDeadline::KNOWN, '2026-07-30', '2026-06-30'], [$known->state, $known->date->toDateString(), $known->parts[0]->from->toDateString()]);

        // An extension granted is another term and is not put in the place of the date the person chose.
        $this->completionDate('2026-09-30', ['code' => 'extension_granted', 'label' => 'Extension granted up to']);
        $this->assertSame('2026-07-30', $this->sole($entry)->date->toDateString());

        // Two confirmed completion dates for the same day (rows written straight to the table) leave the app without one.
        $this->completionDate('2026-08-31');
        $this->assertSame([NoticeDeadline::OPEN, NoticePart::NO_COMPLETION_DATE], [$this->sole($entry)->state, $this->sole($entry)->parts[0]->missing]);
    }

    public function test_two_periods_entered_as_one_group_give_the_earlier_or_the_later_date_as_entered(): void
    {
        // Ended 6 March. 11 days from the start gives 13 March; 4 days from the end gives 10 March.
        $entry = $this->hindrance();
        $fromStart = $this->notice(['combine' => 'earliest', 'combine_group' => 'N1']);
        $fromEnd = $this->notice(['days' => 4, 'counted_from' => 'hindrance_end', 'combine' => 'earliest', 'combine_group' => 'N1']);

        $deadline = $this->sole($entry);
        $this->assertSame([NoticeDeadline::KNOWN, '2026-03-10', 'earliest'], [$deadline->state, $deadline->date->toDateString(), $deadline->combine]);
        $this->assertSame(['2026-03-13', '2026-03-10'], array_map(fn (NoticePart $part) => $part->date->toDateString(), $deadline->parts));

        $this->page()
            ->assertSee('10 Mar 2026')
            ->assertSee('Counted from 02 Mar 2026, this gives 13 Mar 2026.')
            ->assertSee('Counted from 06 Mar 2026, this gives 10 Mar 2026.')
            ->assertSee('Entered as periods that go together: Whichever comes first.');

        ContractTerm::whereKey([$fromStart->id, $fromEnd->id])->update(['combine' => 'latest']);

        $deadline = $this->sole($entry);
        $this->assertSame([NoticeDeadline::KNOWN, '2026-03-13', 'latest'], [$deadline->state, $deadline->date->toDateString(), $deadline->combine]);
        $this->page()->assertSee('Entered as periods that go together: Whichever comes later.');

        // A group may hold more than two periods.
        $this->notice(['days' => 20, 'combine' => 'latest', 'combine_group' => 'N1']);
        $this->assertSame('2026-03-22', $this->sole($entry)->date->toDateString());
    }

    public function test_while_one_period_of_a_group_cannot_be_worked_out_the_app_says_only_what_it_can(): void
    {
        $entry = $this->hindrance(['ended_on' => null]);
        $fromStart = $this->notice(['combine' => 'earliest', 'combine_group' => 'N1']);
        $fromEnd = $this->notice(['days' => 4, 'counted_from' => 'hindrance_end', 'combine' => 'earliest', 'combine_group' => 'N1']);

        // Whichever comes first: it cannot be later than the date already known.
        $deadline = $this->sole($entry);
        $this->assertSame([NoticeDeadline::NOT_LATER_THAN, '2026-03-13', 'earliest'], [$deadline->state, $deadline->date->toDateString(), $deadline->combine]);
        $this->assertSame([null, NoticePart::NO_END], [$deadline->parts[1]->date, $deadline->parts[1]->missing]);
        $this->page()
            ->assertSee('not later than 13 Mar 2026')
            ->assertSee('One of the periods cannot be worked out yet, so the date may come sooner than the one shown.');

        // Whichever comes later: nothing can be said until the missing day is entered.
        ContractTerm::whereKey([$fromStart->id, $fromEnd->id])->update(['combine' => 'latest']);
        $deadline = $this->sole($entry);
        $this->assertSame([NoticeDeadline::OPEN, null, 'latest'], [$deadline->state, $deadline->date, $deadline->combine]);
        $this->assertSame('2026-03-13', $deadline->parts[0]->date->toDateString());
    }

    public function test_confirmed_periods_that_do_not_fit_together_give_no_date(): void
    {
        $entry = $this->hindrance();

        // Rows written straight to the table, which the Contract terms screen would have refused to confirm together.
        $cases = [
            'no group on either' => [[], ['days' => 4]],
            'a group on one of them' => [['combine' => 'earliest', 'combine_group' => 'N1'], ['days' => 4]],
            'two different groups' => [['combine' => 'earliest', 'combine_group' => 'N1'], ['days' => 4, 'combine' => 'earliest', 'combine_group' => 'N2']],
            'one group, two choices' => [['combine' => 'earliest', 'combine_group' => 'N1'], ['days' => 4, 'combine' => 'latest', 'combine_group' => 'N1']],
            'a choice the app does not know' => [['combine' => 'average', 'combine_group' => 'N1'], ['days' => 4, 'combine' => 'average', 'combine_group' => 'N1']],
            'a period with no days' => [['days' => null]],
            'a starting point the app does not know' => [['counted_from' => 'bill_date']],
        ];

        foreach ($cases as $name => $rows) {
            ContractTerm::query()->forceDelete();

            foreach ($rows as $row) {
                $this->notice($row);
            }

            $deadline = $this->sole($entry);
            $this->assertSame([NoticeDeadline::UNCLEAR, null], [$deadline->state, $deadline->date], $name);
            $this->assertFalse($deadline->hasDate(), $name);
        }

        ContractTerm::query()->forceDelete();
        $this->notice();
        $this->notice(['days' => 4]);
        // Each period is shown with the date it would give, and no date is put forward as the answer.
        $html = $this->page()
            ->assertSee('More than one confirmed notice period of this name covers the day this hindrance started, and they are not entered as one group, or one of them is incomplete. The app cannot tell which applies. Correct them under Contract terms.')
            ->assertSee('Counted from 02 Mar 2026, this gives 13 Mar 2026.')
            ->assertSee('Counted from 02 Mar 2026, this gives 06 Mar 2026.')
            ->assertDontSee('days ago')
            ->getContent();
        $this->assertSame(1, preg_match('/Notice of delay:\s+cannot be worked out\s+<\/p>/', $html));
    }

    public function test_the_period_in_force_on_the_day_the_hindrance_started_is_the_one_used(): void
    {
        // 11 days up to the end of February, 4 days from March on.
        $this->notice(['effective_to' => '2026-02-28']);
        $this->notice(['days' => 4, 'effective_from' => '2026-03-01']);

        $february = $this->hindrance(['started_on' => '2026-02-27', 'ended_on' => '2026-03-03']);
        $march = $this->hindrance(['started_on' => '2026-03-01', 'ended_on' => '2026-03-03']);
        $before = $this->hindrance(['started_on' => '2025-12-30', 'ended_on' => '2026-01-05']);

        $this->assertSame('2026-03-10', $this->sole($february)->date->toDateString());
        $this->assertSame('2026-03-05', $this->sole($march)->date->toDateString());
        $this->assertSame([], $this->deadlines($before));

        $this->page()->assertSee('No confirmed notice period covers 30 Dec 2025, the day this hindrance started. Check the dates under Contract terms.')
            ->assertDontSee('No notice period is entered for this contract.');
    }

    public function test_each_notice_has_its_own_date_in_the_order_of_the_contract_terms_list(): void
    {
        $entry = $this->hindrance();
        $this->notice();
        $this->notice(['code' => 'eot_notice', 'label' => 'Notice for extension of time', 'days' => 30]);

        $deadlines = $this->deadlines($entry);

        $this->assertSame(['eot_notice', 'delay_notice'], array_map(fn (NoticeDeadline $deadline) => $deadline->code, $deadlines));
        $this->assertSame(['2026-04-01', '2026-03-13'], array_map(fn (NoticeDeadline $deadline) => $deadline->date->toDateString(), $deadlines));
        $this->assertSame(array_keys(config('modules.contract_terms.codes.notice')), ['eot_notice', 'delay_notice']);
    }

    public function test_only_entries_that_count_are_given_a_date_on_the_page(): void
    {
        $this->notice();
        $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null, 'status' => Hindrance::PROPOSED, 'confirmed_by' => null, 'confirmed_at' => null]);
        $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null, 'status' => Hindrance::DISMISSED, 'confirmed_by' => null, 'confirmed_at' => null]);

        $this->page()->assertDontSee('23 Mar 2026')->assertDontSee('Notice dates');

        $this->hindrance(['started_on' => '2026-03-12', 'ended_on' => null]);
        $this->page()->assertSee('23 Mar 2026')->assertSee('Notice dates');
    }

    public function test_dates_are_worked_out_for_one_project_at_a_time(): void
    {
        $this->notice();
        $other = $this->makeProject($this->org, ['code' => 'SG2']);
        $theirs = $this->hindrance([], $other);

        $this->assertSame([], $this->deadlines($theirs));

        $this->expectException(InvalidArgumentException::class);
        app(NoticeDeadlines::class)->forMany($this->project, [$theirs]);
    }
}
