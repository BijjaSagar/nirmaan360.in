<?php

namespace Tests\Feature\OwnerPack;

use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Models\ProgressEntry;
use App\Services\Access\TeamAccessService;
use App\Services\MeasurementService;
use App\Services\ProjectSetup\BoqCategories;
use App\Services\SiteControl\WorkProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WorkProgress::measuredPercent: the share of the BOQ's value that has been measured and approved.
 * It is the only percentage a weekly update prints, so it is worked out here on invented figures.
 *
 * The house BOQ: 12.500 cum at 4,200.10, 80 sqm at 950.00 and 1 LS at 18,333.33. Its value is 1,46,834.58.
 */
class MeasuredPercentTest extends TestCase
{
    use BuildsOwnerPack, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->house();
        $this->actingAs($this->owner);
    }

    private function percent(): ?string
    {
        return app(WorkProgress::class)->measuredPercent($this->project);
    }

    /** @param  array<string, mixed>  $overrides */
    private function measure(BoqItem $line, string $length, bool $approve = true, array $overrides = []): MeasurementEntry
    {
        $entry = $this->draftEntry($this->project, $line, $this->owner, ['nos' => '1', 'length' => $length, 'breadth' => null, 'depth' => null, ...$overrides]);

        return $approve ? $this->approveEntry($entry, $this->owner, $this->owner) : $entry;
    }

    public function test_there_is_no_percentage_until_a_measurement_is_approved(): void
    {
        $this->assertNull($this->percent());

        // A draft and an entry still in review are not approved work.
        $this->measure($this->lines[1], '40', approve: false);
        app(MeasurementService::class)->submit($this->measure($this->lines[1], '15', approve: false), $this->owner);
        $this->assertNull($this->percent());

        // Work entered on the progress screen is not a measurement either.
        ProgressEntry::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'boq_item_id' => $this->lines[1]->id, 'work_date' => '2026-11-08', 'qty_done' => '60.000', 'entered_by' => $this->owner->id]);
        $this->assertNull($this->percent());
        $this->assertNotSame('0.00', app(WorkProgress::class)->projectPercent($this->project));
    }

    public function test_approved_quantity_is_valued_at_the_boq_rate_against_the_whole_boq(): void
    {
        // 40 sqm of 80 at 950.00 is 38,000.00 of 1,46,834.58.
        $this->measure($this->lines[1], '40');
        $this->assertSame('25.88', $this->percent());

        // Two more entries on another line add up: 2.500 cum at 4,200.10 is 10,500.25.
        $this->measure($this->lines[0], '1.5');
        $this->measure($this->lines[0], '1');
        $this->assertSame('33.03', $this->percent());

        // A rejected entry counts for nothing.
        MeasurementEntry::whereKey($this->measure($this->lines[2], '1', approve: false)->id)->update(['status' => 'REJECTED']);
        $this->assertSame('33.03', $this->percent());
    }

    public function test_a_line_never_counts_for_more_than_its_boq_quantity(): void
    {
        $this->measure($this->lines[1], '40');
        // 20 cum measured against a BOQ quantity of 12.500: the line counts in full, 52,501.25, and no more.
        $this->measure($this->lines[0], '20');

        $this->assertSame('61.63', $this->percent());

        // Everything measured, one line over: the whole and not above it.
        $this->measure($this->lines[1], '70');
        $this->measure($this->lines[2], '1');
        $this->assertSame('100.00', $this->percent());
    }

    public function test_an_approved_deduction_and_an_approved_reversal_take_quantity_off(): void
    {
        $entry = $this->measure($this->lines[1], '40');
        $this->measure($this->lines[1], '10', overrides: ['is_deduction' => true]);

        // 30 sqm at 950.00 is 28,500.00.
        $this->assertSame('19.41', $this->percent());

        // A reversal counts once it is approved, not while it is a draft.
        $reversal = app(MeasurementService::class)->reverse($entry, $this->owner);
        $this->assertSame('19.41', $this->percent());

        $this->approveEntry($reversal, $this->owner, $this->owner);
        // 40 - 10 - 40 is below nothing: the line counts as nothing, never as a minus.
        $this->assertSame('0.00', $this->percent());
    }

    public function test_every_boq_line_is_in_the_whole_and_a_line_taken_out_of_the_boq_is_not(): void
    {
        $this->measure($this->lines[1], '40');

        // An overhead line has no site work to measure, and it is still part of the value the share is taken of.
        $overhead = $this->line($this->project, 'Z-01', 'Site supervision', 'LS', '1.000', '3165.42', 9);
        $overhead->update(['category' => BoqCategories::OVERHEAD]);
        // 38,000.00 of 1,50,000.00.
        $this->assertSame('25.33', $this->percent());

        $overhead->delete();
        $this->assertSame('25.88', $this->percent());
    }

    public function test_a_boq_without_value_gives_no_percentage(): void
    {
        $this->measure($this->lines[1], '40');
        BoqItem::where('project_id', $this->project->id)->update(['rate' => '0.00']);

        $this->assertNull($this->percent());
    }

    public function test_other_projects_and_other_companies_do_not_count(): void
    {
        $this->measure($this->lines[1], '40');

        $second = $this->makeProject($this->organization, ['name' => 'Kulkarni Row House']);
        $theirLine = $this->line($second, 'K-01', 'Terrace waterproofing', 'sqm', '10.000', '500.00');
        $this->approveEntry($this->draftEntry($second, $theirLine, $this->owner, ['nos' => '1', 'length' => '10', 'breadth' => null, 'depth' => null]), $this->owner, $this->owner);

        $other = $this->tenant('Konkan Builders');
        $otherOwner = $this->member($other, 'owner');
        $otherProject = $this->makeProject($other);
        $otherLine = $this->line($otherProject, 'O-01', 'Their work', 'sqm', '10.000', '700.00');
        $this->actingAs($otherOwner);
        $this->approveEntry($this->draftEntry($otherProject, $otherLine, $otherOwner, ['nos' => '1', 'length' => '5', 'breadth' => null, 'depth' => null]), $otherOwner, $otherOwner);

        $this->actingAs($this->owner);
        $this->assertSame('25.88', $this->percent());
        $this->assertSame('100.00', app(WorkProgress::class)->measuredPercent($second));

        // The same answer for someone limited to this project, and with nobody signed in.
        $limited = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($limited, true, [$this->project->id]);
        $this->actingAs($limited->refresh());
        $this->assertSame('25.88', $this->percent());

        $this->signOut();
        $this->assertSame('25.88', $this->percent());
    }
}
