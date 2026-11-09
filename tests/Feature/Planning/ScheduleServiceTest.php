<?php

namespace Tests\Feature\Planning;

use App\Models\AuditLog;
use App\Models\PlanActivity;
use App\Models\PlanBudgetLine;
use App\Models\PlanDependency;
use App\Models\PlanWbsNode;
use App\Services\Planning\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleServiceTest extends TestCase
{
    use BuildsPlan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlan();
    }

    public function test_finish_before_start_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->activity('2026-11-10', '2026-11-05');
    }

    public function test_a_milestone_finishes_on_the_day_it_starts(): void
    {
        $milestone = $this->activity('2026-11-10', '2026-11-20', ['is_milestone' => true]);

        $this->assertSame(['2026-11-10', '2026-11-10'], $this->dates($milestone));
    }

    public function test_activity_codes_are_numbered_when_left_blank(): void
    {
        $this->assertSame('A-001', $this->activity('2026-11-01', '2026-11-02')->code);
        $this->assertSame('A-002', $this->activity('2026-11-01', '2026-11-02')->code);
        $this->assertSame('X9', $this->activity('2026-11-01', '2026-11-02', ['code' => 'X9'])->code);
    }

    public function test_repeating_a_client_uuid_returns_the_original_activity(): void
    {
        $uuid = (string) Str::uuid();

        $first = $this->activity('2026-11-01', '2026-11-02', ['client_uuid' => $uuid]);
        $second = $this->activity('2026-11-01', '2026-11-02', ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PlanActivity::count());
    }

    /** @return array<string, array{0:string, 1:int, 2:string, 3:string}> */
    public static function linkTypes(): array
    {
        // The predecessor runs 10 to 14 Nov. The successor is three days long and starts too early.
        return [
            'finish to start begins the next day' => ['FS', 0, '2026-11-15', '2026-11-17'],
            'finish to start with lag' => ['FS', 2, '2026-11-17', '2026-11-19'],
            'finish to start with lead' => ['FS', -1, '2026-11-14', '2026-11-16'],
            'start to start' => ['SS', 0, '2026-11-10', '2026-11-12'],
            'start to start with lag' => ['SS', 3, '2026-11-13', '2026-11-15'],
            'finish to finish' => ['FF', 0, '2026-11-12', '2026-11-14'],
            'start to finish' => ['SF', 0, '2026-11-07', '2026-11-09'],
        ];
    }

    #[DataProvider('linkTypes')]
    public function test_each_link_type_sets_the_right_dates(string $type, int $lag, string $start, string $end): void
    {
        $predecessor = $this->activity('2026-11-10', '2026-11-14');
        $successor = $this->activity('2026-11-01', '2026-11-03');

        [, $moved] = $this->schedule()->addDependency($successor, ['predecessor_id' => $predecessor->id, 'type' => $type, 'lag_days' => $lag]);

        $this->assertSame([$start, $end], $this->dates($successor));
        $this->assertSame(1, $moved);
    }

    public function test_adding_a_link_leaves_an_activity_that_already_fits(): void
    {
        $predecessor = $this->activity('2026-11-01', '2026-11-03');
        $successor = $this->activity('2026-11-10', '2026-11-12');

        [, $moved] = $this->schedule()->addDependency($successor, ['predecessor_id' => $predecessor->id, 'type' => 'FS']);

        $this->assertSame(0, $moved);
        $this->assertSame(['2026-11-10', '2026-11-12'], $this->dates($successor));
    }

    public function test_saving_the_same_pair_changes_its_type_and_lag(): void
    {
        $predecessor = $this->activity('2026-11-01', '2026-11-03');
        $successor = $this->activity('2026-11-10', '2026-11-12');

        $this->schedule()->addDependency($successor, ['predecessor_id' => $predecessor->id, 'type' => 'FS']);
        $this->schedule()->addDependency($successor, ['predecessor_id' => $predecessor->id, 'type' => 'SS', 'lag_days' => 4]);

        $link = PlanDependency::sole();
        $this->assertSame('SS', $link->type);
        $this->assertSame(4, $link->lag_days);
    }

    public function test_work_either_side_of_a_milestone_can_share_its_date(): void
    {
        $work = $this->activity('2026-11-10', '2026-11-14');
        $milestone = $this->activity('2026-11-01', '2026-11-01', ['is_milestone' => true]);
        $next = $this->activity('2026-11-01', '2026-11-03');

        $this->schedule()->addDependency($milestone, ['predecessor_id' => $work->id, 'type' => 'FS']);
        $this->schedule()->addDependency($next, ['predecessor_id' => $milestone->id, 'type' => 'FS']);

        $this->assertSame(['2026-11-14', '2026-11-14'], $this->dates($milestone));
        $this->assertSame(['2026-11-14', '2026-11-16'], $this->dates($next));
    }

    public function test_delaying_an_activity_pushes_the_whole_chain_and_keeps_durations(): void
    {
        $first = $this->activity('2026-11-01', '2026-11-05');
        $second = $this->activity('2026-11-06', '2026-11-08');
        $third = $this->activity('2026-11-09', '2026-11-20');
        $this->schedule()->addDependency($second, ['predecessor_id' => $first->id, 'type' => 'FS']);
        $this->schedule()->addDependency($third, ['predecessor_id' => $second->id, 'type' => 'FS']);

        $moved = $this->schedule()->updateActivity($first, ['name' => 'Work package', 'planned_start' => '2026-11-01', 'planned_end' => '2026-11-10']);

        $this->assertSame(2, $moved);
        $this->assertSame(['2026-11-11', '2026-11-13'], $this->dates($second));
        $this->assertSame(['2026-11-14', '2026-11-25'], $this->dates($third));
    }

    public function test_a_link_only_pushes_as_far_as_its_strictest_predecessor_needs(): void
    {
        $early = $this->activity('2026-11-01', '2026-11-03');
        $late = $this->activity('2026-11-01', '2026-11-12');
        $successor = $this->activity('2026-11-05', '2026-11-06');

        $this->schedule()->addDependency($successor, ['predecessor_id' => $early->id, 'type' => 'FS']);
        $this->schedule()->addDependency($successor, ['predecessor_id' => $late->id, 'type' => 'FS']);

        $this->assertSame(['2026-11-13', '2026-11-14'], $this->dates($successor));
    }

    public function test_bringing_an_activity_forward_never_pulls_later_work_back(): void
    {
        $first = $this->activity('2026-11-05', '2026-11-08');
        $second = $this->activity('2026-11-09', '2026-11-12');
        $this->schedule()->addDependency($second, ['predecessor_id' => $first->id, 'type' => 'FS']);

        $moved = $this->schedule()->updateActivity($first, ['name' => 'Work package', 'planned_start' => '2026-11-01', 'planned_end' => '2026-11-03']);

        $this->assertSame(0, $moved);
        $this->assertSame(['2026-11-09', '2026-11-12'], $this->dates($second));
    }

    public function test_an_activity_cannot_be_moved_ahead_of_what_it_depends_on(): void
    {
        $first = $this->activity('2026-11-05', '2026-11-08');
        $second = $this->activity('2026-11-09', '2026-11-12');
        $this->schedule()->addDependency($second, ['predecessor_id' => $first->id, 'type' => 'FS']);

        try {
            $this->schedule()->updateActivity($second, ['name' => 'Work package', 'planned_start' => '2026-11-08', 'planned_end' => '2026-11-12']);
            $this->fail('A start ahead of the predecessor should be refused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('planned_start', $exception->errors());
        }

        $this->assertSame(['2026-11-09', '2026-11-12'], $this->dates($second));
    }

    public function test_an_activity_cannot_depend_on_itself(): void
    {
        $activity = $this->activity('2026-11-01', '2026-11-03');

        $this->expectException(ValidationException::class);

        $this->schedule()->addDependency($activity, ['predecessor_id' => $activity->id, 'type' => 'FS']);
    }

    public function test_a_direct_loop_is_rejected_and_nothing_is_saved(): void
    {
        $a = $this->activity('2026-11-01', '2026-11-03', ['code' => 'A']);
        $b = $this->activity('2026-11-04', '2026-11-06', ['code' => 'B']);
        $this->schedule()->addDependency($b, ['predecessor_id' => $a->id, 'type' => 'FS']);

        try {
            $this->schedule()->addDependency($a, ['predecessor_id' => $b->id, 'type' => 'FS']);
            $this->fail('A loop should be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('B → A → B', $exception->errors()['predecessor_id'][0]);
        }

        $this->assertSame(1, PlanDependency::count());
        $this->assertSame(['2026-11-01', '2026-11-03'], $this->dates($a));
    }

    public function test_a_long_loop_is_rejected(): void
    {
        $a = $this->activity('2026-11-01', '2026-11-03', ['code' => 'A']);
        $b = $this->activity('2026-11-04', '2026-11-06', ['code' => 'B']);
        $c = $this->activity('2026-11-07', '2026-11-09', ['code' => 'C']);
        $d = $this->activity('2026-11-10', '2026-11-12', ['code' => 'D']);
        $this->schedule()->addDependency($b, ['predecessor_id' => $a->id, 'type' => 'FS']);
        $this->schedule()->addDependency($c, ['predecessor_id' => $b->id, 'type' => 'FS']);
        $this->schedule()->addDependency($d, ['predecessor_id' => $c->id, 'type' => 'SS']);

        try {
            $this->schedule()->addDependency($a, ['predecessor_id' => $d->id, 'type' => 'FS']);
            $this->fail('A loop should be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('D → A → B → C → D', $exception->errors()['predecessor_id'][0]);
        }

        $this->assertSame(3, PlanDependency::count());
    }

    public function test_a_diamond_is_not_a_loop(): void
    {
        $top = $this->activity('2026-11-01', '2026-11-02');
        $left = $this->activity('2026-11-03', '2026-11-04');
        $right = $this->activity('2026-11-03', '2026-11-06');
        $bottom = $this->activity('2026-11-07', '2026-11-08');

        foreach ([[$left, $top], [$right, $top], [$bottom, $left], [$bottom, $right]] as [$successor, $predecessor]) {
            $this->schedule()->addDependency($successor, ['predecessor_id' => $predecessor->id, 'type' => 'FS']);
        }

        $this->assertSame(4, PlanDependency::count());
    }

    public function test_activities_from_another_project_cannot_be_linked(): void
    {
        $other = $this->makeProject($this->organization);
        $elsewhere = $this->activity('2026-11-01', '2026-11-03', [], $other);
        $here = $this->activity('2026-11-05', '2026-11-08');

        $this->expectException(ValidationException::class);

        $this->schedule()->addDependency($here, ['predecessor_id' => $elsewhere->id, 'type' => 'FS']);
    }

    public function test_a_boq_item_cannot_be_linked_beyond_its_quantity(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000');
        $a = $this->activity('2026-11-01', '2026-11-03');
        $b = $this->activity('2026-11-04', '2026-11-06');
        $this->link($a, $item, '70');

        try {
            $this->link($b, $item, '31');
            $this->fail('Linking more than the BOQ holds should be refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('30.000', $exception->errors()['qty'][0]);
        }

        $this->assertSame('30.000', $this->link($b, $item, '30')->qty);
    }

    public function test_a_blank_quantity_takes_whatever_is_left_and_a_full_item_is_refused(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000');
        $a = $this->activity('2026-11-01', '2026-11-03');
        $b = $this->activity('2026-11-04', '2026-11-06');
        $c = $this->activity('2026-11-07', '2026-11-09');
        $this->link($a, $item, '40');

        $link = $this->schedule()->linkBoqItem($b, ['boq_item_id' => $item->id, 'qty' => null]);
        $this->assertSame('60.000', $link->qty);

        $this->expectException(ValidationException::class);

        $this->schedule()->linkBoqItem($c, ['boq_item_id' => $item->id, 'qty' => null]);
    }

    public function test_relinking_the_same_item_changes_its_quantity_and_ignores_its_old_share(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000');
        $activity = $this->activity('2026-11-01', '2026-11-03');

        $this->link($activity, $item, '90');
        $link = $this->link($activity, $item, '100');

        $this->assertSame('100.000', $link->qty);
        $this->assertSame(1, $activity->boqLinks()->count());
    }

    public function test_a_wbs_node_with_work_under_it_cannot_be_removed(): void
    {
        $node = $this->schedule()->createNode($this->project, ['code' => '1', 'name' => 'Substructure']);
        $this->activity('2026-11-01', '2026-11-03', ['wbs_node_id' => $node->id]);

        $this->expectException(ValidationException::class);

        $this->schedule()->deleteNode($node);
    }

    public function test_a_wbs_node_cannot_move_inside_itself(): void
    {
        $top = $this->schedule()->createNode($this->project, ['code' => '1', 'name' => 'Top']);
        $child = $this->schedule()->createNode($this->project, ['code' => '1.1', 'name' => 'Child', 'parent_id' => $top->id]);

        $this->expectException(ValidationException::class);

        $this->schedule()->updateNode($top, ['code' => '1', 'name' => 'Top', 'parent_id' => $child->id]);
    }

    public function test_repeating_a_client_uuid_returns_the_original_wbs_node(): void
    {
        $uuid = (string) Str::uuid();

        $first = $this->schedule()->createNode($this->project, ['code' => '1', 'name' => 'Top', 'client_uuid' => $uuid]);
        $second = $this->schedule()->createNode($this->project, ['code' => '1', 'name' => 'Top', 'client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PlanWbsNode::count());
    }

    public function test_a_baseline_freezes_dates_and_budget_and_is_audited(): void
    {
        $activity = $this->activity('2026-11-01', '2026-11-05');
        app(BudgetService::class)->saveLine($this->project, ['head' => 'material', 'amount' => '500000']);

        $baseline = $this->schedule()->takeBaseline($this->project, $this->owner);

        $this->schedule()->updateActivity($activity, ['name' => 'Work package', 'planned_start' => '2026-11-03', 'planned_end' => '2026-11-09']);
        app(BudgetService::class)->saveLine($this->project, ['head' => 'material', 'amount' => '600000']);

        $activity->refresh();
        $this->assertSame('2026-11-01', $activity->baseline_start->toDateString());
        $this->assertSame('2026-11-05', $activity->baseline_end->toDateString());
        $this->assertSame('2026-11-03', $activity->planned_start->toDateString());

        $line = PlanBudgetLine::sole();
        $this->assertSame('500000.00', $line->baseline_amount);
        $this->assertSame('600000.00', $line->amount);

        $this->assertSame(1, $baseline->activity_count);
        $this->assertSame('500000.00', $baseline->budget_total);
        $this->assertTrue(AuditLog::where('action', 'planning.baseline')->exists());
    }
}
