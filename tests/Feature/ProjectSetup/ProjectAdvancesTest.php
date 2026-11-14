<?php

namespace Tests\Feature\ProjectSetup;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectAdvance;
use App\Services\ProjectService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The mobilisation advance as several lines on the website's project form, and the advance recovery
 * percentage worked out from it. Every name, date and figure here is made up for the test.
 */
class ProjectAdvancesTest extends ProjectSetupTestCase
{
    /** The form as the page sends it: advance lines, never a separate advance figure. */
    private function fields(array $overrides = []): array
    {
        return [
            'code' => 'NW1', 'name' => 'Nirmal Heights', 'contract_value' => '3000000',
            'retention_pct' => '5', 'tds_pct' => '2', 'gst_pct' => '18',
            'advances' => [['amount' => '100000', 'received_on' => '2026-11-02', 'note' => 'RTGS 4471']],
            'advance_recovery_auto' => '0', 'advance_recovery_pct' => '0', 'measurement_tolerance_pct' => '5',
            'deduction_basis' => 'gross', 'deduction_order' => 'retention,advance,material,tds',
            ...$overrides,
        ];
    }

    /** The edit form for the shared project, with its saved values. */
    private function editFields(array $overrides = []): array
    {
        $project = $this->project->refresh();

        return $this->fields(['code' => $project->code, 'name' => $project->name, 'status' => $project->status, 'contract_value' => $project->contract_value, ...$overrides]);
    }

    private function lines(?Project $project = null): array
    {
        return ProjectAdvance::withoutGlobalScopes()->where('project_id', ($project ?? $this->project)->id)->orderBy('sort')->get()
            ->map(fn (ProjectAdvance $line) => [$line->amount, $line->received_on?->toDateString(), $line->note, $line->sort])->all();
    }

    // Creating and editing

    public function test_a_new_project_is_made_with_several_advances_and_their_sum(): void
    {
        $this->actingAs($this->pm)->post(route('projects.store'), $this->fields([
            'advances' => [
                ['amount' => '60000', 'received_on' => '2026-11-02', 'note' => '  RTGS 4471  '],
                ['amount' => '40000.50', 'received_on' => '2026-12-01', 'note' => ''],
            ],
            // Sent beside the lines, a figure of its own is ignored: the advance is their sum.
            'advance_amount' => '5',
            'advance_recovery_pct' => '7.5',
        ]))->assertSessionHasNoErrors();

        $project = Project::where('code', 'NW1')->firstOrFail();
        $this->assertSame('100000.50', $project->advance_amount);
        $this->assertSame('7.50', $project->advance_recovery_pct);
        $this->assertFalse($project->advance_recovery_auto);
        $this->assertSame([['60000.00', '2026-11-02', 'RTGS 4471', 0], ['40000.50', '2026-12-01', null, 1]], $this->lines($project));
        $this->assertSame([$this->org->id], ProjectAdvance::withoutGlobalScopes()->pluck('organization_id')->unique()->values()->all());

        // The audit says what changed and who did it, but never quotes a note.
        $audit = AuditLog::where('action', 'project.advances_changed')->sole();
        $this->assertSame($this->pm->id, $audit->user_id);
        $this->assertNull($audit->old_values);
        $this->assertSame([
            ['amount' => '60000.00', 'received_on' => '2026-11-02', 'has_note' => true],
            ['amount' => '40000.50', 'received_on' => '2026-12-01', 'has_note' => false],
        ], $audit->new_values['advances']);
        $this->assertStringNotContainsString('RTGS', json_encode($audit->new_values));
    }

