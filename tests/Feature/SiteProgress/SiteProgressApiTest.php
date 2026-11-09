<?php

namespace Tests\Feature\SiteProgress;

use App\Models\MepStageCell;
use App\Models\MepStageEvent;
use App\Models\ProgressEntry;
use App\Models\ProgressPhoto;
use App\Models\ProjectFloor;
use App\Services\Access\TeamAccessService;
use App\Services\SiteProgress\FloorService;
use App\Services\SiteProgress\MepTracker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class SiteProgressApiTest extends SiteProgressTestCase
{
    private const API = '/api/v1';

    private ProjectFloor $floor;

    private ProjectFloor $flat;

    private function populate(): void
    {
        $item = $this->item('100.000');
        $this->floor = $this->floor('2nd floor');
        $this->flat = $this->floor('Flat 201', $this->floor->id);
        app(FloorService::class)->setTarget($this->flat, $item, '50');
        $this->work($item, '20', $this->flat);
        $this->work($item, '0', $this->flat, ['is_pending' => true, 'remark' => 'Passage wiring']);
        app(MepTracker::class)->update($this->project, ['project_floor_id' => $this->flat->id, 'trade' => 'electrical', 'stage' => 'wiring', 'status' => 'pending', 'remark' => 'Passage'], $this->owner);
    }

    private function url(string $path): string
    {
        return self::API."/projects/{$this->project->id}/$path";
    }

    public function test_no_response_carries_money(): void
    {
        $this->populate();
        // The project manager holds money.view; the screens and endpoints of this module still show none.
        Sanctum::actingAs($this->member($this->organization, 'pm'));

        foreach (['floors', 'progress/summary', 'progress/items', 'progress/pending', 'progress/entries', 'stages'] as $path) {
            $response = $this->getJson($this->url($path))->assertOk();
            $this->assertNoMoneyKeys($response->json());
        }

        $this->assertNoMoneyKeys($this->getJson(self::API."/floors/{$this->floor->id}/progress")->assertOk()->json());
        $this->assertNoMoneyKeys($this->getJson(self::API."/floors/{$this->floor->id}/before-finishing")->assertOk()->json());
    }

    public function test_floors_come_as_a_tree_with_percentages(): void
    {
        $this->populate();
        Sanctum::actingAs($this->member($this->organization, 'engineer'));

        $response = $this->getJson($this->url('floors'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', '2nd floor')
            ->assertJsonPath('data.0.children.0.name', 'Flat 201')
            ->assertJsonPath('data.0.children.0.percent', '40.00')
            ->assertJsonPath('data.0.children.0.pending', 1)
            ->assertJsonPath('data.0.mep_pending', 1);

        $this->assertSame($this->flat->id, $response->json('data.0.children.0.id'));

        $this->getJson(self::API."/floors/{$this->flat->id}/progress")->assertOk()
            ->assertJsonPath('data.lines.0.planned_qty', '50.000')
            ->assertJsonPath('data.lines.0.done_qty', '20.000')
            ->assertJsonPath('data.lines.0.balance_qty', '30.000')
            ->assertJsonPath('data.pending_entries.0.remark', 'Passage wiring')
            ->assertJsonPath('data.pending_entries.0.item_code', fn ($code) => $code !== null);
    }

    public function test_the_boq_list_has_quantities_and_percentages_and_no_money(): void
    {
        $this->populate();
        Sanctum::actingAs($this->member($this->organization, 'engineer'));

        $row = $this->getJson($this->url('progress/items'))->assertOk()->json('data.0');

        $this->assertEqualsCanonicalizing(['id', 'section', 'code', 'description', 'unit', 'qty', 'done_qty', 'balance_qty', 'percent'], array_keys($row));
        $this->assertSame('100.000', $row['qty']);
        $this->assertSame('20.00', $row['percent']);
    }

    public function test_the_summary_compares_time_with_work(): void
    {
        $this->project->update(['start_date' => now()->subDays(90), 'planned_days' => 180]);
        $this->work($this->item('100.000'), '35');
        Sanctum::actingAs($this->member($this->organization, 'viewer'));

        $this->getJson($this->url('progress/summary'))->assertOk()
            ->assertJsonPath('data.work_percent', '35.00')
            ->assertJsonPath('data.time_percent', '50.00')
            ->assertJsonPath('data.lag', '15.00')
            ->assertJsonPath('data.status', 'behind');
    }

    public function test_an_entry_is_saved_once_however_often_it_is_sent(): void
    {
        $item = $this->item('100.000');
        Sanctum::actingAs($engineer = $this->member($this->organization, 'engineer'));
        $payload = ['client_uuid' => (string) Str::uuid(), 'boq_item_id' => $item->id, 'qty_done' => '12.5', 'remark' => 'Footing F1', 'work_date' => now()->toDateString()];

        $this->postJson($this->url('progress/entries'), $payload)->assertCreated()
            ->assertJsonPath('data.qty_done', '12.500')
            ->assertJsonPath('data.entered_by', $engineer->id)
            ->assertJsonPath('data.client_uuid', $payload['client_uuid'])
            ->assertJsonPath('item.percent', '12.50')
            ->assertJsonPath('item.done_qty', '12.500')
            ->assertJsonPath('warnings', []);

        $this->postJson($this->url('progress/entries'), $payload)->assertOk()->assertJsonPath('item.done_qty', '12.500');

        $this->assertSame(1, ProgressEntry::count());
    }

    public function test_an_entry_over_the_boq_quantity_comes_back_with_a_warning(): void
    {
        $item = $this->item('10.000');
        Sanctum::actingAs($this->member($this->organization, 'engineer'));

        $this->postJson($this->url('progress/entries'), ['boq_item_id' => $item->id, 'qty_done' => '11'])->assertCreated()
            ->assertJsonPath('warnings.0.code', 'over_boq');
    }

    public function test_entries_are_validated(): void
    {
        $item = $this->item();
        $foreign = $this->item('5.000', $this->makeProject($this->organization, ['name' => 'Second site']));
        Sanctum::actingAs($this->member($this->organization, 'engineer'));

        $this->postJson($this->url('progress/entries'), [])->assertUnprocessable()->assertJsonValidationErrors('boq_item_id');
        $this->postJson($this->url('progress/entries'), ['boq_item_id' => $foreign->id, 'qty_done' => 1])->assertUnprocessable()->assertJsonValidationErrors('boq_item_id');
        $this->postJson($this->url('progress/entries'), ['boq_item_id' => $item->id])->assertUnprocessable()->assertJsonValidationErrors('qty_done');
        $this->postJson($this->url('progress/entries'), ['boq_item_id' => $item->id, 'qty_done' => 1, 'project_floor_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('project_floor_id');
        $this->postJson($this->url('progress/entries'), ['boq_item_id' => $item->id, 'qty_done' => 1, 'client_uuid' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
        $this->postJson($this->url('progress/entries'), ['boq_item_id' => $item->id, 'is_pending' => true])->assertUnprocessable()->assertJsonValidationErrors('remark');
    }

    public function test_corrections_and_finishing_pending_items(): void
    {
        $item = $this->item('100.000');
        $entry = $this->work($item, '60');
        $pending = $this->work($item, '0', null, ['is_pending' => true, 'remark' => 'Curing left']);
        Sanctum::actingAs($this->member($this->organization, 'engineer'));
        $uuid = (string) Str::uuid();

        $this->postJson(self::API."/progress/entries/{$entry->id}/corrections", ['qty_done' => '50', 'remark' => 'Typo', 'client_uuid' => $uuid])->assertCreated()
            ->assertJsonPath('data.qty_done', '-10.000')
            ->assertJsonPath('data.corrects_entry_id', $entry->id)
            ->assertJsonPath('item.percent', '50.00');
        $this->postJson(self::API."/progress/entries/{$entry->id}/corrections", ['qty_done' => '50', 'remark' => 'Typo', 'client_uuid' => $uuid])->assertOk();
        $this->postJson(self::API."/progress/entries/{$entry->id}/corrections", ['qty_done' => '50'])->assertUnprocessable()->assertJsonValidationErrors('remark');
        $this->assertSame(1, ProgressEntry::where('corrects_entry_id', $entry->id)->count());

        $this->getJson($this->url('progress/pending'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $pending->id);
        $this->postJson(self::API."/progress/entries/{$pending->id}/resolve", ['remark' => 'Cured', 'client_uuid' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.resolves_entry_id', $pending->id);
        $this->getJson($this->url('progress/pending'))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson(self::API."/progress/entries/{$pending->id}/resolve")->assertUnprocessable();
    }

    public function test_entries_can_be_listed_filtered_and_pulled_since_a_time(): void
    {
        $item = $this->item();
        $floor = $this->floor();
        $this->work($item, '1', $floor);
        $this->work($item, '2');
        Sanctum::actingAs($this->member($this->organization, 'viewer'));

        $this->getJson($this->url('progress/entries'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->url('progress/entries?project_floor_id='.$floor->id))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->url('progress/entries?since='.urlencode(now()->addHour()->toIso8601String())))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url('progress/entries?since=garbage'))->assertUnprocessable();
    }

    public function test_photos_upload_once_and_stay_private(): void
    {
        $entry = $this->work($this->item(), '5');
        Sanctum::actingAs($this->member($this->organization, 'engineer'));
        $uuid = (string) Str::uuid();

        $created = $this->postJson(self::API."/progress/entries/{$entry->id}/photos", ['photo' => UploadedFile::fake()->image('wall.jpg'), 'client_uuid' => $uuid, 'caption' => 'Wall'])
            ->assertCreated()->assertJsonPath('data.caption', 'Wall');
        $this->postJson(self::API."/progress/entries/{$entry->id}/photos", ['photo' => UploadedFile::fake()->image('wall.jpg'), 'client_uuid' => $uuid])->assertOk();
        $this->postJson(self::API."/progress/entries/{$entry->id}/photos", ['photo' => UploadedFile::fake()->create('plan.pdf', 5, 'application/pdf')])->assertUnprocessable();

        $this->assertSame(1, ProgressPhoto::count());
        $this->getJson($this->url('progress/entries'))->assertJsonPath('data.0.photos.0.id', $created->json('data.id'));
        $this->get(self::API.'/progress/photos/'.$created->json('data.id'))->assertOk();

        Sanctum::actingAs($this->stranger()->owner);
        $this->get(self::API.'/progress/photos/'.$created->json('data.id'))->assertNotFound();
    }

    public function test_the_stage_matrix_comes_with_the_stage_lists(): void
    {
        $this->populate();
        Sanctum::actingAs($this->member($this->organization, 'engineer'));

        $response = $this->getJson($this->url('stages'))->assertOk()
            ->assertJsonPath('data.trades.0.key', 'plumbing')
            ->assertJsonPath('data.trades.0.stages.0.key', 'core_cutting')
            ->assertJsonPath('data.trades.1.stages.4.optional', true)
            ->assertJsonPath('data.statuses.3.key', 'pending')
            ->assertJsonPath('data.rows.1.label', '2nd floor › Flat 201')
            ->assertJsonPath('data.rows.1.cells.electrical.wiring.status', 'pending');

        $this->assertCount(6, $response->json('data.trades.0.stages'));
        $this->assertCount(5, $response->json('data.trades.1.stages'));
    }

    public function test_a_stage_status_is_saved_once_and_warns_about_an_earlier_pending_stage(): void
    {
        $this->populate();
        Sanctum::actingAs($this->member($this->organization, 'engineer'));
        $payload = ['client_uuid' => (string) Str::uuid(), 'project_floor_id' => $this->flat->id, 'trade' => 'electrical', 'stage' => 'boards', 'status' => 'done'];

        $first = $this->postJson($this->url('stages/cells'), $payload)->assertCreated()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('applied', true)
            ->assertJsonPath('warnings.0.code', 'earlier_stage_pending')
            ->assertJsonPath('warnings.0.stage', 'wiring');
        $this->postJson($this->url('stages/cells'), $payload)->assertOk()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('warnings.0.stage', 'wiring');

        $this->assertSame(2, MepStageCell::count());
        $this->assertSame(2, MepStageEvent::count());
    }

    public function test_a_stage_status_is_validated(): void
    {
        $this->populate();
        Sanctum::actingAs($this->member($this->organization, 'engineer'));
        $base = ['project_floor_id' => $this->flat->id, 'trade' => 'plumbing', 'stage' => 'core_cutting', 'status' => 'done'];

        $this->postJson($this->url('stages/cells'), [...$base, 'status' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors('remark');
        $this->postJson($this->url('stages/cells'), [...$base, 'status' => 'na'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($this->url('stages/cells'), [...$base, 'stage' => 'wiring'])->assertUnprocessable()->assertJsonValidationErrors('stage');
        $this->postJson($this->url('stages/cells'), [...$base, 'trade' => 'hvac'])->assertUnprocessable()->assertJsonValidationErrors('trade');
        $this->postJson($this->url('stages/cells'), [...$base, 'project_floor_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('project_floor_id');
    }

    public function test_a_stage_photo_goes_on_the_cell(): void
    {
        $this->populate();
        Sanctum::actingAs($this->member($this->organization, 'engineer'));
        $cell = MepStageCell::firstOrFail();

        $this->postJson(self::API."/stage-cells/{$cell->id}/photos", ['photo' => UploadedFile::fake()->image('p.jpg'), 'client_uuid' => (string) Str::uuid()])->assertCreated();

        $this->assertSame(1, $cell->photos()->count());
        $this->getJson($this->url('stages'))->assertJsonPath('data.rows.1.cells.electrical.wiring.photos.0', ProgressPhoto::first()->id);
    }

    public function test_the_checklist_before_finishing_lists_the_pending_items(): void
    {
        $this->populate();
        Sanctum::actingAs($this->member($this->organization, 'viewer'));

        $this->getJson(self::API."/floors/{$this->floor->id}/before-finishing")->assertOk()
            ->assertJsonPath('data.ready', false)
            ->assertJsonCount(1, 'data.pending')
            ->assertJsonPath('data.pending.0.remark', 'Passage')
            ->assertJsonPath('data.pending.0.row', '2nd floor › Flat 201');
    }

    public function test_a_viewer_reads_but_cannot_write(): void
    {
        $this->populate();
        $entry = ProgressEntry::firstOrFail();
        Sanctum::actingAs($this->member($this->organization, 'viewer'));

        $this->postJson($this->url('progress/entries'), ['boq_item_id' => $entry->boq_item_id, 'qty_done' => 1])->assertForbidden();
        $this->postJson(self::API."/progress/entries/{$entry->id}/corrections", ['qty_done' => 1, 'remark' => 'x'])->assertForbidden();
        $this->postJson(self::API."/progress/entries/{$entry->id}/resolve")->assertForbidden();
        $this->postJson(self::API."/progress/entries/{$entry->id}/photos", ['photo' => UploadedFile::fake()->image('p.jpg')])->assertForbidden();
        $this->postJson($this->url('stages/cells'), ['project_floor_id' => $this->flat->id, 'trade' => 'plumbing', 'stage' => 'core_cutting', 'status' => 'done'])->assertForbidden();
        $this->postJson(self::API.'/stage-cells/1/photos', ['photo' => UploadedFile::fake()->image('p.jpg')])->assertForbidden();
        $this->getJson($this->url('progress/summary'))->assertOk();
    }

    public function test_it_needs_a_signed_in_user_and_the_site_control_plan(): void
    {
        $this->getJson($this->url('floors'))->assertUnauthorized();

        $free = $this->tenant('Free Builders', 'free');
        $project = $this->makeProject($free);
        Sanctum::actingAs($this->member($free, 'owner'));

        $this->getJson(self::API."/projects/{$project->id}/floors")->assertForbidden();
        $this->getJson(self::API."/projects/{$project->id}/stages")->assertForbidden();
    }

    public function test_another_company_reaches_nothing(): void
    {
        $this->populate();
        $entry = ProgressEntry::firstOrFail();
        Sanctum::actingAs($this->stranger()->owner);

        foreach (['floors', 'progress/summary', 'progress/items', 'progress/pending', 'progress/entries', 'stages'] as $path) {
            $this->getJson($this->url($path))->assertNotFound();
        }

        $this->getJson(self::API."/floors/{$this->flat->id}/progress")->assertNotFound();
        $this->getJson(self::API."/floors/{$this->flat->id}/before-finishing")->assertNotFound();
        $this->postJson($this->url('progress/entries'), ['boq_item_id' => $entry->boq_item_id, 'qty_done' => 1])->assertNotFound();
        $this->postJson(self::API."/progress/entries/{$entry->id}/corrections", ['qty_done' => 1, 'remark' => 'x'])->assertNotFound();
        $this->postJson(self::API."/progress/entries/{$entry->id}/resolve")->assertNotFound();
        $this->postJson($this->url('stages/cells'), ['project_floor_id' => $this->flat->id, 'trade' => 'plumbing', 'stage' => 'core_cutting', 'status' => 'done'])->assertNotFound();
        $this->assertSame(2, ProgressEntry::withoutGlobalScopes()->count(), 'Nothing was added');
    }

    public function test_a_person_limited_to_another_project_reaches_nothing_here(): void
    {
        $this->populate();
        $second = $this->makeProject($this->organization, ['name' => 'Second site']);
        $limited = $this->member($this->organization, 'engineer');
        app(TeamAccessService::class)->applyProjects($limited, true, [$second->id]);
        Sanctum::actingAs($limited->refresh());

        $this->getJson($this->url('floors'))->assertNotFound();
        $this->getJson($this->url('stages'))->assertNotFound();
        $this->getJson(self::API."/floors/{$this->flat->id}/progress")->assertNotFound();
        $this->getJson(self::API."/projects/{$second->id}/floors")->assertOk();
    }

    public function test_a_repeated_uuid_cannot_reach_a_row_in_a_project_the_person_is_limited_from(): void
    {
        $this->populate();
        $entry = ProgressEntry::firstOrFail();
        $uuid = (string) Str::uuid();
        $entry->newQuery()->withoutGlobalScopes()->whereKey($entry->id)->update(['client_uuid' => $uuid]);

        $second = $this->makeProject($this->organization, ['name' => 'Second site']);
        $item = $this->item('10.000', $second);
        $limited = $this->member($this->organization, 'engineer');
        app(TeamAccessService::class)->applyProjects($limited, true, [$second->id]);
        Sanctum::actingAs($limited->refresh());

        $this->postJson(self::API."/projects/{$second->id}/progress/entries", ['client_uuid' => $uuid, 'boq_item_id' => $item->id, 'qty_done' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }
}
