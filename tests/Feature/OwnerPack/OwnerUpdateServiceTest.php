<?php

namespace Tests\Feature\OwnerPack;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Dpr;
use App\Models\DprWorkItem;
use App\Models\OwnerUpdate;
use App\Services\OwnerPack\OwnerUpdateDocument;
use App\Services\OwnerPack\OwnerUpdateShareService;
use App\Services\SiteControl\WorkProgress;
use App\Services\Wording\FactSheet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The weekly update as the service builds it: which records it reads, the figures it works out,
 * what blocks an approval, and that an approved update never changes.
 */
class OwnerUpdateServiceTest extends TestCase
{
    use BuildsOwnerUpdates, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site();
    }

    private function sheet(OwnerUpdate $update): FactSheet
    {
        return FactSheet::fromArray($update->fresh()->facts);
    }

    /** @return array<string, string> Slot id => its text, as the review screen would print it. */
    private function text(OwnerUpdate $update): array
    {
        [, $note] = $this->updates()->review($update->fresh());
        $this->assertNotNull($note);

        return array_column(app(OwnerUpdateDocument::class)->body($note), 'text', 'id');
    }

    /** @param  list<string>  $keys */
    private function assertRefused(callable $work, array $keys): ValidationException
    {
        try {
            $work();
        } catch (ValidationException $e) {
            $this->assertSame($keys, array_keys($e->errors()));

            return $e;
        }

        $this->fail('Nothing was refused.');
    }

    public function test_a_draft_reads_only_the_approved_daily_reports_of_its_week(): void
    {
        $this->week();
        $update = $this->draftUpdate();
        $sheet = $this->sheet($update);

        $this->assertSame([OwnerUpdate::DRAFT, '2026-11-09', '2026-11-15', '2026-11-14', false], [$update->status, $update->week_start->toDateString(), $update->week_end->toDateString(), $update->prepared_on->toDateString(), $update->show_money]);
        $this->assertSame([$this->organization->id, $this->project->id, $this->manager->id], [$update->organization_id, $update->project_id, $update->created_by]);

        // Three approved reports. The one in review, the draft and last week's report are not read.
        $this->assertSame('3', $sheet->get('report_count')->value);
        $this->assertSame(
            [
                ['n' => 1, 'name' => 'A-01 Excavation for foundation in all soils', 'qty' => '7.375', 'unit' => 'cum'],
                ['n' => 2, 'name' => 'B-04 Brick masonry in cement mortar', 'qty' => '22.500', 'unit' => 'sqm'],
                // A row that names no BOQ line has no unit, and a quantity of nothing is not printed for it.
                ['n' => 3, 'name' => 'Curing of plinth beam', 'qty' => null, 'unit' => null],
            ],
            app(OwnerUpdateDocument::class)->workLines($sheet, 'en'),
        );
        $this->assertSame('3', $sheet->get('work_count')->value);
        // 4 + 6 + 5 + 5 + 3.
        $this->assertSame('23', $sheet->get('worker_days')->value);
        $this->assertTrue($sheet->matches($update->fact_fingerprint));

        foreach (['work_more', 'measured_pct', 'photo_count', 'pay_number'] as $absent) {
            $this->assertFalse($sheet->has($absent), $absent);
        }

        $this->assertSame([
            'opening' => 'Here is the update on the work at your site.',
            'period' => 'This update covers the week from 9 November 2026 to 15 November 2026.',
            'reports' => 'Approved daily reports of that week: 3.',
            'work' => 'Lines of work reported in them: 3. The table gives the quantity of each.',
            'workers' => 'The daily worker counts in these reports add up to 23.',
            'closing' => 'Please tell us if you would like anything explained.',
        ], $this->text($update));

        // Nothing a report holds in free text is read, and no figure of a report that is not approved.
        $kept = json_encode($update->fresh()->getAttributes(), JSON_UNESCAPED_UNICODE);

        foreach (['mixer', 'steel came late', 'Internal remark', '55.555', '44.444', '77.777', 'Clear'] as $private) {
            $this->assertStringNotContainsString($private, $kept);
        }

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'owner_update.created')->sole();
        $this->assertSame([$this->organization->id, $this->manager->id, $update->id], [$audit->organization_id, $audit->user_id, $audit->auditable_id]);
        $this->assertSame(['2026-11-09', 'en', false, 0], [$audit->new_values['week_start'], $audit->new_values['language'], $audit->new_values['show_money'], $audit->new_values['photos']]);
        $this->assertArrayNotHasKey('facts', $audit->new_values);
    }

    public function test_a_week_is_seven_days_from_the_day_chosen_and_other_projects_and_companies_are_not_read(): void
    {
        $this->week();

        // Wednesday to Tuesday: the reports of the 12th, and nothing of Monday or Tuesday the 10th.
        $midweek = $this->draftUpdate(['week_start' => '2026-11-11']);
        $this->assertSame('2026-11-17', $midweek->week_end->toDateString());
        $this->assertSame('1', $this->sheet($midweek)->get('report_count')->value);
        $this->assertSame('3', $this->sheet($midweek)->get('worker_days')->value);

        // The same week in another project of the company, and in another company.
        $second = $this->makeProject($this->organization, ['name' => 'Kulkarni Row House']);
        $this->report('2026-11-10', 'APPROVED', [[$this->line($second, 'K-01', 'Terrace waterproofing', 'sqm', '10.000', '500.00'), '9.999']], ['Mason' => 50], $second);

        $other = $this->tenant('Konkan Builders');
        $theirs = $this->makeProject($other);
        $this->report('2026-11-10', 'APPROVED', [[null, '8.888', 'Their own work']], ['Mason' => 60], $theirs);

        $update = $this->draftUpdate();
        $this->assertSame('3', $this->sheet($update)->get('report_count')->value);
        $this->assertSame('23', $this->sheet($update)->get('worker_days')->value);
        $this->assertStringNotContainsString('Terrace', json_encode($update->facts));
        $this->assertStringNotContainsString('Their own', json_encode($update->facts));

        $elsewhere = $this->updates()->create($second, ['week_start' => self::WEEK, 'language' => 'en'], $this->manager);
        $this->assertSame([['n' => 1, 'name' => 'K-01 Terrace waterproofing', 'qty' => '9.999', 'unit' => 'sqm']], app(OwnerUpdateDocument::class)->workLines($this->sheet($elsewhere), 'en'));
    }

    public function test_a_week_with_no_approved_report_says_so_and_cannot_be_approved_on_its_own(): void
    {
        $this->report('2026-11-10', 'IN_REVIEW', [[$this->lines[0], '5.000']], ['Mason' => 9]);
        $update = $this->draftUpdate();

        $this->assertSame('0', $this->sheet($update)->get('report_count')->value);
        $this->assertSame(['opening', 'period', 'reports', 'closing'], array_keys($this->text($update)));
        $this->assertSame('No daily report of that week has been approved yet, so no work is listed here.', $this->text($update)['reports']);

        $this->assertRefused(fn () => $this->updates()->approve($update, $this->updateTicks($update), $this->manager), ['approve']);
        $this->assertSame(OwnerUpdate::DRAFT, $update->fresh()->status);
    }

    public function test_work_rows_beyond_the_limit_are_counted_and_said_not_dropped_silently(): void
    {
        $this->week();
        config(['modules.owner_pack.max_work_lines' => 2]);

        $update = $this->draftUpdate();
        $sheet = $this->sheet($update);

        $this->assertSame(['2', '1'], [$sheet->get('work_count')->value, $sheet->get('work_more')->value]);
        $this->assertCount(2, app(OwnerUpdateDocument::class)->workLines($sheet, 'en'));
        $this->assertSame('Lines of work that are not listed here: 1.', $this->text($update)['work_more']);
    }

    public function test_photos_come_only_from_the_approved_reports_of_the_week(): void
    {
        ['reports' => [$monday, , $thursday], 'photos' => [$footing, $plain, $brickwork]] = $this->week();

        $inReview = $this->photo(Dpr::where('status', 'IN_REVIEW')->sole(), 'Not approved yet');
        $lastWeek = $this->photo(Dpr::whereDate('report_date', '2026-11-06')->sole(), 'Last week');
        $second = $this->makeProject($this->organization);
        $otherProject = $this->photo($this->report('2026-11-10', 'APPROVED', [], [], $second), 'Another site');
        $otherCompany = $this->photo($this->report('2026-11-10', 'APPROVED', [], [], $this->makeProject($this->tenant('Konkan Builders'))), 'Another company');

        foreach ([$inReview, $lastWeek, $otherProject, $otherCompany] as $photo) {
            $this->assertRefused(fn () => $this->draftUpdate(['photo_ids' => [$footing->id, $photo->id]]), ['photo_ids']);
        }

        $this->assertSame(0, OwnerUpdate::withoutGlobalScopes()->count());

        // Picked in any order, they are kept in the order of the reports.
        $update = $this->draftUpdate(['photo_ids' => [$brickwork->id, $footing->id]]);
        $sheet = $this->sheet($update);

        $this->assertSame([$footing->id, $brickwork->id], $update->photoIds());
        $this->assertSame(
            [
                ['n' => 1, 'id' => $footing->id, 'date' => '9 November 2026', 'caption' => 'Footing F2 before concreting'],
                ['n' => 2, 'id' => $brickwork->id, 'date' => '12 November 2026', 'caption' => 'Brickwork on the east wall'],
            ],
            app(OwnerUpdateDocument::class)->photos($sheet, 'en'),
        );
        $this->assertSame('Photos with this update: 2. Each is shown with the date of the daily report it belongs to.', $this->text($update)['photos']);

        // A photo with no caption has a date and nothing else.
        $update = $this->updates()->update($update, ['photo_ids' => [$plain->id]], $this->manager);
        $this->assertSame([['n' => 1, 'id' => $plain->id, 'date' => '9 November 2026', 'caption' => null]], app(OwnerUpdateDocument::class)->photos($this->sheet($update), 'en'));

        // Taking the last photo off, and changing the week, leave no photo of the old week behind.
        $update = $this->updates()->update($update, ['photo_ids' => null], $this->manager);
        $this->assertSame([], $update->photoIds());
        $this->assertFalse($this->sheet($update)->has('photo_count'));

        $update = $this->updates()->update($update, ['photo_ids' => [$footing->id, $brickwork->id]], $this->manager);
        $update = $this->updates()->update($update, ['week_start' => '2026-11-11'], $this->manager);
        $this->assertSame([$brickwork->id], $update->photoIds());
        $this->assertSame([$thursday->id], [$brickwork->dpr_id]);
        $this->assertNotSame($monday->id, $thursday->id);

        // The page sends the photos it showed ticked along with a new week. Those of the new week stay and
        // the rest go with the old week; with the week unchanged, a photo of another week is still refused.
        $update = $this->updates()->update($update, ['week_start' => self::WEEK, 'photo_ids' => [$footing->id, $brickwork->id]], $this->manager);
        $update = $this->updates()->update($update, ['week_start' => '2026-11-12', 'photo_ids' => [$footing->id, $brickwork->id]], $this->manager);
        $this->assertSame(['2026-11-12', [$brickwork->id]], [$update->week_start->toDateString(), $update->photoIds()]);
        $this->assertRefused(fn () => $this->updates()->update($update, ['week_start' => '2026-11-12', 'photo_ids' => [$footing->id, $brickwork->id]], $this->manager), ['photo_ids']);
        $this->assertRefused(fn () => $this->updates()->update($update, ['photo_ids' => [$footing->id]], $this->manager), ['photo_ids']);
    }

    public function test_the_percentage_is_printed_only_when_work_has_been_measured_and_approved(): void
    {
        $this->week();
        $update = $this->draftUpdate();

        $this->assertArrayNotHasKey('progress', $this->text($update));
        $this->assertStringNotContainsString('%', implode(' ', $this->text($update)));

        // 40 sqm of brickwork measured and approved: 38,000.00 of a BOQ worth 1,46,834.58.
        $this->actingAs($this->owner);
        $this->approveEntry($this->draftEntry($this->project, $this->lines[1], $this->owner, ['nos' => '1', 'length' => '40', 'breadth' => null, 'depth' => null]), $this->owner, $this->owner);

        $this->assertFalse($this->updates()->isCurrent($update->fresh()));
        $update = $this->updates()->update($update, [], $this->manager);
        $this->assertTrue($this->updates()->isCurrent($update));

        $this->assertSame('25.88', $this->sheet($update)->get('measured_pct')->value);
        $this->assertSame('Work measured and approved so far: 25.88% of the value of the bill of quantities, as on 14 November 2026.', $this->text($update)['progress']);
        $this->assertSame('This share is worked out from the quantities measured and approved, at the rates of the bill of quantities. It is not judged from photos.', $this->text($update)['progress_basis']);
    }

    public function test_the_percentage_comes_from_work_progress_and_from_nowhere_else(): void
    {
        $this->week();

        // Whatever WorkProgress answers is what the update states. Progress entries and reported quantities are not turned into a percentage here.
        $this->mock(WorkProgress::class, fn ($mock) => $mock->shouldReceive('measuredPercent')->once()->withArgs(fn ($project) => $project->is($this->project))->andReturn('12.34'));
        $update = $this->draftUpdate();

        $this->assertSame('12.34', $this->sheet($update)->get('measured_pct')->value);
        $this->assertSame('computed', $this->sheet($update)->get('measured_pct')->source->type);
        $this->assertStringContainsString('12.34%', $this->text($update)['progress']);

        $this->mock(WorkProgress::class, fn ($mock) => $mock->shouldReceive('measuredPercent')->once()->andReturn(null));
        $update = $this->updates()->update($update, [], $this->manager);

        $this->assertFalse($this->sheet($update)->has('measured_pct'));
        $this->assertStringNotContainsString('%', implode(' ', $this->text($update)));
    }

    public function test_preparing_approving_and_sharing_send_nothing_to_anyone(): void
    {
        Notification::fake();
        Mail::fake();
        Queue::fake();
        Http::fake();

        ['photos' => [$footing]] = $this->week();
        $quotation = $this->quotationWithAStageReached();
        $update = $this->draftUpdate(['photo_ids' => [$footing->id], 'show_money' => true, 'quotation_id' => $quotation->id, 'received_amount' => '40000']);
        $update = $this->updates()->update($update, ['next_week_text' => 'Plinth beam'], $this->manager);
        [$shared, $url] = $this->sharedUpdate($this->approvedUpdate($update));
        app(OwnerUpdateShareService::class)->revoke($shared, $this->manager);

        // The contractor sends the link himself: the app has written rows and nothing else.
        Notification::assertNothingSent();
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertStringContainsString('/shared/owner-updates/', $url);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_the_payment_position_is_worked_out_to_the_paisa_from_the_stages_reached_and_the_typed_receipt(): void
    {
        $this->week();
        // Total 1,57,847.17. Stages: 47,354.15, 52,615.67 and 57,877.35. The first was reached on the 10th.
        $quotation = $this->quotationWithAStageReached();
        $money = ['show_money' => true, 'quotation_id' => $quotation->id];

        $update = $this->draftUpdate([...$money, 'received_amount' => '40000']);
        $sheet = $this->sheet($update);

        $this->assertSame(
            ['157847.17', '1', '47354.15', '40000.00', '7354.15'],
            array_map(fn (string $key) => $sheet->get($key)->value, ['pay_total', 'pay_reached_count', 'pay_reached', 'pay_received', 'pay_due']),
        );
        $this->assertSame('entered', $sheet->get('pay_received')->source->type);
        $this->assertSame([
            'pay_quotation' => 'Payment position on quotation '.$quotation->number.', revision 1, as on 14 November 2026. The total of that quotation is ₹1,57,847.17.',
            'pay_stage_1' => 'On agreement: ₹47,354.15, reached on 10 November 2026.',
            'pay_reached' => 'The stages reached so far add up to ₹47,354.15.',
            'pay_received' => 'Received so far, as entered by the contractor: ₹40,000.00.',
            'pay_due' => 'Due on the stages reached, after what was received: ₹7,354.15.',
        ], array_filter($this->text($update), fn (string $id) => str_starts_with($id, 'pay_'), ARRAY_FILTER_USE_KEY));

        // A second stage is reached: 47,354.15 + 52,615.67 = 99,969.82. Received exactly that: nothing is due.
        $this->quotations()->markReached($quotation->stages()->get()[1], ['reached' => true, 'reached_on' => '2026-11-13'], $this->manager);
        $update = $this->updates()->update($update, ['received_amount' => '99969.82'], $this->manager);
        $sheet = $this->sheet($update);

        $this->assertSame(['2', '99969.82'], [$sheet->get('pay_reached_count')->value, $sheet->get('pay_reached')->value]);
        $this->assertFalse($sheet->has('pay_due') || $sheet->has('pay_ahead'));
        $this->assertSame('Nothing is due on the stages reached so far.', $this->text($update)['pay_due']);
        $this->assertSame('On completion of plinth: ₹52,615.67, reached on 13 November 2026.', $this->text($update)['pay_stage_2']);

        // More received than the stages reached is said as such, never as a minus.
        $update = $this->updates()->update($update, ['received_amount' => '120000.50'], $this->manager);
        $this->assertSame('20030.68', $this->sheet($update)->get('pay_ahead')->value);
        $this->assertSame('What was received is more than the stages reached so far by ₹20,030.68.', $this->text($update)['pay_due']);
        $this->assertStringNotContainsString('-', $this->text($update)['pay_due']);

        // Nothing typed: the update says nothing about what is due, and assumes no receipt.
        $update = $this->updates()->update($update, ['received_amount' => null], $this->manager);
        $this->assertFalse($this->sheet($update)->has('pay_received'));
        $this->assertArrayNotHasKey('pay_due', $this->text($update));
        $this->assertArrayNotHasKey('pay_received', $this->text($update));

        // Zero typed is a receipt of nothing, which is not the same as nothing typed.
        $update = $this->updates()->update($update, ['received_amount' => '0'], $this->manager);
        $this->assertSame(['0.00', '99969.82'], [$this->sheet($update)->get('pay_received')->value, $this->sheet($update)->get('pay_due')->value]);

        // Switching the payment position off takes every amount out of the update.
        $update = $this->updates()->update($update, ['show_money' => false], $this->manager);
        $this->assertSame([null, null, false], [$update->quotation_id, $update->received_amount, $update->show_money]);
        $this->assertFalse($this->sheet($update)->hasKind('money'));
        $this->assertStringNotContainsString('₹', implode(' ', $this->text($update)));
    }

    public function test_the_payment_position_needs_an_issued_quotation_of_the_project_that_still_stands(): void
    {
        $this->week();
        $issued = $this->issued();
        $draft = $this->draft(['client_name' => 'Second Client']);
        $second = $this->makeProject($this->organization);
        $elsewhere = $this->issued($this->quotations()->create($second, ['client_name' => 'Elsewhere', 'language' => 'en', 'boq_item_ids' => [$this->line($second, 'K-01', 'Terrace waterproofing', 'sqm', '10.000', '500.00')->id]], $this->manager));

        foreach ([null, $draft->id, $elsewhere->id, 999999] as $id) {
            $this->assertRefused(fn () => $this->draftUpdate(['show_money' => true, 'quotation_id' => $id]), ['quotation_id']);
        }

        // With no stage reached the update says so and states no amount as reached or due.
        $update = $this->draftUpdate(['show_money' => true, 'quotation_id' => $issued->id]);

        // On a draft, a quotation sent empty is refused, and the one chosen before stays when the field is not sent at all.
        $this->assertRefused(fn () => $this->updates()->update($update, ['show_money' => true, 'quotation_id' => null], $this->manager), ['quotation_id']);
        $this->assertRefused(fn () => $this->updates()->update($update, ['quotation_id' => $draft->id], $this->manager), ['quotation_id']);
        $this->assertSame($issued->id, $this->updates()->update($update, ['show_money' => true, 'received_amount' => '10'], $this->manager)->quotation_id);
        $update = $this->updates()->update($update, ['received_amount' => null], $this->manager);
        $this->assertSame('No payment stage has been reached yet.', $this->text($update)['pay_reached']);
        $this->assertSame('0', $this->sheet($update)->get('pay_reached_count')->value);

        // A later issued revision replaces the quotation: the old one can no longer be chosen, and the draft made on it cannot be approved.
        $revision = $this->issued($this->quotations()->revise($issued, $this->manager));
        $this->assertRefused(fn () => $this->draftUpdate(['show_money' => true, 'quotation_id' => $issued->id]), ['quotation_id']);
        $refusal = $this->assertRefused(fn () => $this->updates()->approve($update, $this->updateTicks($update), $this->manager), ['facts']);
        $this->assertStringContainsString('replaced by revision 2', $refusal->errors()['facts'][0]);

        $this->assertSame([$revision->id], $this->updates()->standingQuotations($this->organization->id, $this->project->id)->pluck('id')->all());
        $this->assertSame(OwnerUpdate::APPROVED, $this->approvedUpdate($this->draftUpdate(['show_money' => true, 'quotation_id' => $revision->id]))->status);
    }

    public function test_a_person_without_money_view_prepares_no_payment_position_and_approves_nothing(): void
    {
        $this->week();
        $quotation = $this->quotationWithAStageReached();

        try {
            $this->draftUpdate(['show_money' => true, 'quotation_id' => $quotation->id], $this->engineer);
            $this->fail('An engineer asked for the payment position.');
        } catch (AuthorizationException) {
            $this->assertSame(0, OwnerUpdate::withoutGlobalScopes()->count());
        }

        // Without the switch, a quotation and a receipt sent along are not kept.
        $plain = $this->draftUpdate(['quotation_id' => $quotation->id, 'received_amount' => '500'], $this->engineer);
        $this->assertSame([false, null, null, $this->engineer->id], [$plain->show_money, $plain->quotation_id, $plain->received_amount, $plain->created_by]);
        $this->assertFalse($this->sheet($plain)->hasKind('money'));

        // The engineer changes their own draft, but cannot switch money on, and cannot approve.
        $this->updates()->update($plain, ['next_week_text' => 'Slab shuttering starts'], $this->engineer);

        foreach ([
            fn () => $this->updates()->update($plain, ['show_money' => true, 'quotation_id' => $quotation->id], $this->engineer),
            fn () => $this->updates()->approve($plain, $this->updateTicks($plain), $this->engineer),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An engineer was let through.');
            } catch (AuthorizationException) {
                // Refused.
            }
        }

        $this->assertSame([OwnerUpdate::DRAFT, false], [$plain->fresh()->status, $plain->fresh()->show_money]);

        // An update that states the payment position is closed to them altogether.
        $withMoney = $this->draftUpdate(['show_money' => true, 'quotation_id' => $quotation->id, 'received_amount' => '100']);
        $before = $withMoney->fresh()->getAttributes();

        try {
            $this->updates()->update($withMoney, ['next_week_text' => 'Changed by the engineer', 'show_money' => false], $this->engineer);
            $this->fail('An engineer changed an update that states money.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $withMoney->fresh()->getAttributes());
        }
    }

    public function test_approval_needs_a_tick_for_every_figure_every_work_row_every_photo_and_every_typed_number(): void
    {
        ['photos' => [$footing]] = $this->week();
        $update = $this->draftUpdate(['photo_ids' => [$footing->id], 'next_week_text' => 'We plan to cast 2 footings and start the plinth beam.']);
        $all = $this->updateTicks($update);

        $this->assertSame(
            ['week_start', 'week_end', 'report_count', 'work_count', 'worker_days', 'photo_count', 'typed:next_week:1:2', 'work_1', 'work_2', 'work_3', 'photo_1'],
            $all['number_ticks'],
        );

        foreach (['week_end' => 'Last day of the week', 'worker_days' => 'Daily worker counts added up', 'work_2' => 'Work row 2', 'photo_1' => 'Photo 1', 'typed:next_week:1:2' => '2 (typed by you, not from the records)'] as $left => $named) {
            $refusal = $this->assertRefused(fn () => $this->updates()->approve($update, [...$all, 'number_ticks' => array_values(array_diff($all['number_ticks'], [$left]))], $this->manager), ['number_ticks']);

            $this->assertStringContainsString($named, $refusal->errors()['number_ticks'][0]);
            $this->assertSame(OwnerUpdate::DRAFT, $update->fresh()->status);
        }

        $this->assertRefused(fn () => $this->updates()->approve($update, [...$all, 'number_ticks' => []], $this->manager), ['number_ticks']);

        $approved = $this->updates()->approve($update, $all, $this->manager)->fresh();

        $this->assertSame([OwnerUpdate::APPROVED, $this->manager->id, '2026-11-14'], [$approved->status, $approved->approved_by, $approved->approved_at->toDateString()]);
        $this->assertSame($all['number_ticks'], $approved->number_ticks);
        $this->assertSame([['id' => 'typed:next_week:1:2', 'slot' => 'next_week', 'text' => '2', 'context' => 'We plan to cast 2 footings and start the']], $approved->typed_numbers);
        $this->assertNull($approved->share_token);

        // The text as it was approved is kept, slot by slot.
        $body = array_column($approved->body_rendered, 'text', 'id');
        $this->assertSame(['opening', 'period', 'reports', 'work', 'workers', 'photos', 'next_week_intro', 'next_week', 'closing'], array_keys($body));
        $this->assertSame('Planned for next week, as written by the contractor:', $body['next_week_intro']);
        $this->assertSame('We plan to cast 2 footings and start the plinth beam.', $body['next_week']);

        // Who ticked the typed number is on record, and so is the approval.
        $typed = AuditLog::withoutGlobalScopes()->where('action', 'wording.typed_numbers_ticked')->sole();
        $this->assertSame([$this->manager->id, $approved->id, 'owner_update'], [$typed->user_id, $typed->auditable_id, $typed->new_values['document_type']]);
        $this->assertSame('2', $typed->new_values['numbers'][0]['text']);
        $this->assertSame(11, AuditLog::withoutGlobalScopes()->where('action', 'owner_update.approved')->sole()->new_values['ticks']);

        // A second press changes nothing and writes nothing more.
        $again = $this->updates()->approve($approved, ['fact_fingerprint' => str_repeat('0', 64), 'number_ticks' => []], $this->owner);
        $this->assertSame([$this->manager->id, $approved->approved_at->toIso8601String()], [$again->approved_by, $again->approved_at->toIso8601String()]);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'owner_update.approved')->count());
    }

    public function test_approval_is_refused_when_the_text_changed_but_not_the_figures(): void
    {
        $this->week();
        $update = $this->draftUpdate();
        $seen = $this->updateTicks($update);

        // While the manager is ticking, the engineer saves the same draft in Marathi with a plan for next week
        // that carries no figure. The figures, and so the ticks, are the same as on the manager's page.
        $this->updates()->update($update, ['language' => 'mr', 'next_week_text' => 'Plinth beam and backfilling.'], $this->engineer);
        $this->assertSame([$seen['fact_fingerprint'], $seen['number_ticks']], [$this->updateTicks($update)['fact_fingerprint'], $this->updateTicks($update)['number_ticks']]);

        $refusal = $this->assertRefused(fn () => $this->updates()->approve($update, $seen, $this->manager), ['facts']);
        $this->assertStringContainsString('changed after the page was opened', $refusal->errors()['facts'][0]);
        $this->assertSame([OwnerUpdate::DRAFT, null], [$update->fresh()->status, $update->fresh()->body_rendered]);

        // Read again, it is approved, and what is kept is the text the manager then read.
        $approved = $this->updates()->approve($update, $this->updateTicks($update), $this->manager)->fresh();
        $this->assertSame('mr', $approved->language);
        $this->assertSame('Plinth beam and backfilling.', array_column($approved->body_rendered, 'text', 'id')['next_week']);
    }

    public function test_approval_is_refused_when_the_records_changed_after_the_draft_was_prepared(): void
    {
        ['reports' => [$monday]] = $this->week();
        $update = $this->draftUpdate();
        $ticks = $this->updateTicks($update);

        // A report of the week is approved after the draft was read.
        Dpr::where('status', 'IN_REVIEW')->sole()->update(['status' => 'APPROVED']);

        $refusal = $this->assertRefused(fn () => $this->updates()->approve($update, $ticks, $this->manager), ['facts']);
        $this->assertStringContainsString('Save the draft to read them again', $refusal->errors()['facts'][0]);
        $this->assertSame(OwnerUpdate::DRAFT, $update->fresh()->status);
        $this->assertFalse($this->updates()->isCurrent($update->fresh()));

        // Saving reads the records again. Ticks given for the old figures do not carry over.
        $update = $this->updates()->update($update, [], $this->manager);
        $this->assertSame('4', $this->sheet($update)->get('report_count')->value);
        $this->assertSame('63', $this->sheet($update)->get('worker_days')->value);
        $this->assertRefused(fn () => $this->updates()->approve($update, $ticks, $this->manager), ['facts']);

        // A quantity written into an approved report underneath the app is caught the same way.
        $fresh = $this->updateTicks($update);
        DprWorkItem::withoutGlobalScopes()->where('dpr_id', $monday->id)->where('qty', '4.250')->update(['qty' => '4.750']);
        $this->assertRefused(fn () => $this->updates()->approve($update, $fresh, $this->manager), ['facts']);

        $update = $this->updates()->update($update, [], $this->manager);
        $this->assertSame(OwnerUpdate::APPROVED, $this->updates()->approve($update, $this->updateTicks($update), $this->manager)->status);
    }

    public function test_the_date_the_figures_were_read_does_not_move_until_the_draft_is_saved_again(): void
    {
        $this->week();
        $update = $this->draftUpdate();

        // Two days later nothing has changed in the records: the draft still stands, with its own date.
        $this->travelTo(now()->addDays(2));
        $this->assertTrue($this->updates()->isCurrent($update->fresh()));
        $this->assertSame('2026-11-14', $this->sheet($update)->get('as_on')->value);

        $approved = $this->approvedUpdate($update);
        $this->assertSame(['2026-11-14', '2026-11-16'], [$approved->prepared_on->toDateString(), $approved->approved_at->toDateString()]);

        // A draft saved on the later day is read on that day.
        $later = $this->updates()->update($this->draftUpdate(), [], $this->manager);
        $this->assertSame('2026-11-16', $this->sheet($later)->get('as_on')->value);
    }

    public function test_an_approved_update_never_changes(): void
    {
        $this->week();
        $approved = $this->approvedUpdate();
        $before = $approved->fresh()->getAttributes();

        foreach ([
            fn () => $this->updates()->update($approved, ['next_week_text' => 'Changed after approval'], $this->manager),
            fn () => $approved->fresh()->update(['next_week_text' => 'Changed underneath']),
            fn () => $approved->fresh()->update(['facts' => []]),
            fn () => $approved->fresh()->update(['status' => OwnerUpdate::DRAFT]),
            fn () => $approved->fresh()->update(['approved_by' => $this->owner->id]),
            fn () => $approved->fresh()->delete(),
        ] as $change) {
            try {
                $change();
                $this->fail('An approved update was changed.');
            } catch (ImmutableRecordException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }

        $this->assertSame($before, $approved->fresh()->getAttributes());

        // Once shared it cannot go back to approved or to draft either. Only the link itself may change.
        [$shared] = $this->sharedUpdate($approved);

        foreach ([OwnerUpdate::APPROVED, OwnerUpdate::DRAFT] as $status) {
            try {
                $shared->fresh()->update(['status' => $status]);
                $this->fail('A shared update went back.');
            } catch (ImmutableRecordException) {
                $this->assertSame(OwnerUpdate::SHARED, $shared->fresh()->status);
            }
        }

        // A draft stays free to change and to delete.
        $draft = $this->draftUpdate();
        $draft->update(['next_week_text' => 'Still a draft']);
        $this->assertTrue($draft->delete());
    }

    public function test_the_opening_and_closing_can_be_rewritten_and_the_code_sentence_follows_the_language(): void
    {
        $this->week();
        $update = $this->draftUpdate();

        $update = $this->updates()->update($update, ['opening' => 'Namaste. This is how the week went.', 'closing' => ''], $this->manager);
        $this->assertSame(['opening' => ['text' => 'Namaste. This is how the week went.', 'source' => 'edited']], $update->slots);
        $this->assertSame('Namaste. This is how the week went.', $this->text($update)['opening']);

        // Sent back unchanged, the code's own closing is not stored, so it changes with the language.
        $update = $this->updates()->update($update, ['language' => 'mr', 'closing' => 'Please tell us if you would like anything explained.'], $this->manager);
        $this->assertSame(['opening'], array_keys($update->slots));
        $this->assertSame('कोणत्याही गोष्टीबद्दल माहिती हवी असल्यास आम्हाला कळवा.', $this->text($update)['closing']);
        $this->assertSame('त्या आठवड्यातील मंजूर दैनिक अहवाल: 3.', $this->text($update)['reports']);
        $this->assertSame('हे अपडेट 9 नोव्हेंबर 2026 ते 15 नोव्हेंबर 2026 या आठवड्याचे आहे.', $this->text($update)['period']);

        // Emptied, the opening goes back to the code's sentence too.
        $update = $this->updates()->update($update, ['opening' => '  ', 'language' => 'hi'], $this->manager);
        $this->assertNull($update->slots);
        $this->assertSame('आपकी साइट पर हुए काम का अपडेट यहाँ दिया गया है।', $this->text($update)['opening']);
        $this->assertSame('इन रिपोर्टों में रोज़ की मज़दूर संख्या का जोड़ 23 है।', $this->text($update)['workers']);

        // A model's text kept under "next_week" in the slots column is never used: that slot is the contractor's own.
        DB::table('owner_updates')->where('id', $update->id)->update(['slots' => json_encode(['next_week' => ['text' => 'Written by nobody', 'source' => 'ai']])]);
        $this->assertArrayNotHasKey('next_week', $this->text($update));
        $this->assertStringNotContainsString('Written by nobody', implode(' ', $this->text($update)));
    }

    public function test_a_repeated_client_uuid_returns_the_first_draft(): void
    {
        $this->week();
        $uuid = '5f0c8a1e-2b7d-4f6a-9c3e-1d2a4b6c8e0f';

        $first = $this->draftUpdate(['client_uuid' => $uuid]);
        $second = $this->draftUpdate(['client_uuid' => $uuid, 'week_start' => '2026-11-02', 'language' => 'hi']);

        $this->assertTrue($first->is($second));
        $this->assertSame(['2026-11-09', 'en'], [$second->week_start->toDateString(), $second->language]);
        $this->assertSame(1, OwnerUpdate::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'owner_update.created')->count());

        // Another company may use the same key for a row of its own.
        $other = $this->tenant('Konkan Builders');
        $theirs = $this->updates()->create($this->makeProject($other), ['client_uuid' => $uuid, 'week_start' => self::WEEK, 'language' => 'en'], $this->member($other, 'owner'));
        $this->assertFalse($theirs->is($first));
        $this->assertSame($other->id, $theirs->organization_id);

        // Two updates for the same week are allowed: one in each language, or one prepared again.
        $this->assertFalse($this->draftUpdate(['language' => 'mr'])->is($first));
    }
}