    public function test_editing_replaces_the_lines_and_keeps_the_sum(): void
    {
        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->editFields([
            'advances' => [['amount' => '250000', 'received_on' => '2026-11-05', 'note' => 'Cheque 000812']],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('projects.show', $this->project));

        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->editFields([
            'advances' => [
                ['amount' => '250000', 'received_on' => '2026-11-05', 'note' => 'Cheque 000812'],
                ['amount' => '125000.25', 'received_on' => '2027-01-10', 'note' => 'Second instalment'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('375000.25', $this->project->refresh()->advance_amount);
        $this->assertCount(2, $this->lines());

        $audit = AuditLog::where('action', 'project.advances_changed')->latest('id')->first();
        $this->assertCount(1, $audit->old_values['advances']);
        $this->assertCount(2, $audit->new_values['advances']);

        // The total's own change is in the project's audit row.
        $this->assertSame('375000.25', AuditLog::where('action', 'updated')->where('auditable_id', $this->project->id)->latest('id')->first()->new_values['advance_amount']);

        // The same lines again change nothing and write no audit.
        $ids = ProjectAdvance::withoutGlobalScopes()->pluck('id')->all();
        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->editFields([
            'advances' => [
                ['amount' => '250000.00', 'received_on' => '2026-11-05', 'note' => 'Cheque 000812'],
                ['amount' => '125000.25', 'received_on' => '2027-01-10', 'note' => 'Second instalment'],
            ],
        ]))->assertSessionHasNoErrors();
        $this->assertSame($ids, ProjectAdvance::withoutGlobalScopes()->pluck('id')->all());
        $this->assertSame(2, AuditLog::where('action', 'project.advances_changed')->count());
    }

    public function test_lines_with_no_amount_are_left_out(): void
    {
        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->editFields([
            'advances' => [
                ['amount' => '', 'received_on' => 'not a date', 'note' => Str::repeat('x', 300)],
                ['amount' => '80000', 'received_on' => '', 'note' => ''],
                ['amount' => null, 'received_on' => '2026-11-09', 'note' => 'Left blank'],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([['80000.00', null, null, 0]], $this->lines());
        $this->assertSame('80000.00', $this->project->refresh()->advance_amount);

        // Every line blank: no advance at all.
        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->editFields([
            'advances' => [['amount' => '', 'received_on' => '', 'note' => '']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame([], $this->lines());
        $this->assertSame('0.00', $this->project->refresh()->advance_amount);
        $this->assertNull(AuditLog::where('action', 'project.advances_changed')->latest('id')->first()->new_values);
    }

    public function test_each_line_is_checked_and_the_error_is_on_that_line(): void
    {
        $line = fn (array $values) => $this->editFields(['advances' => [['amount' => '50000', 'received_on' => '2026-11-01', 'note' => ''], $values]]);

        foreach ([
            ['advances.1.amount', ['amount' => '-500']],
            ['advances.1.amount', ['amount' => '0']],
            ['advances.1.amount', ['amount' => 'fifty thousand']],
            ['advances.1.amount', ['amount' => '100000000000000']],
            ['advances.1.received_on', ['amount' => '1000', 'received_on' => '2026-02-30']],
            ['advances.1.note', ['amount' => '1000', 'note' => Str::repeat('n', 256)]],
        ] as [$field, $values]) {
            $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $line($values))
                ->assertSessionHasErrors($field)
                ->assertSessionDoesntHaveErrors('advances.0.amount');
        }

        // An amount that rounds to nothing is no advance either.
        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $line(['amount' => '0.004']))
            ->assertSessionHasErrors(['advances.1.amount' => 'An advance must be more than zero.']);

        $this->assertSame([], $this->lines());
        $this->assertSame('0.00', $this->project->refresh()->advance_amount);
    }

    public function test_the_form_shows_a_line_error_beside_the_line_and_keeps_what_was_typed(): void
    {
        $this->actingAs($this->pm)->from(route('projects.edit', $this->project))->patch(route('projects.update', $this->project), $this->editFields([
            'advances' => [['amount' => '50000', 'received_on' => '2026-11-01', 'note' => 'First'], ['amount' => '-7', 'received_on' => '', 'note' => 'Typo']],
        ]))->assertRedirect(route('projects.edit', $this->project));

        $this->actingAs($this->pm)->get(route('projects.edit', $this->project))->assertOk()
            ->assertSeeInOrder(['name="advances[0][amount]" value="50000"', 'name="advances[1][amount]" value="-7"', 'An advance must be more than zero.', 'value="Typo"'], false);
    }

    public function test_a_project_takes_at_most_twenty_lines(): void
    {
        $lines = fn (int $count) => array_fill(0, $count, ['amount' => '1000', 'received_on' => '', 'note' => '']);

        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->editFields(['advances' => $lines(21)]))
            ->assertSessionHasErrors(['advances' => 'A project can have at most 20 advances.']);
        $this->assertSame([], $this->lines());

        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->editFields(['advances' => $lines(20)]))
            ->assertSessionHasNoErrors();
        $this->assertCount(20, $this->lines());
        $this->assertSame('20000.00', $this->project->refresh()->advance_amount);
    }

    // The recovery percentage

    public function test_the_percentage_is_worked_out_from_the_advance_and_the_contract_value(): void
    {
        // Whatever percentage the browser sends is ignored while it is worked out.
        $this->actingAs($this->pm)->post(route('projects.store'), $this->fields(['advance_recovery_auto' => '1', 'advance_recovery_pct' => '55']))
            ->assertSessionHasNoErrors();

        $project = Project::where('code', 'NW1')->firstOrFail();
        $this->assertTrue($project->advance_recovery_auto);
        // 1,00,000 on 30,00,000 is 3.333…, which rounds to 3.33.
        $this->assertSame('3.33', $project->advance_recovery_pct);

        // It follows the advance and the contract value on every save.
        $this->actingAs($this->pm)->patch(route('projects.update', $project), $this->fields([
            'status' => 'active', 'contract_value' => '1200000', 'advance_recovery_auto' => '1', 'advance_recovery_pct' => '1',
            'advances' => [['amount' => '100000', 'received_on' => '', 'note' => ''], ['amount' => '50000', 'received_on' => '', 'note' => '']],
        ]))->assertSessionHasNoErrors();

        $this->assertSame('12.50', $project->refresh()->advance_recovery_pct);
    }

    public function test_the_worked_out_percentage_rounds_half_up_stops_at_100_and_is_0_without_a_value(): void
    {
        $this->assertSame('3.33', ProjectService::recoveryPct('100000', '3000000'));
        $this->assertSame('66.67', ProjectService::recoveryPct('200000', '300000'));
        // Exactly half a hundredth goes up.
        $this->assertSame('0.01', ProjectService::recoveryPct('1', '20000'));
        $this->assertSame('0.00', ProjectService::recoveryPct('1', '20000.01'));
        $this->assertSame('100.00', ProjectService::recoveryPct('500000', '400000'));
        $this->assertSame('0.00', ProjectService::recoveryPct('100000', '0'));
        $this->assertSame('0.00', ProjectService::recoveryPct('0', '3000000'));

        // An advance larger than the contract value holds back the whole of each bill, and no more.
        $this->actingAs($this->pm)->post(route('projects.store'), $this->fields(['contract_value' => '50000', 'advance_recovery_auto' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertSame('100.00', Project::where('code', 'NW1')->firstOrFail()->advance_recovery_pct);

        $this->actingAs($this->pm)->post(route('projects.store'), $this->fields(['code' => 'NW2', 'contract_value' => '0', 'advance_recovery_auto' => '1', 'advance_recovery_pct' => '9']))
            ->assertSessionHasNoErrors();
        $this->assertSame('0.00', Project::where('code', 'NW2')->firstOrFail()->advance_recovery_pct);
    }

    public function test_with_auto_off_the_typed_percentage_is_kept(): void
    {
        $this->actingAs($this->pm)->patch(route('projects.update', $this->project), $this->editFields([
            'advance_recovery_auto' => '0', 'advance_recovery_pct' => '12.5',
            'advances' => [['amount' => '900000', 'received_on' => '', 'note' => '']],
        ]))->assertSessionHasNoErrors();

        $project = $this->project->refresh();
        $this->assertSame('12.50', $project->advance_recovery_pct);
        $this->assertFalse($project->advance_recovery_auto);
    }

    public function test_auto_can_be_turned_on_later_and_off_again(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'OLD', 'contract_value' => '10000000', 'advance_amount' => '250000', 'advance_recovery_pct' => '10']);
        $form = fn (array $overrides) => $this->fields(['code' => 'OLD', 'status' => 'active', 'contract_value' => '10000000', 'advances' => [['amount' => '250000', 'received_on' => '', 'note' => '']], ...$overrides]);

        $this->actingAs($this->pm)->patch(route('projects.update', $project), $form(['advance_recovery_auto' => '1', 'advance_recovery_pct' => '10']))->assertSessionHasNoErrors();
        $this->assertSame('2.50', $project->refresh()->advance_recovery_pct);
        $this->assertTrue($project->advance_recovery_auto);

        // Unticked, the person types their contract's own figure.
        $this->actingAs($this->pm)->patch(route('projects.update', $project), $form(['advance_recovery_auto' => '0', 'advance_recovery_pct' => '10']))->assertSessionHasNoErrors();
        $this->assertSame('10.00', $project->refresh()->advance_recovery_pct);
        $this->assertFalse($project->advance_recovery_auto);
    }

    public function test_a_form_that_sends_no_lines_and_no_tick_behaves_as_before(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'OLD', 'advance_amount' => '250000', 'advance_recovery_pct' => '10']);

        $this->actingAs($this->pm)->patch(route('projects.update', $project), [
            ...Arr::except($this->fields(['code' => 'OLD', 'status' => 'active']), ['advances', 'advance_recovery_auto']),
            'advance_amount' => '300000', 'advance_recovery_pct' => '9',
        ])->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame('300000.00', $project->advance_amount);
        $this->assertSame('9.00', $project->advance_recovery_pct);
        $this->assertFalse($project->advance_recovery_auto);
        $this->assertSame([], $this->lines($project));
        $this->assertSame(0, AuditLog::where('action', 'project.advances_changed')->count());
    }

    // Projects saved before advances came in lines

    public function test_an_existing_single_figure_shows_as_the_first_line_and_keeps_its_percentage(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'OLD', 'name' => 'Old Wing', 'contract_value' => '10000000', 'advance_amount' => '250000', 'advance_recovery_pct' => '10']);

        $this->assertFalse($project->refresh()->advance_recovery_auto);

        $this->actingAs($this->pm)->get(route('projects.edit', $project))->assertOk()
            ->assertSee('name="advances[0][amount]" value="250000.00"', false)
            ->assertSee('name="advances[0][received_on]" value=""', false)
            ->assertDontSee('name="advances[1][amount]"', false)
            ->assertSee('value="10.00" class="input num" required', false)
            ->assertDontSee('class="input num" required readonly', false)
            ->assertDontSee('value="1" class="mt-0.5" checked', false);

        // Saved as the page shows it, nothing is lost and the percentage stays.
        $this->actingAs($this->pm)->patch(route('projects.update', $project), $this->fields([
            'code' => 'OLD', 'name' => 'Old Wing', 'status' => 'active', 'contract_value' => '10000000.00',
            'advances' => [['amount' => '250000.00', 'received_on' => '', 'note' => '']],
            'advance_recovery_auto' => '0', 'advance_recovery_pct' => '10.00',
        ]))->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame('250000.00', $project->advance_amount);
        $this->assertSame('10.00', $project->advance_recovery_pct);
        $this->assertSame([['250000.00', null, null, 0]], $this->lines($project));
    }

    public function test_a_new_project_form_ticks_auto_and_starts_with_one_empty_line(): void
    {
        $this->actingAs($this->pm)->get(route('projects.create'))->assertOk()
            ->assertSee('name="advances[0][amount]" value=""', false)
            ->assertDontSee('name="advances[1][amount]"', false)
            ->assertSee('name="advance_recovery_auto" value="0"', false)
            ->assertSee('name="advance_recovery_auto" value="1" class="mt-0.5" checked', false)
            ->assertSee('Add advance')
            ->assertSee('Work it out from the advance and the contract value')
            ->assertSee('This much of each RA bill is held back until the advance is recovered.');
    }

    public function test_an_edit_form_lists_the_saved_lines_and_their_total(): void
    {
        app(ProjectService::class)->update($this->project, ['advances' => [
            ['amount' => '60000', 'received_on' => '2026-11-02', 'note' => 'RTGS 4471'],
            ['amount' => '40000', 'received_on' => null, 'note' => null],
        ], 'advance_recovery_auto' => true], $this->owner);

        $this->actingAs($this->pm)->get(route('projects.edit', $this->project))->assertOk()
            ->assertSeeInOrder([
                'name="advances[0][amount]" value="60000.00"', 'name="advances[0][received_on]" value="2026-11-02"', 'value="RTGS 4471"',
                'name="advances[1][amount]" value="40000.00"', 'Total advance', '₹1,00,000.00',
            ], false)
            // Worked out: 1,00,000 on 1,00,00,000.
            ->assertSee('value="1.00" class="input num" required readonly', false)
            ->assertSee('name="advance_recovery_auto" value="1" class="mt-0.5" checked', false);
    }

    // The project page

    public function test_the_project_page_lists_the_advances_to_someone_who_sees_money(): void
    {
        app(ProjectService::class)->update($this->project, ['contract_value' => '3000000', 'advance_recovery_auto' => true, 'advances' => [
            ['amount' => '60000', 'received_on' => '2026-11-02', 'note' => 'RTGS 4471'],
            ['amount' => '40000', 'received_on' => null, 'note' => null],
        ]], $this->owner);

        $this->actingAs($this->owner)->get(route('projects.show', $this->project))->assertOk()
            ->assertSeeInOrder(['Mobilisation advance', '02 Nov 2026', 'RTGS 4471', '₹60,000.00', '—', '₹40,000.00', 'Total', '₹1,00,000.00', 'Advance recovery', '3.33%', 'worked out']);

        app(ProjectService::class)->update($this->project, ['advance_recovery_auto' => false, 'advance_recovery_pct' => '5'], $this->owner);
        $this->actingAs($this->owner)->get(route('projects.show', $this->project))->assertOk()
            ->assertSeeInOrder(['Advance recovery', '5.00%', 'set by hand']);
    }

    public function test_the_project_page_hides_the_advances_from_someone_who_cannot_see_money(): void
    {
        app(ProjectService::class)->update($this->project, ['advances' => [['amount' => '60000', 'received_on' => '2026-11-02', 'note' => 'RTGS 4471']]], $this->owner);

        foreach ([$this->engineer, $this->holding(['dashboard.view', 'project.view', 'project.manage'])] as $user) {
            $this->actingAs($user)->get(route('projects.show', $this->project))->assertOk()
                ->assertDontSee('Mobilisation advance')->assertDontSee('RTGS 4471')->assertDontSee('60,000.00');
        }
    }

    public function test_the_project_page_shows_a_single_figure_and_nothing_without_an_advance(): void
    {
        $project = $this->makeProject($this->org, ['code' => 'OLD', 'advance_amount' => '250000', 'advance_recovery_pct' => '10']);

        $this->actingAs($this->owner)->get(route('projects.show', $project))->assertOk()
            ->assertSeeInOrder(['Mobilisation advance', '₹2,50,000.00', 'Total', '₹2,50,000.00', '10.00%', 'set by hand']);

        $this->actingAs($this->owner)->get(route('projects.show', $this->project))->assertOk()->assertDontSee('Mobilisation advance');
    }

    // Who may

    public function test_changing_the_advances_needs_project_manage(): void
    {
        $this->actingAs($this->engineer)->get(route('projects.edit', $this->project))->assertForbidden();
        $this->actingAs($this->engineer)->patch(route('projects.update', $this->project), $this->editFields())->assertForbidden();
        $this->actingAs($this->qs)->patch(route('projects.update', $this->project), $this->editFields())->assertForbidden();

        $this->assertSame([], $this->lines());
        $this->assertSame('0.00', $this->project->refresh()->advance_amount);
    }

    public function test_another_company_cannot_reach_the_project_or_its_advances(): void
    {
        app(ProjectService::class)->update($this->project, ['advances' => [['amount' => '60000', 'received_on' => '2026-11-02', 'note' => 'RTGS 4471']]], $this->owner);
        [, $rivalOwner, $rivalProject] = $this->rival();

        $this->actingAs($rivalOwner)->get(route('projects.edit', $this->project))->assertNotFound();
        $this->actingAs($rivalOwner)->get(route('projects.show', $this->project))->assertNotFound();
        $this->actingAs($rivalOwner)->patch(route('projects.update', $this->project), $this->editFields(['advances' => [['amount' => '1', 'received_on' => '', 'note' => '']]]))->assertNotFound();

        $this->assertSame([['60000.00', '2026-11-02', 'RTGS 4471', 0]], $this->lines());
        $this->assertSame('60000.00', $this->project->refresh()->advance_amount);

        // The rival's own project sees none of them.
        $this->actingAs($rivalOwner)->get(route('projects.edit', $rivalProject))->assertOk()->assertDontSee('RTGS 4471');
        $this->assertSame(0, ProjectAdvance::withoutGlobalScopes()->where('project_id', $rivalProject->id)->count());
    }

    // Texts and schema

    public function test_the_new_texts_have_hindi_and_marathi(): void
    {
        $texts = [
            'Mobilisation advance (₹)', 'Mobilisation advance', 'Add advance', 'Remove this advance', 'Total advance',
            'Amount (₹)', 'Date received', 'Note', 'Amount', 'Total', 'Advance recovery', 'Advance recovery %',
            'For example the cheque or transfer number', 'A project can have at most :max advances.',
            'This much of each RA bill is held back until the advance is recovered.',
            'Work it out from the advance and the contract value', 'worked out', 'set by hand',
            'Enter the amount in rupees.', 'An advance must be more than zero.', 'This amount is more than a project can hold.',
            'Enter the date the advance came in.', 'Keep the note to :max characters.',
            "This is the total of the project's advances. Change the advances to change it.",
            'The advances add up to more than a project can hold.',
        ];

        foreach (['hi', 'mr'] as $locale) {
            foreach ($texts as $text) {
                $this->assertNotSame($text, __($text, [], $locale), "{$text} has no {$locale} text");
            }
        }

        $this->pm->update(['locale' => 'hi']);
        $this->actingAs($this->pm)->get(route('projects.create'))->assertOk()->assertSee('अग्रिम जोड़ें');
        $this->pm->update(['locale' => 'mr']);
        $this->actingAs($this->pm)->get(route('projects.create'))->assertOk()->assertSee('आगाऊ रक्कम जोडा');
    }

    public function test_the_migrations_roll_back_and_run_again(): void
    {
        $lines = require database_path('migrations/2027_01_19_100001_create_project_advances_table.php');
        $auto = require database_path('migrations/2027_01_19_100002_add_advance_recovery_auto_to_projects.php');

        $auto->down();
        $lines->down();
        $this->assertFalse(Schema::hasTable('project_advances'));
        $this->assertFalse(Schema::hasColumn('projects', 'advance_recovery_auto'));

        $lines->up();
        $auto->up();
        $this->assertSame(
            ['id', 'organization_id', 'project_id', 'amount', 'received_on', 'note', 'sort', 'created_at', 'updated_at'],
            Schema::getColumnListing('project_advances'),
        );
        $this->assertTrue(Schema::hasColumn('projects', 'advance_recovery_auto'));

        // A project that was there before is not worked out.
        $this->assertFalse($this->project->refresh()->advance_recovery_auto);
    }
}
