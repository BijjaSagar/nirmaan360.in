<?php

namespace Tests\Feature\Planning;

use App\Models\PlanActivity;
use App\Models\PlanBoqLink;
use App\Models\PlanDependency;
use App\Models\PlanWbsNode;
use App\Services\Planning\MsProjectImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MsProjectImporterTest extends TestCase
{
    use BuildsPlan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlan();
    }

    private function importer(): MsProjectImporter
    {
        return app(MsProjectImporter::class);
    }

    /** @param list<string> $tasks */
    private function xml(array $tasks, string $head = '<MinutesPerDay>480</MinutesPerDay>'): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Project xmlns="http://schemas.microsoft.com/project">'.$head
            .'<Tasks><Task><UID>0</UID><ID>0</ID><Name>Whole project</Name><OutlineLevel>0</OutlineLevel><Summary>1</Summary></Task>'
            .implode('', $tasks).'</Tasks></Project>';
    }

    private function task(int $uid, string $name, int $level, string $outline, array $extra = [], string $links = ''): string
    {
        $fields = [
            'UID' => $uid,
            'Name' => $name,
            'WBS' => $outline,
            'OutlineNumber' => $outline,
            'OutlineLevel' => $level,
            'Start' => '2026-11-02T08:00:00',
            'Finish' => '2026-11-06T17:00:00',
            'Summary' => 0,
            'Milestone' => 0,
            ...$extra,
        ];

        $body = '';

        foreach ($fields as $key => $value) {
            $body .= "<{$key}>".htmlspecialchars((string) $value, ENT_XML1)."</{$key}>";
        }

        return "<Task>{$body}{$links}</Task>";
    }

    private function predecessor(int $uid, int $type = 1, int $lag = 0, int $format = 7): string
    {
        return "<PredecessorLink><PredecessorUID>{$uid}</PredecessorUID><Type>{$type}</Type><LinkLag>{$lag}</LinkLag><LagFormat>{$format}</LagFormat></PredecessorLink>";
    }

    private function sample(): string
    {
        return $this->xml([
            $this->task(1, 'Substructure', 1, '1', ['Summary' => 1]),
            $this->task(2, 'Footings', 2, '1.1', ['Summary' => 1]),
            $this->task(3, 'Excavation', 3, '1.1.1', ['Start' => '2026-11-02T08:00:00', 'Finish' => '2026-11-06T17:00:00']),
            $this->task(4, 'PCC & rebar', 3, '1.1.2', ['Start' => '2026-11-07T08:00:00', 'Finish' => '2026-11-12T17:00:00'], $this->predecessor(3, 1, 4800)),
            $this->task(5, 'Backfill', 2, '1.2', ['Start' => '2026-11-13T08:00:00', 'Finish' => '2026-11-16T17:00:00'], $this->predecessor(4, 3, 0)),
            $this->task(6, 'Plinth complete', 1, '2', ['Milestone' => 1, 'Start' => '2026-11-16T17:00:00', 'Finish' => '2026-11-16T17:00:00'], $this->predecessor(5, 0, 14400, 8)),
        ]);
    }

    public function test_summary_tasks_become_wbs_and_others_become_activities(): void
    {
        $result = $this->importer()->import($this->project, $this->sample());

        $this->assertSame(['activities' => 4, 'wbs' => 2, 'links' => 3, 'skipped_links' => 0, 'percent_lags' => 0], $result);

        $top = PlanWbsNode::where('name', 'Substructure')->sole();
        $footings = PlanWbsNode::where('name', 'Footings')->sole();
        $this->assertNull($top->parent_id);
        $this->assertSame($top->id, $footings->parent_id);

        $this->assertSame('1.1.1', PlanActivity::where('name', 'Excavation')->sole()->code);
        $this->assertSame($footings->id, PlanActivity::where('name', 'Excavation')->sole()->wbs_node_id);
        $this->assertSame($top->id, PlanActivity::where('name', 'Backfill')->sole()->wbs_node_id);
        $this->assertNull(PlanActivity::where('name', 'Plinth complete')->sole()->wbs_node_id);
    }

    public function test_dates_and_milestones_come_through(): void
    {
        $this->importer()->import($this->project, $this->sample());

        $excavation = PlanActivity::where('name', 'Excavation')->sole();
        $this->assertSame('2026-11-02', $excavation->planned_start->toDateString());
        $this->assertSame('2026-11-06', $excavation->planned_end->toDateString());
        $this->assertFalse($excavation->is_milestone);

        $milestone = PlanActivity::where('name', 'Plinth complete')->sole();
        $this->assertTrue($milestone->is_milestone);
        $this->assertSame('2026-11-16', $milestone->planned_end->toDateString());
    }

    public function test_link_types_and_lags_come_through(): void
    {
        $this->importer()->import($this->project, $this->sample());

        $link = fn (string $name) => PlanDependency::where('successor_id', PlanActivity::where('name', $name)->sole()->id)->sole();

        // 4800 tenths of a minute over an eight-hour day is one working day.
        $this->assertSame('FS', $link('PCC & rebar')->type);
        $this->assertSame(1, $link('PCC & rebar')->lag_days);

        $this->assertSame('SS', $link('Backfill')->type);
        $this->assertSame(0, $link('Backfill')->lag_days);

        // 14400 tenths of a minute in elapsed days is one calendar day.
        $this->assertSame('FF', $link('Plinth complete')->type);
        $this->assertSame(1, $link('Plinth complete')->lag_days);
    }

    public function test_the_dates_in_the_file_are_not_rescheduled(): void
    {
        $xml = $this->xml([
            $this->task(1, 'First', 1, '1', ['Finish' => '2026-11-06T17:00:00']),
            $this->task(2, 'Second', 1, '2', ['Start' => '2026-11-06T13:00:00', 'Finish' => '2026-11-09T17:00:00'], $this->predecessor(1)),
        ]);

        $this->importer()->import($this->project, $xml);

        $this->assertSame('2026-11-06', PlanActivity::where('name', 'Second')->sole()->planned_start->toDateString());
    }

    public function test_importing_again_updates_tasks_and_keeps_their_links_to_the_boq(): void
    {
        $this->importer()->import($this->project, $this->sample());

        $excavation = PlanActivity::where('name', 'Excavation')->sole();
        $this->link($excavation, $this->makeBoqItem($this->project, '100.000'), '100');

        $changed = str_replace('<Name>Excavation</Name>', '<Name>Bulk excavation</Name>', $this->sample());
        $this->importer()->import($this->project, $changed);

        $this->assertSame(4, PlanActivity::count());
        $this->assertSame(2, PlanWbsNode::count());
        $this->assertSame(3, PlanDependency::count());
        $this->assertSame('Bulk excavation', $excavation->refresh()->name);
        $this->assertSame(1, PlanBoqLink::count());
    }

    public function test_a_code_already_used_gets_a_suffix(): void
    {
        $this->activity('2026-11-01', '2026-11-02', ['code' => '1.1.1']);

        $this->importer()->import($this->project, $this->sample());

        $this->assertSame('1.1.1-2', PlanActivity::where('name', 'Excavation')->sole()->code);
    }

    public function test_links_to_summary_tasks_are_counted_as_skipped(): void
    {
        $xml = $this->xml([
            $this->task(1, 'Group', 1, '1', ['Summary' => 1]),
            $this->task(2, 'Inside', 2, '1.1'),
            $this->task(3, 'After the group', 1, '2', [], $this->predecessor(1)),
        ]);

        $result = $this->importer()->import($this->project, $xml);

        $this->assertSame(0, $result['links']);
        $this->assertSame(1, $result['skipped_links']);
    }

    public function test_percentage_lag_is_reported_rather_than_guessed(): void
    {
        $xml = $this->xml([
            $this->task(1, 'First', 1, '1'),
            $this->task(2, 'Second', 1, '2', [], $this->predecessor(1, 1, 500, 19)),
        ]);

        $result = $this->importer()->import($this->project, $xml);

        $this->assertSame(1, $result['percent_lags']);
        $this->assertSame(0, PlanDependency::sole()->lag_days);
    }

    public function test_blank_and_inactive_tasks_are_ignored(): void
    {
        $xml = $this->xml([
            $this->task(1, 'Real', 1, '1'),
            '<Task><UID>2</UID><IsNull>1</IsNull></Task>',
            $this->task(3, 'Switched off', 1, '2', ['Active' => 0]),
        ]);

        $this->assertSame(1, $this->importer()->import($this->project, $xml)['activities']);
    }

    public function test_a_finish_at_midnight_belongs_to_the_day_before(): void
    {
        $xml = $this->xml([$this->task(1, 'Night shift', 1, '1', ['Start' => '2026-11-02T00:00:00', 'Finish' => '2026-11-05T00:00:00'])]);

        $this->importer()->import($this->project, $xml);

        $this->assertSame('2026-11-04', PlanActivity::sole()->planned_end->toDateString());
    }

    public function test_a_loop_in_the_file_rolls_everything_back(): void
    {
        $xml = $this->xml([
            $this->task(1, 'A', 1, '1', [], $this->predecessor(2)),
            $this->task(2, 'B', 1, '2', [], $this->predecessor(1)),
        ]);

        $this->assertImportFails($xml, 'loop');
        $this->assertSame(0, PlanActivity::count());
    }

    public function test_a_file_that_loops_with_links_added_by_hand_rolls_back(): void
    {
        $this->importer()->import($this->project, $this->xml([
            $this->task(1, 'A', 1, '1'),
            $this->task(2, 'B', 1, '2'),
        ]));

        $a = PlanActivity::where('name', 'A')->sole();
        $b = PlanActivity::where('name', 'B')->sole();
        $manual = $this->activity('2026-11-20', '2026-11-22', ['code' => 'M']);
        $this->schedule()->addDependency($manual, ['predecessor_id' => $b->id, 'type' => 'FS']);
        $this->schedule()->addDependency($a, ['predecessor_id' => $manual->id, 'type' => 'FS']);

        $this->assertImportFails($this->xml([
            $this->task(1, 'A', 1, '1'),
            $this->task(2, 'B', 1, '2', [], $this->predecessor(1)),
        ]), 'loop');

        $this->assertSame(2, PlanDependency::count());
    }

    public function test_one_bad_task_stops_the_whole_file(): void
    {
        $xml = $this->xml([
            $this->task(1, 'Good', 1, '1'),
            $this->task(2, 'Backwards', 1, '2', ['Start' => '2026-11-10T08:00:00', 'Finish' => '2026-11-02T17:00:00']),
        ]);

        $this->assertImportFails($xml, 'Backwards');
        $this->assertSame(0, PlanActivity::count());
    }

    public function test_a_repeated_uid_is_refused(): void
    {
        $this->assertImportFails($this->xml([
            $this->task(7, 'First', 1, '1'),
            $this->task(7, 'Second', 1, '2'),
        ]), 'UID 7');

        $this->assertSame(0, PlanActivity::count());
    }

    public function test_a_task_with_no_dates_is_refused(): void
    {
        $this->assertImportFails($this->xml([$this->task(1, 'Undated', 1, '1', ['Start' => ''])]), 'Undated');
    }

    public function test_an_impossible_date_is_refused(): void
    {
        $this->assertImportFails($this->xml([$this->task(1, 'Odd', 1, '1', ['Start' => '2026-02-31T08:00:00'])]), 'Odd');
    }

    public function test_something_that_is_not_xml_is_refused(): void
    {
        $this->assertImportFails('Task,Start,Finish', 'not valid XML');
    }

    public function test_other_xml_is_refused(): void
    {
        $this->assertImportFails('<?xml version="1.0"?><Workbook></Workbook>', 'not a Microsoft Project');
    }

    public function test_a_project_with_no_tasks_is_refused(): void
    {
        $this->assertImportFails($this->xml([]), 'No tasks');
    }

    public function test_a_document_type_declaration_is_refused(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE Project [<!ENTITY secret SYSTEM "file:///etc/passwd">]>'
            .'<Project><Tasks><Task><UID>1</UID><Name>&secret;</Name><OutlineLevel>1</OutlineLevel><Start>2026-11-02T08:00:00</Start><Finish>2026-11-03T08:00:00</Finish></Task></Tasks></Project>';

        $this->assertImportFails($xml, 'document type');
        $this->assertSame(0, PlanActivity::count());
    }

    private function assertImportFails(string $xml, string $message): void
    {
        try {
            $this->importer()->import($this->project, $xml);
            $this->fail('The import should have been refused.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, $exception->errors()['file'][0]);
        }
    }
}
