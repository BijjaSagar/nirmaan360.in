<?php

namespace Tests\Feature\SiteProgress;

use App\Exceptions\ImmutableRecordException;
use App\Models\MepStageCell;
use App\Models\MepStageEvent;
use App\Models\ProgressPhoto;
use App\Models\ProjectFloor;
use App\Services\SiteProgress\MepTracker;
use App\Services\SiteProgress\ProgressPhotoService;
use App\Services\SiteProgress\ProgressReport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MepTrackerTest extends SiteProgressTestCase
{
    private function tracker(): MepTracker
    {
        return app(MepTracker::class);
    }

    private function set(ProjectFloor $floor, string $trade, string $stage, string $status, array $extra = []): array
    {
        $this->actingAs($this->owner);

        return $this->tracker()->update($this->project, [
            'project_floor_id' => $floor->id, 'trade' => $trade, 'stage' => $stage, 'status' => $status, ...$extra,
        ], $this->owner);
    }

    public function test_the_stage_lists_are_the_ones_in_the_requirements_in_order(): void
    {
        $trades = $this->tracker()->trades();

        $this->assertSame([
            'Core cutting and internal pipes',
            'IPS / bathroom bed and complete waterproofing underneath',
            'Internal water fitting / piping',
            'Outer piping and CP fitting',
            'Sanitary ware fitting',
            'Drainage line (final stage)',
        ], array_column($trades['plumbing']['stages'], 'label'));

        $this->assertSame([
            'Slab piping',
            'Concealed piping in walls',
            'Wiring',
            'Button boards / switch boards',
            'Final fittings: fans, lights etc.',
        ], array_column($trades['electrical']['stages'], 'label'));

        $this->assertSame(['not_started', 'in_progress', 'done', 'pending', 'na'], array_keys($this->tracker()->statuses()));
        $this->assertSame(['final_fittings'], array_keys(array_filter($trades['electrical']['stages'], fn ($stage) => $stage['optional'] ?? false)));
    }

    public function test_a_status_change_creates_the_cell_and_keeps_the_history(): void
    {
        $floor = $this->floor();

        $first = $this->set($floor, 'plumbing', 'core_cutting', 'in_progress');
        $second = $this->set($floor, 'plumbing', 'core_cutting', 'pending', ['remark' => 'Bathroom 2 not cut']);
        $third = $this->set($floor, 'plumbing', 'core_cutting', 'done');

        $this->assertSame(1, MepStageCell::count());
        $this->assertSame($first['cell']->id, $third['cell']->id);
        $cell = $third['cell']->fresh();
        $this->assertSame('done', $cell->status);
        $this->assertNull($cell->remark, 'A note belongs to the status it was written for');
        $this->assertSame($this->owner->id, $cell->updated_by);
        $this->assertSame(['not_started', 'in_progress', 'pending'], MepStageEvent::orderBy('id')->pluck('from_status')->all());
        $this->assertSame(['in_progress', 'pending', 'done'], MepStageEvent::orderBy('id')->pluck('status')->all());
        $this->assertSame('Bathroom 2 not cut', $second['event']->remark);
    }

    public function test_pending_needs_a_remark(): void
    {
        $floor = $this->floor();

        $this->expectException(ValidationException::class);
        $this->set($floor, 'electrical', 'wiring', 'pending');
    }

    public function test_not_applicable_is_only_for_the_optional_stage(): void
    {
        $floor = $this->floor();

        $result = $this->set($floor, 'electrical', 'final_fittings', 'na');
        $this->assertSame('na', $result['cell']->status);

        $this->expectException(ValidationException::class);
        $this->set($floor, 'electrical', 'wiring', 'na');
    }

    public function test_a_stage_must_belong_to_its_trade(): void
    {
        $floor = $this->floor();

        $this->expectException(ValidationException::class);
        $this->set($floor, 'plumbing', 'wiring', 'done');
    }

    public function test_the_rules_keep_to_the_project_and_the_known_trades(): void
    {
        $other = $this->makeProject($this->organization, ['name' => 'Second site']);
        $foreign = $this->floor('Foreign', null, $other);
        $rules = $this->tracker()->rules($this->project);

        $this->assertTrue(validator(['project_floor_id' => $foreign->id, 'trade' => 'plumbing', 'stage' => 'core_cutting', 'status' => 'done'], $rules)->fails());
        $this->assertTrue(validator(['project_floor_id' => $this->floor()->id, 'trade' => 'hvac', 'stage' => 'core_cutting', 'status' => 'done'], $rules)->fails());
        $this->assertTrue(validator(['project_floor_id' => $this->floor('Another')->id, 'trade' => 'plumbing', 'stage' => 'core_cutting', 'status' => 'finished'], $rules)->fails());
    }

    public function test_marking_a_stage_done_while_an_earlier_one_is_pending_warns_but_saves(): void
    {
        $floor = $this->floor();
        $this->set($floor, 'plumbing', 'bathroom_bed', 'pending', ['remark' => 'Waterproofing test not done']);

        $result = $this->set($floor, 'plumbing', 'outer_piping', 'done');

        $this->assertSame('done', $result['cell']->fresh()->status, 'Saved: the supervisor is writing down what happened');
        $this->assertCount(1, $result['warnings']);
        $this->assertSame('earlier_stage_pending', $result['warnings'][0]['code']);
        $this->assertSame('bathroom_bed', $result['warnings'][0]['stage']);
        $this->assertStringContainsString('IPS / bathroom bed', $result['warnings'][0]['message']);
        $this->assertSame('bathroom_bed', $result['event']->out_of_order_after);
    }

    public function test_every_earlier_pending_stage_is_named(): void
    {
        $floor = $this->floor();
        $this->set($floor, 'plumbing', 'core_cutting', 'pending', ['remark' => 'a']);
        $this->set($floor, 'plumbing', 'internal_fitting', 'pending', ['remark' => 'b']);

        $result = $this->set($floor, 'plumbing', 'sanitary_ware', 'done');

        $this->assertSame(['internal_fitting', 'core_cutting'], array_column($result['warnings'], 'stage'), 'Nearest first');
    }

    public function test_no_warning_when_nothing_earlier_is_pending(): void
    {
        $floor = $this->floor();
        $this->set($floor, 'plumbing', 'core_cutting', 'in_progress');
        // A later stage pending is not an earlier one.
        $this->set($floor, 'plumbing', 'drainage', 'pending', ['remark' => 'x']);

        $this->assertSame([], $this->set($floor, 'plumbing', 'bathroom_bed', 'done')['warnings']);
        $this->assertSame([], $this->set($floor, 'plumbing', 'internal_fitting', 'pending', ['remark' => 'y'])['warnings']);
    }

    public function test_the_warning_is_per_row_and_per_trade(): void
    {
        $floor = $this->floor('2nd floor');
        $flatA = $this->floor('Flat 201', $floor->id);
        $flatB = $this->floor('Flat 202', $floor->id);
        $this->set($flatA, 'electrical', 'slab_piping', 'pending', ['remark' => 'Passage conduit missing']);

        $this->assertSame([], $this->set($flatB, 'electrical', 'wiring', 'done')['warnings'], 'Another flat');
        $this->assertSame([], $this->set($flatA, 'plumbing', 'drainage', 'done')['warnings'], 'Another trade');
        $this->assertCount(1, $this->set($flatA, 'electrical', 'wiring', 'done')['warnings']);
    }

    public function test_the_warning_clears_once_the_earlier_stage_is_done(): void
    {
        $floor = $this->floor();
        $this->set($floor, 'electrical', 'slab_piping', 'pending', ['remark' => 'x']);
        $late = $this->set($floor, 'electrical', 'wiring', 'done');
        $this->assertCount(1, $this->tracker()->warningsFor($late['cell']->fresh()));

        $this->set($floor, 'electrical', 'slab_piping', 'done');

        $this->assertSame([], $this->tracker()->warningsFor($late['cell']->fresh()));
    }

    public function test_the_matrix_shows_percent_per_trade_and_flags_out_of_order_cells(): void
    {
        $floor = $this->floor('2nd floor');
        $flat = $this->floor('Flat 201', $floor->id);
        $this->set($flat, 'plumbing', 'core_cutting', 'done');
        $this->set($flat, 'plumbing', 'bathroom_bed', 'done');
        $this->set($flat, 'plumbing', 'internal_fitting', 'pending', ['remark' => 'Geyser line']);
        $this->set($flat, 'plumbing', 'outer_piping', 'done');
        foreach (['slab_piping', 'wall_piping', 'wiring', 'boards'] as $stage) {
            $this->set($flat, 'electrical', $stage, 'done');
        }
        $this->set($flat, 'electrical', 'final_fittings', 'na');

        $matrix = $this->tracker()->matrix($this->project);

        $this->assertSame(['2nd floor', '2nd floor › Flat 201'], array_column($matrix['rows'], 'label'));
        $row = $matrix['rows'][1];
        $this->assertSame(1, $row['level']);
        $this->assertSame('50.00', $row['percent']['plumbing'], '3 of 6 stages done');
        $this->assertSame('100.00', $row['percent']['electrical'], 'Not applicable is left out of the count');
        $this->assertTrue($row['cells']['plumbing']['outer_piping']['warning']);
        $this->assertFalse($row['cells']['plumbing']['bathroom_bed']['warning']);
        $this->assertSame('Geyser line', $row['cells']['plumbing']['internal_fitting']['remark']);
        $this->assertSame(1, $row['pending']);
        $this->assertSame('not_started', $matrix['rows'][0]['cells']['plumbing']['core_cutting']['status']);
        $this->assertSame('0.00', $matrix['rows'][0]['percent']['plumbing']);
    }

    public function test_before_finishing_lists_every_pending_item_on_the_floor_and_its_flats(): void
    {
        $floor = $this->floor('2nd floor');
        $flat = $this->floor('Flat 201', $floor->id);
        $elsewhere = $this->floor('3rd floor');
        $this->set($floor, 'electrical', 'wiring', 'pending', ['remark' => 'Outside passage wiring not done']);
        $this->set($flat, 'plumbing', 'sanitary_ware', 'pending', ['remark' => 'Basin missing']);
        $this->set($elsewhere, 'plumbing', 'drainage', 'pending', ['remark' => 'Not on this floor']);

        $list = $this->tracker()->preFinishing($floor);

        $this->assertFalse($list['ready']);
        $this->assertCount(2, $list['pending']);
        $this->assertEqualsCanonicalizing(['Outside passage wiring not done', 'Basin missing'], array_column($list['pending'], 'remark'));
        $this->assertEqualsCanonicalizing(['2nd floor', '2nd floor › Flat 201'], array_column($list['pending'], 'row'));
        $this->assertNotEmpty($list['unfinished'], 'Stages never started are listed too, apart from the pending ones');
        $this->assertSame(0, count(array_filter($list['unfinished'], fn ($item) => $item['status'] === 'pending')));
    }

    public function test_before_finishing_is_ready_only_when_every_stage_is_done_or_not_applicable(): void
    {
        $floor = $this->floor('1st floor');

        $this->assertFalse($this->tracker()->preFinishing($floor)['ready'], 'Nothing recorded is not the same as nothing pending');
        $this->assertCount(11, $this->tracker()->preFinishing($floor)['unfinished']);

        foreach ($this->tracker()->trades() as $trade => $definition) {
            foreach (array_keys($definition['stages']) as $stage) {
                $this->set($floor, $trade, $stage, $stage === 'final_fittings' ? 'na' : 'done');
            }
        }

        $list = $this->tracker()->preFinishing($floor);
        $this->assertTrue($list['ready']);
        $this->assertSame([], $list['pending']);
        $this->assertSame([], $list['unfinished']);
    }

    public function test_the_floor_board_counts_pending_stage_items_under_the_floor(): void
    {
        $floor = $this->floor('2nd floor');
        $flat = $this->floor('Flat 201', $floor->id);
        $this->set($flat, 'electrical', 'wiring', 'pending', ['remark' => 'x']);

        $board = app(ProgressReport::class)->floors($this->project);

        $this->assertSame(1, $board[0]['mep_pending']);
        $this->assertSame(1, $board[0]['children'][0]['mep_pending']);
    }

    public function test_a_repeated_client_uuid_changes_nothing(): void
    {
        $floor = $this->floor();
        $uuid = (string) Str::uuid();

        $first = $this->set($floor, 'plumbing', 'core_cutting', 'done', ['client_uuid' => $uuid]);
        $this->set($floor, 'plumbing', 'core_cutting', 'in_progress');
        $again = $this->set($floor, 'plumbing', 'core_cutting', 'done', ['client_uuid' => $uuid]);

        $this->assertTrue($again['repeat']);
        $this->assertFalse($first['repeat']);
        $this->assertSame($first['event']->id, $again['event']->id);
        $this->assertSame(2, MepStageEvent::count());
        $this->assertSame('in_progress', MepStageCell::first()->status, 'The retry did not undo the later change');
    }

    public function test_a_uuid_cannot_be_reused_for_a_different_cell(): void
    {
        $floor = $this->floor();
        $uuid = (string) Str::uuid();
        $this->set($floor, 'plumbing', 'core_cutting', 'done', ['client_uuid' => $uuid]);

        $this->expectException(ValidationException::class);
        $this->set($floor, 'plumbing', 'drainage', 'done', ['client_uuid' => $uuid]);
    }

    public function test_a_change_older_than_the_cell_is_kept_in_the_history_but_not_applied(): void
    {
        $floor = $this->floor();
        $this->set($floor, 'plumbing', 'core_cutting', 'done', ['occurred_at' => '2026-11-10T10:00:00+05:30']);

        $stale = $this->set($floor, 'plumbing', 'core_cutting', 'in_progress', ['occurred_at' => '2026-11-10T09:00:00+05:30']);

        $this->assertFalse($stale['event']->applied);
        $this->assertSame('done', MepStageCell::first()->status);
        $this->assertSame(2, MepStageEvent::count());

        $newer = $this->set($floor, 'plumbing', 'core_cutting', 'pending', ['occurred_at' => '2026-11-10T11:00:00+05:30', 'remark' => 'Redo']);
        $this->assertTrue($newer['event']->applied);
        $this->assertSame('pending', MepStageCell::first()->status);
    }

    public function test_stage_history_cannot_be_rewritten(): void
    {
        $result = $this->set($this->floor(), 'plumbing', 'core_cutting', 'done');

        $this->expectException(ImmutableRecordException::class);
        $result['event']->update(['status' => 'pending']);
    }

    public function test_photos_attach_to_a_cell_once_and_stay_private(): void
    {
        $cell = $this->set($this->floor(), 'electrical', 'wiring', 'pending', ['remark' => 'Passage'])['cell'];
        $uuid = (string) Str::uuid();
        $service = app(ProgressPhotoService::class);

        $first = $service->attach($cell, UploadedFile::fake()->image('passage.jpg'), $this->owner, ['client_uuid' => $uuid]);
        $again = $service->attach($cell, UploadedFile::fake()->image('passage.jpg'), $this->owner, ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, ProgressPhoto::count());
        $this->assertCount(1, Storage::allFiles(), 'The retried upload was not kept');
        $this->assertStringStartsWith('org-'.$this->organization->id.'/progress/'.$this->project->id.'/', $first->path);
        $this->assertSame($cell->id, $first->mep_stage_cell_id);
        $this->assertSame([$first->id], $this->tracker()->matrix($this->project)['rows'][0]['cells']['electrical']['wiring']['photos']);
    }

    public function test_a_cell_keeps_at_most_ten_photos(): void
    {
        $cell = $this->set($this->floor(), 'electrical', 'wiring', 'done')['cell'];
        $service = app(ProgressPhotoService::class);

        for ($i = 0; $i < ProgressPhotoService::MAX_PER_RECORD; $i++) {
            $service->attach($cell, UploadedFile::fake()->image("p$i.jpg"), $this->owner);
        }

        $this->expectException(ValidationException::class);
        $service->attach($cell, UploadedFile::fake()->image('one-too-many.jpg'), $this->owner);
    }
}
