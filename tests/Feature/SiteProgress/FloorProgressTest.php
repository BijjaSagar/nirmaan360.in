<?php

namespace Tests\Feature\SiteProgress;

use App\Models\FloorWorkPlan;
use App\Models\MepStageCell;
use App\Models\ProjectFloor;
use App\Services\SiteControl\WorkProgress;
use App\Services\SiteProgress\FloorService;
use App\Services\SiteProgress\MepTracker;
use App\Services\SiteProgress\ProgressEntryService;
use App\Services\SiteProgress\ProgressReport;
use App\Support\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FloorProgressTest extends SiteProgressTestCase
{
    private function floors(): FloorService
    {
        return app(FloorService::class);
    }

    private function report(): ProgressReport
    {
        return app(ProgressReport::class);
    }

    /** @return array<string, mixed> The row of one floor from the board. */
    private function row(int $floorId): array
    {
        foreach ($this->report()->floors($this->project) as $floor) {
            foreach ([$floor, ...$floor['children']] as $row) {
                if ($row['id'] === $floorId) {
                    return $row;
                }
            }
        }

        $this->fail("Floor $floorId is not on the board");
    }

    public function test_floor_percent_is_measured_against_the_quantity_planned_for_it(): void
    {
        $brick = $this->item('100.000', null, '100.00');
        $plaster = $this->item('100.000', null, '300.00');
        $first = $this->floor('1st floor');
        $second = $this->floor('2nd floor');

        $this->floors()->setTarget($first, $brick, '50');
        $this->floors()->setTarget($second, $brick, '50');
        $this->floors()->setTarget($first, $plaster, '100');

        $this->work($brick, '50', $first);

        // 50 of 50 bricks on the 1st floor is worth 5,000 of the 35,000 planned there, in the weights of WorkProgress.
        $this->assertSame('14.29', $this->row($first->id)['percent']);
        $this->assertSame('0.00', $this->row($second->id)['percent']);
        $this->assertSame('12.50', app(WorkProgress::class)->projectPercent($this->project), 'The project figure is unchanged and still comes from WorkProgress');
    }

    public function test_a_line_counts_no_more_than_its_target(): void
    {
        $item = $this->item('100.000', null, '100.00');
        $other = $this->item('100.000', null, '100.00');
        $floor = $this->floor();
        $this->floors()->setTarget($floor, $item, '10');
        $this->floors()->setTarget($floor, $other, '10');

        $this->work($item, '40', $floor);

        $this->assertSame('50.00', $this->row($floor->id)['percent'], 'The first line is full and the second is empty');
        $detail = $this->report()->floorDetail($floor);
        $line = collect($detail['lines'])->firstWhere('id', $item->id);
        $this->assertSame('400.00', $line['percent']);
        $this->assertSame('0.000', $line['balance_qty']);
    }

    public function test_work_on_a_floor_with_no_target_is_listed_but_not_counted(): void
    {
        $item = $this->item();
        $floor = $this->floor();
        $this->work($item, '10', $floor);

        $detail = $this->report()->floorDetail($floor);

        $this->assertSame(0, $detail['targets']);
        $this->assertSame('0.00', $detail['percent']);
        $this->assertCount(1, $detail['unplanned']);
        $this->assertNull($detail['unplanned'][0]['percent']);
        $this->assertSame('10.000', $detail['unplanned'][0]['done_qty']);
    }

    public function test_a_floor_includes_its_flats_and_a_flat_stands_alone(): void
    {
        $item = $this->item('100.000', null, '100.00');
        $floor = $this->floor('2nd floor');
        $flatA = $this->floor('Flat 201', $floor->id);
        $flatB = $this->floor('Flat 202', $floor->id);
        $this->floors()->setTarget($flatA, $item, '30');
        $this->floors()->setTarget($flatB, $item, '30');

        $this->work($item, '30', $flatA);

        $this->assertSame('100.00', $this->row($flatA->id)['percent']);
        $this->assertSame('0.00', $this->row($flatB->id)['percent']);
        $this->assertSame('50.00', $this->row($floor->id)['percent'], 'Half of what is planned across both flats');

        $board = $this->report()->floors($this->project);
        $this->assertCount(1, $board);
        $this->assertCount(2, $board[0]['children']);
    }

    public function test_a_correction_moves_the_floor_percent_too(): void
    {
        $item = $this->item('100.000', null, '100.00');
        $floor = $this->floor();
        $this->floors()->setTarget($floor, $item, '100');
        $entry = $this->work($item, '80', $floor);

        app(ProgressEntryService::class)->correct($entry, '60', 'Miscounted', $this->owner);

        $this->assertSame('60.00', $this->row($floor->id)['percent']);
    }

    public function test_sharing_a_line_equally_adds_up_to_the_boq_quantity(): void
    {
        $item = $this->item('100.000');
        $one = $this->floor('One');
        $two = $this->floor('Two');
        $three = $this->floor('Three');
        $this->floors()->setTarget($one, $item, '999');

        $count = $this->floors()->splitEqually($this->project, [$item->id], [$one->id, $two->id, $three->id]);

        $this->assertSame(1, $count);
        $this->assertSame(['33.333', '33.333', '33.334'], FloorWorkPlan::orderBy('project_floor_id')->pluck('planned_qty')->all());
        $this->assertSame('100.000', Dec::qty(FloorWorkPlan::sum('planned_qty')));

        // Sharing again over fewer floors drops the old targets.
        $this->floors()->splitEqually($this->project, [$item->id], [$one->id, $two->id]);
        $this->assertSame(['50.000', '50.000'], FloorWorkPlan::orderBy('project_floor_id')->pluck('planned_qty')->all());
    }

    public function test_sharing_needs_a_floor_and_an_item(): void
    {
        $this->expectException(ValidationException::class);
        $this->floors()->splitEqually($this->project, [], []);
    }

    public function test_a_target_of_zero_removes_it(): void
    {
        $item = $this->item();
        $floor = $this->floor();
        $this->floors()->setTarget($floor, $item, '40');
        $this->floors()->setTarget($floor, $item, '45.5');
        $this->assertSame('45.500', FloorWorkPlan::first()->planned_qty);

        $this->assertNull($this->floors()->setTarget($floor, $item, '0'));
        $this->assertSame(0, FloorWorkPlan::count());
    }

    public function test_a_target_cannot_name_a_line_of_another_project(): void
    {
        $other = $this->makeProject($this->organization, ['name' => 'Second site']);
        $foreign = $this->item('10.000', $other);
        $floor = $this->floor();

        $this->expectException(NotFoundHttpException::class);
        $this->floors()->setTarget($floor, $foreign, '5');
    }

    public function test_floor_names_are_unique_under_the_same_parent_only(): void
    {
        $first = $this->floor('1st floor');
        $second = $this->floor('2nd floor');
        $this->floor('Passage', $first->id);
        $this->floor('Passage', $second->id);

        $this->assertSame(2, ProjectFloor::where('name', 'Passage')->count());

        try {
            $this->floor('passage', $first->id);
            $this->fail('A duplicate name under the same floor');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $this->floor('1st FLOOR');
    }

    public function test_a_floor_cannot_become_its_own_flat_or_adopt_a_floor_with_flats(): void
    {
        $floor = $this->floor('1st floor');
        $other = $this->floor('2nd floor');
        $this->floor('Flat 101', $floor->id);

        try {
            $this->floors()->update($floor, ['name' => '1st floor', 'parent_id' => $other->id]);
            $this->fail('A floor with flats cannot sit under another floor.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }

        $this->expectException(ValidationException::class);
        $this->floors()->update($other, ['name' => '2nd floor', 'parent_id' => $other->id]);
    }

    public function test_a_floor_with_history_cannot_be_removed(): void
    {
        $item = $this->item();
        $used = $this->floor('Used');
        $staged = $this->floor('Staged');
        $empty = $this->floor('Empty');
        $parent = $this->floor('Parent');
        $this->floor('Child', $parent->id);
        $this->work($item, '5', $used);
        app(MepTracker::class)->update($this->project, ['project_floor_id' => $staged->id, 'trade' => 'plumbing', 'stage' => 'core_cutting', 'status' => 'done'], $this->owner);
        $this->floors()->setTarget($empty, $item, '10');

        foreach ([$used, $staged, $parent] as $floor) {
            try {
                $this->floors()->delete($floor);
                $this->fail("{$floor->name} has history");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('floor', $e->errors());
            }
        }

        $this->floors()->delete($empty);
        $this->assertNull(ProjectFloor::find($empty->id));
        $this->assertSame(0, FloorWorkPlan::where('project_floor_id', $empty->id)->count());
        $this->assertSame(1, MepStageCell::count());
    }

    public function test_pending_counts_roll_up_to_the_floor(): void
    {
        $item = $this->item();
        $floor = $this->floor('2nd floor');
        $flat = $this->floor('Flat 201', $floor->id);
        $this->work($item, '0', $flat, ['is_pending' => true, 'remark' => 'Passage wiring']);

        $this->assertSame(1, $this->row($flat->id)['pending']);
        $this->assertSame(1, $this->row($floor->id)['pending']);
        $this->assertSame(1, $this->report()->floorDetail($floor)['pending_entries']->count());
    }

    public function test_time_against_work_ninety_of_one_eighty_days_and_thirty_five_percent_is_behind(): void
    {
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->project->update(['start_date' => '2026-09-02', 'planned_days' => 180, 'deadline_date' => '2027-02-28']);
        $item = $this->item('100.000');
        $this->work($item, '35');

        $summary = $this->report()->summary($this->project->fresh());

        $this->assertSame('35.00', $summary['work_percent']);
        $this->assertSame('50.00', $summary['time_percent']);
        $this->assertSame('15.00', $summary['lag']);
        $this->assertSame('behind', $summary['status']);
        $this->assertSame('high', $summary['severity']);
        $this->assertSame(90, $summary['elapsed_days']);
        $this->assertSame(180, $summary['planned_days']);
        $this->assertSame(89, $summary['days_to_deadline']);
        $this->assertSame('65.00', $summary['work_left_percent']);
    }

    public function test_work_ahead_of_time_is_on_track(): void
    {
        Carbon::setTestNow('2026-12-01 10:00:00');
        $this->project->update(['start_date' => '2026-09-02', 'planned_days' => 180]);
        $this->work($this->item('100.000'), '60');

        $summary = $this->report()->summary($this->project->fresh());

        $this->assertSame('on_track', $summary['status']);
        $this->assertSame('none', $summary['severity']);
        $this->assertSame('-10.00', $summary['lag']);
    }

    public function test_without_a_start_date_or_planned_days_nothing_is_compared(): void
    {
        $summary = $this->report()->summary($this->project);

        $this->assertNull($summary['time_percent']);
        $this->assertNull($summary['status']);
        $this->assertNull($summary['lag']);
    }

    public function test_the_weekly_view_nets_corrections_and_lists_pending_and_what_is_under_way(): void
    {
        Carbon::setTestNow('2026-11-11 09:00:00'); // a Wednesday
        $floor = $this->floor();
        $running = $this->item('100.000');
        $untouched = $this->item('50.000');
        $finished = $this->item('10.000');

        $mon = $this->work($running, '30', $floor, ['work_date' => '2026-11-09']);
        $this->work($running, '10', $floor, ['work_date' => '2026-11-10']);
        $this->work($running, '20', $floor, ['work_date' => '2026-11-03']); // the week before
        $this->work($finished, '10', null, ['work_date' => '2026-11-11']);
        app(ProgressEntryService::class)->correct($mon, '20', 'Typo', $this->owner);
        $this->work($running, '0', $floor, ['is_pending' => true, 'remark' => 'Curing not done']);

        $week = $this->report()->week($this->project, Carbon::parse('2026-11-12'));

        $this->assertSame('2026-11-09', $week['start']->toDateString());
        $this->assertSame('2026-11-15', $week['end']->toDateString());
        $done = $week['done']->keyBy('id');
        $this->assertSame('30.000', $done[$running->id]['week_qty'], '20 after the correction plus 10');
        $this->assertSame('10.000', $done[$finished->id]['week_qty']);
        $this->assertCount(1, $week['pending']);
        $this->assertSame([$running->id], $week['next']->pluck('id')->all(), 'Only the item under way: not the finished one and not the untouched one');
        $this->assertSame(1, $week['not_started']);
        $this->assertNotContains($untouched->id, $week['next']->pluck('id')->all());
        $this->assertTrue($week['is_current']);
    }
}
