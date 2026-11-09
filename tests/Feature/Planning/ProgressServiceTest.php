<?php

namespace Tests\Feature\Planning;

use App\Services\ApprovalService;
use App\Services\MeasurementService;
use App\Services\Planning\ActivityProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgressServiceTest extends TestCase
{
    use BuildsPlan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlan();
    }

    public function test_only_approved_measurements_count(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $activity = $this->activity('2026-11-01', '2026-11-30');
        $this->link($activity, $item, '100');

        $this->approved($item, '25', '2026-11-05');
        $this->draftEntry($this->project, $item, $this->engineer, ['nos' => '40', 'length' => null, 'breadth' => null, 'depth' => null]);

        $progress = $this->snapshot('2026-11-10')->progress[$activity->id];

        $this->assertSame('25.00', $progress->percent);
        $this->assertSame('2500.00', $progress->earned);
        $this->assertSame('10000.00', $progress->value);
        $this->assertSame(ActivityProgress::IN_PROGRESS, $progress->state);
    }

    public function test_progress_is_weighted_by_value_across_boq_items(): void
    {
        $small = $this->makeBoqItem($this->project, '10.000', '1000.00');
        $large = $this->makeBoqItem($this->project, '100.000', '900.00');
        $activity = $this->activity('2026-11-01', '2026-11-30');
        $this->link($activity, $small, '10');
        $this->link($activity, $large, '100');

        $this->approved($small, '10', '2026-11-05');

        $progress = $this->snapshot('2026-11-10')->progress[$activity->id];

        $this->assertSame('10.00', $progress->percent);
        $this->assertSame('100000.00', $progress->value);
    }

    public function test_a_shared_boq_item_fills_activities_in_schedule_order(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $later = $this->activity('2026-12-01', '2026-12-10');
        $earlier = $this->activity('2026-11-01', '2026-11-10');
        $this->link($later, $item, '60');
        $this->link($earlier, $item, '40');

        $this->approved($item, '50', '2026-11-08');

        $snapshot = $this->snapshot('2026-11-09');

        $this->assertSame('100.00', $snapshot->progress[$earlier->id]->percent);
        $this->assertSame(ActivityProgress::COMPLETE, $snapshot->progress[$earlier->id]->state);
        $this->assertSame('16.67', $snapshot->progress[$later->id]->percent);
    }

    public function test_a_reversal_takes_progress_back(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $activity = $this->activity('2026-11-01', '2026-11-30');
        $this->link($activity, $item, '100');

        $entry = $this->approved($item, '30', '2026-11-05');
        $this->assertSame('30.00', $this->snapshot('2026-11-10')->progress[$activity->id]->percent);

        $reversal = app(MeasurementService::class)->reverse($entry, $this->engineer);
        $approval = app(MeasurementService::class)->submit($reversal, $this->engineer);

        while ($approval->status === 'IN_REVIEW') {
            $approval = app(ApprovalService::class)->act($approval, $this->owner, 'approve');
        }

        $progress = $this->snapshot('2026-11-10')->progress[$activity->id];

        $this->assertSame('0.00', $progress->percent);
        $this->assertSame(ActivityProgress::NOT_STARTED, $progress->state);
        $this->assertNull($progress->actualStart);
    }

    public function test_measuring_more_than_the_link_stops_at_one_hundred_percent(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $activity = $this->activity('2026-11-01', '2026-11-30');
        $this->link($activity, $item, '50');

        $this->approved($item, '80', '2026-11-05');

        $progress = $this->snapshot('2026-11-10')->progress[$activity->id];

        $this->assertSame('100.00', $progress->percent);
        $this->assertSame('5000.00', $progress->earned);
    }

    public function test_an_activity_only_reads_complete_when_it_truly_is(): void
    {
        $item = $this->makeBoqItem($this->project, '100000.000', '1.00');
        $activity = $this->activity('2026-11-01', '2026-11-30');
        $this->link($activity, $item, '100000');

        $this->approved($item, '99999.999', '2026-11-05');

        $progress = $this->snapshot('2026-11-10')->progress[$activity->id];

        $this->assertSame('99.99', $progress->percent);
        $this->assertSame(ActivityProgress::IN_PROGRESS, $progress->state);
    }

    public function test_an_activity_without_a_boq_link_has_no_progress_and_is_never_delayed(): void
    {
        $activity = $this->activity('2026-11-01', '2026-11-02');

        $snapshot = $this->snapshot('2026-11-20');
        $progress = $snapshot->progress[$activity->id];

        $this->assertNull($progress->percent);
        $this->assertSame(ActivityProgress::UNLINKED, $progress->state);
        $this->assertFalse($progress->isDelayed());
        $this->assertSame(1, $snapshot->summary()['unlinked']);
    }

    public function test_actual_dates_come_from_the_measurement_dates(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $activity = $this->activity('2026-11-01', '2026-11-30');
        $this->link($activity, $item, '100');

        $this->approved($item, '10', '2026-11-03');
        $this->approved($item, '90', '2026-11-20');

        $progress = $this->snapshot('2026-11-25')->progress[$activity->id];

        $this->assertSame('2026-11-03', $progress->actualStart);
        $this->assertSame('2026-11-20', $progress->actualFinish);
    }

    public function test_delay_states(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');

        $done = $this->activity('2026-10-28', '2026-11-05');
        $overdue = $this->activity('2026-11-01', '2026-11-10');
        $lateStart = $this->activity('2026-11-12', '2026-11-30');
        $notYet = $this->activity('2026-11-14', '2026-11-30');

        $this->link($done, $item, '10');
        $this->link($overdue, $item, '40');
        $this->link($lateStart, $item, '20');
        $this->link($notYet, $item, '30');

        $this->approved($item, '30', '2026-11-04');

        $snapshot = $this->snapshot('2026-11-15');

        $this->assertSame(ActivityProgress::OVERDUE, $snapshot->progress[$overdue->id]->delayKind);
        $this->assertSame(5, $snapshot->progress[$overdue->id]->delayDays);

        $this->assertSame(ActivityProgress::LATE_START, $snapshot->progress[$lateStart->id]->delayKind);
        $this->assertSame(3, $snapshot->progress[$lateStart->id]->delayDays);

        $this->assertSame(ActivityProgress::COMPLETE, $snapshot->progress[$done->id]->state);
        $this->assertNull($snapshot->progress[$done->id]->delayKind);

        // One day past its planned start with nothing measured counts as a late start.
        $this->assertSame(ActivityProgress::LATE_START, $snapshot->progress[$notYet->id]->delayKind);
        $this->assertSame(1, $snapshot->progress[$notYet->id]->delayDays);

        // On the planned start day itself it is not late yet.
        $this->assertNull($this->snapshot('2026-11-14')->progress[$notYet->id]->delayKind);
    }

    public function test_the_expected_percent_runs_evenly_over_the_days(): void
    {
        $activity = $this->activity('2026-11-01', '2026-11-10');
        $item = $this->makeBoqItem($this->project, '10.000', '100.00');
        $this->link($activity, $item, '10');

        $this->assertSame('0.00', $this->snapshot('2026-10-31')->progress[$activity->id]->expected);
        $this->assertSame('10.00', $this->snapshot('2026-11-01')->progress[$activity->id]->expected);
        $this->assertSame('50.00', $this->snapshot('2026-11-05')->progress[$activity->id]->expected);
        $this->assertSame('100.00', $this->snapshot('2026-11-20')->progress[$activity->id]->expected);
    }

    /** Two weeks, two equal activities: 70,000 in each. */
    private function twoWeekPlan(): array
    {
        $first = $this->makeBoqItem($this->project, '14.000', '5000.00');
        $second = $this->makeBoqItem($this->project, '14.000', '5000.00');
        $a = $this->activity('2026-11-02', '2026-11-08');
        $b = $this->activity('2026-11-09', '2026-11-15');
        $this->link($a, $first, '14');
        $this->link($b, $second, '14');

        return [$a, $b, $first, $second];
    }

    public function test_s_curve_figures(): void
    {
        [, , $first] = $this->twoWeekPlan();
        $this->approved($first, '7', '2026-11-04');
        $this->approved($first, '7', '2026-11-09');

        $curve = $this->snapshot('2026-11-11')->sCurve();

        $this->assertSame('week', $curve['grain']);
        $this->assertSame('140000.00', $curve['total']);
        $this->assertSame(['2026-11-01', '2026-11-08', '2026-11-11', '2026-11-15'], array_column($curve['points'], 'date'));

        $this->assertSame('0.00', $curve['points'][0]['planned']);
        $this->assertSame('0.00', $curve['points'][0]['actual_pct']);

        $this->assertSame('70000.00', $curve['points'][1]['planned']);
        $this->assertSame('50.00', $curve['points'][1]['planned_pct']);
        $this->assertSame('35000.00', $curve['points'][1]['actual']);
        $this->assertSame('25.00', $curve['points'][1]['actual_pct']);

        // Three of seven days into the second activity: 70,000 + 30,000.
        $this->assertSame('100000.00', $curve['points'][2]['planned']);
        $this->assertSame('71.43', $curve['points'][2]['planned_pct']);
        $this->assertSame('70000.00', $curve['points'][2]['actual']);
        $this->assertSame('50.00', $curve['points'][2]['actual_pct']);
        $this->assertTrue($curve['points'][2]['is_today']);

        $this->assertSame('140000.00', $curve['points'][3]['planned']);
        $this->assertSame('100.00', $curve['points'][3]['planned_pct']);
        $this->assertNull($curve['points'][3]['actual']);
        $this->assertNull($curve['points'][3]['actual_pct']);
    }

    public function test_the_summary_agrees_with_the_curve(): void
    {
        [, , $first] = $this->twoWeekPlan();
        $this->approved($first, '7', '2026-11-04');
        $this->approved($first, '7', '2026-11-09');

        $snapshot = $this->snapshot('2026-11-11');
        $summary = $snapshot->summary();
        $today = collect($snapshot->sCurve()['points'])->firstWhere('is_today', true);

        $this->assertSame($today['planned'], $summary['planned']);
        $this->assertSame($today['actual'], $summary['earned']);
        $this->assertSame('71.43', $summary['planned_pct']);
        $this->assertSame('50.00', $summary['actual_pct']);
        $this->assertSame('-21.43', $summary['variance_pct']);
        $this->assertSame('0.70', $summary['spi']);
        $this->assertSame('140000.00', $summary['total']);
    }

    public function test_a_shared_item_on_the_curve_follows_schedule_order(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $a = $this->activity('2026-11-02', '2026-11-08');
        $b = $this->activity('2026-11-09', '2026-11-15');
        $this->link($a, $item, '40');
        $this->link($b, $item, '60');
        $this->approved($item, '50', '2026-11-08');

        $points = collect($this->snapshot('2026-11-10')->sCurve()['points']);

        $this->assertSame('5000.00', $points->firstWhere('date', '2026-11-08')['actual']);
        $this->assertSame('50.00', $points->firstWhere('date', '2026-11-10')['actual_pct']);
    }

    public function test_the_planned_curve_stays_on_the_baseline_after_replanning(): void
    {
        [, $b] = $this->twoWeekPlan();
        $this->schedule()->takeBaseline($this->project, $this->owner);

        $this->schedule()->updateActivity($b, ['name' => 'Work package', 'planned_start' => '2026-11-20', 'planned_end' => '2026-11-26']);

        $points = collect($this->snapshot('2026-11-11')->sCurve()['points']);

        $this->assertSame('100000.00', $points->firstWhere('date', '2026-11-11')['planned']);
    }

    public function test_a_long_project_is_charted_by_month(): void
    {
        $item = $this->makeBoqItem($this->project, '10.000', '100.00');
        $activity = $this->activity('2026-01-15', '2026-12-20');
        $this->link($activity, $item, '10');

        $curve = $this->snapshot('2026-06-15')->sCurve();

        $this->assertSame('month', $curve['grain']);
        $dates = array_column($curve['points'], 'date');
        $this->assertSame('2026-01-14', $dates[0]);
        $this->assertContains('2026-01-31', $dates);
        $this->assertContains('2026-02-28', $dates);
        $this->assertContains('2026-06-15', $dates);
        $this->assertSame('2026-12-20', $dates[count($dates) - 1]);
    }

    public function test_a_project_running_late_keeps_its_curve_open_up_to_today(): void
    {
        $item = $this->makeBoqItem($this->project, '10.000', '100.00');
        $activity = $this->activity('2026-11-02', '2026-11-08');
        $this->link($activity, $item, '10');

        $points = $this->snapshot('2026-11-20')->sCurve()['points'];
        $last = $points[count($points) - 1];

        $this->assertSame('2026-11-20', $last['date']);
        $this->assertSame('100.00', $last['planned_pct']);
        $this->assertSame('0.00', $last['actual_pct']);
    }

    public function test_work_measured_before_the_plan_starts_extends_the_curve_back(): void
    {
        $item = $this->makeBoqItem($this->project, '10.000', '100.00');
        $activity = $this->activity('2026-11-10', '2026-11-16');
        $this->link($activity, $item, '10');
        $this->approved($item, '2', '2026-11-03');

        $points = $this->snapshot('2026-11-12')->sCurve()['points'];

        $this->assertSame('2026-11-02', $points[0]['date']);
        $this->assertSame('20.00', collect($points)->firstWhere('date', '2026-11-08')['actual_pct']);
    }

    public function test_no_linked_activities_means_an_empty_curve(): void
    {
        $this->activity('2026-11-02', '2026-11-08');

        $curve = $this->snapshot('2026-11-05')->sCurve();

        $this->assertNull($curve['grain']);
        $this->assertSame([], $curve['points']);
    }

    public function test_gantt_rows_roll_up_through_the_wbs(): void
    {
        $node = $this->schedule()->createNode($this->project, ['code' => '1', 'name' => 'Substructure']);
        $child = $this->schedule()->createNode($this->project, ['code' => '1.1', 'name' => 'Footings', 'parent_id' => $node->id]);
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $a = $this->activity('2026-11-02', '2026-11-08', ['wbs_node_id' => $child->id]);
        $b = $this->activity('2026-11-09', '2026-11-15', ['wbs_node_id' => $child->id]);
        $this->link($a, $item, '50');
        $this->link($b, $item, '50');
        $this->schedule()->addDependency($b, ['predecessor_id' => $a->id, 'type' => 'FS']);
        $this->approved($item, '25', '2026-11-05');

        $gantt = $this->snapshot('2026-11-05')->gantt();
        $rows = collect($gantt['rows'])->keyBy('key');

        $this->assertSame(['w'.$node->id, 'w'.$child->id, 'a'.$a->id, 'a'.$b->id], array_column($gantt['rows'], 'key'));
        $this->assertSame('25.00', $rows['w'.$node->id]['pct']);
        $this->assertSame([$node->id, $child->id], $rows['a'.$a->id]['ancestors']);
        $this->assertSame([['from' => 'a'.$a->id, 'type' => 'FS']], $rows['a'.$b->id]['deps']);
        $this->assertSame($rows['a'.$a->id]['start'], $rows['w'.$child->id]['start']);
        $this->assertSame($rows['a'.$b->id]['end'], $rows['w'.$child->id]['end']);
        $this->assertSame('day', $gantt['zoom']);
    }
}
