<?php

namespace Tests\Feature\SiteProgress;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\ProgressEntry;
use App\Services\SiteControl\WorkProgress;
use App\Services\SiteProgress\ProgressEntryService;
use App\Services\SiteProgress\ProgressReport;
use App\Support\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProgressEntryServiceTest extends SiteProgressTestCase
{
    private function service(): ProgressEntryService
    {
        return app(ProgressEntryService::class);
    }

    public function test_an_entry_records_who_did_what_and_when(): void
    {
        $item = $this->item();
        $floor = $this->floor();

        $entry = $this->work($item, '12.5', $floor, ['remark' => 'Slab cast']);

        $this->assertSame('12.500', $entry->qty_done);
        $this->assertSame($floor->id, $entry->project_floor_id);
        $this->assertSame($this->owner->id, $entry->entered_by);
        $this->assertSame(now()->toDateString(), $entry->work_date->toDateString());
        $this->assertFalse($entry->is_pending);
        $this->assertNotNull(AuditLog::where('auditable_type', $entry->getMorphClass())->where('auditable_id', $entry->id)->first());
    }

    public function test_item_percent_is_the_flooring_example(): void
    {
        $item = $this->item('100.000');
        $this->work($item, '50');

        $standing = app(ProgressReport::class)->itemStanding($item->id);

        $this->assertSame('50.00', $standing['percent']);
        $this->assertSame('50.00', app(WorkProgress::class)->itemPercent($item));
        $this->assertSame('50.000', $standing['balance_qty']);
    }

    public function test_the_same_client_uuid_records_one_entry(): void
    {
        $item = $this->item();
        $uuid = (string) Str::uuid();

        $first = $this->work($item, '10', null, ['client_uuid' => $uuid]);
        $second = $this->work($item, '10', null, ['client_uuid' => $uuid]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ProgressEntry::count());
        $this->assertSame('10.000', (string) app(WorkProgress::class)->completedQty($item->id));
    }

    public function test_a_repeated_uuid_from_another_project_is_refused(): void
    {
        $uuid = (string) Str::uuid();
        $this->work($this->item(), '10', null, ['client_uuid' => $uuid]);

        $other = $this->makeProject($this->organization, ['name' => 'Second site']);
        $otherItem = $this->item('50.000', $other);

        $this->expectException(ValidationException::class);
        $this->service()->record($other, ['boq_item_id' => $otherItem->id, 'qty_done' => '5', 'client_uuid' => $uuid], $this->owner);
    }

    public function test_work_needs_a_quantity_unless_it_is_a_pending_remark(): void
    {
        $item = $this->item();

        try {
            $this->work($item, '0');
            $this->fail('A zero entry with nothing pending should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('qty_done', $e->errors());
        }

        try {
            $this->work($item, '0', null, ['is_pending' => true]);
            $this->fail('A pending entry needs a remark.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('remark', $e->errors());
        }

        $entry = $this->work($item, '0', null, ['is_pending' => true, 'remark' => 'Outside passage wiring not done']);
        $this->assertTrue($entry->is_pending);
        $this->assertSame('0.000', $entry->qty_done);
    }

    public function test_a_correction_carries_the_difference_and_the_first_entry_stays(): void
    {
        $item = $this->item('100.000');
        $entry = $this->work($item, '60', null, ['work_date' => '2026-11-02']);

        $correction = $this->service()->correct($entry, '50', 'Counted the wrong wall', $this->owner);

        $this->assertSame('-10.000', $correction->qty_done);
        $this->assertSame($entry->id, $correction->corrects_entry_id);
        $this->assertSame('2026-11-02', $correction->work_date->toDateString(), 'Dated the day the work was done, so week totals come out right');
        $this->assertSame('60.000', $entry->fresh()->qty_done, 'The original is untouched');
        $this->assertSame('50.00', app(WorkProgress::class)->itemPercent($item));
        $this->assertSame('50.000', Dec::qty($this->service()->netQty($entry)));
    }

    public function test_a_second_correction_works_from_the_corrected_figure(): void
    {
        $item = $this->item('100.000');
        $entry = $this->work($item, '60');
        $this->service()->correct($entry, '50', 'First fix', $this->owner);

        $second = $this->service()->correct($entry, '55', 'Re-measured', $this->owner);

        $this->assertSame('5.000', $second->qty_done);
        $this->assertSame('55.00', app(WorkProgress::class)->itemPercent($item));

        $this->expectException(ValidationException::class);
        $this->service()->correct($entry, '55', 'No change', $this->owner);
    }

    public function test_a_correction_needs_a_reason_and_only_applies_to_work_entries(): void
    {
        $item = $this->item();
        $entry = $this->work($item, '20');

        try {
            $this->service()->correct($entry, '10', '  ', $this->owner);
            $this->fail('A reason is required.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('remark', $e->errors());
        }

        $correction = $this->service()->correct($entry, '10', 'Typo', $this->owner);

        $this->expectException(ValidationException::class);
        $this->service()->correct($correction, '5', 'Correcting a correction', $this->owner);
    }

    public function test_a_repeated_correction_uuid_changes_nothing(): void
    {
        $item = $this->item();
        $entry = $this->work($item, '20');
        $uuid = (string) Str::uuid();

        $first = $this->service()->correct($entry, '15', 'Typo', $this->owner, $uuid);
        $second = $this->service()->correct($entry, '15', 'Typo', $this->owner, $uuid);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('15.000', (string) app(WorkProgress::class)->completedQty($item->id));
    }

    public function test_entries_cannot_be_edited_or_deleted(): void
    {
        $entry = $this->work($this->item(), '20');

        try {
            $entry->update(['qty_done' => '99']);
            $this->fail('An entry must not be editable.');
        } catch (ImmutableRecordException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->expectException(ImmutableRecordException::class);
        $entry->delete();
    }

    public function test_pending_remarks_stay_listed_until_finished(): void
    {
        $item = $this->item();
        $floor = $this->floor();
        $report = app(ProgressReport::class);
        $pending = $this->work($item, '0', $floor, ['is_pending' => true, 'remark' => 'Outside passage wiring not done']);
        $this->work($item, '5', $floor);

        $this->assertSame([$pending->id], $report->pending($this->project)->pluck('id')->all(), 'Plain progress on the item does not close a pending remark');

        $resolution = $this->service()->resolve($pending, 'Passage wired', $this->owner);

        $this->assertSame($pending->id, $resolution->resolves_entry_id);
        $this->assertSame('0.000', $resolution->qty_done);
        $this->assertSame([], $report->pending($this->project)->pluck('id')->all());
        $this->assertSame($pending->id, ProgressEntry::find($pending->id)->id, 'The remark itself stays in the history');
    }

    public function test_a_pending_item_cannot_be_finished_twice_and_only_pending_items_can_be_finished(): void
    {
        $item = $this->item();
        $pending = $this->work($item, '0', null, ['is_pending' => true, 'remark' => 'Skirting left']);
        $this->service()->resolve($pending, null, $this->owner);

        try {
            $this->service()->resolve($pending, null, $this->owner);
            $this->fail('Already finished.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('entry', $e->errors());
        }

        $plain = $this->work($item, '5');
        $this->expectException(ValidationException::class);
        $this->service()->resolve($plain, null, $this->owner);
    }

    public function test_a_repeated_finish_uuid_returns_the_first_resolution(): void
    {
        $item = $this->item();
        $pending = $this->work($item, '0', null, ['is_pending' => true, 'remark' => 'Skirting left']);
        $uuid = (string) Str::uuid();

        $first = $this->service()->resolve($pending, 'Done', $this->owner, $uuid);
        $second = $this->service()->resolve($pending, 'Done', $this->owner, $uuid);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ProgressEntry::where('resolves_entry_id', $pending->id)->count());
    }

    public function test_doing_more_than_the_boq_quantity_is_flagged_but_allowed(): void
    {
        $item = $this->item('10.000');

        $within = $this->work($item, '8');
        $this->assertFalse($this->service()->exceedsBoq($within));

        $over = $this->work($item, '5');
        $this->assertTrue($this->service()->exceedsBoq($over));
        $this->assertSame('130.00', app(WorkProgress::class)->itemPercent($item));
    }

    public function test_net_quantities_cover_a_whole_page_in_one_go(): void
    {
        $item = $this->item();
        $one = $this->work($item, '40');
        $two = $this->work($item, '10');
        $this->service()->correct($one, '30', 'Typo', $this->owner);

        $nets = $this->service()->netQuantities(ProgressEntry::whereIn('id', [$one->id, $two->id])->get());

        $this->assertSame('30.000', $nets[$one->id]);
        $this->assertSame('10.000', $nets[$two->id]);
    }

    public function test_the_validation_rules_keep_to_the_project(): void
    {
        $other = $this->makeProject($this->organization, ['name' => 'Second site']);
        $foreignItem = $this->item('10.000', $other);
        $foreignFloor = $this->floor('Other floor', null, $other);

        $rules = $this->service()->rules($this->project);
        $validator = validator(['boq_item_id' => $foreignItem->id, 'project_floor_id' => $foreignFloor->id, 'qty_done' => '1'], $rules);

        $this->assertTrue($validator->fails());
        $this->assertEqualsCanonicalizing(['boq_item_id', 'project_floor_id'], array_keys($validator->errors()->toArray()));

        $this->assertTrue(validator(['boq_item_id' => $this->item()->id, 'qty_done' => '1.2345'], $rules)->fails(), 'More than three decimals');
        $this->assertTrue(validator(['boq_item_id' => $this->item()->id, 'qty_done' => '1', 'work_date' => Carbon::tomorrow()->toDateString()], $rules)->fails(), 'No entries for the future');
    }
}
