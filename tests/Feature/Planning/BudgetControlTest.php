<?php

namespace Tests\Feature\Planning;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\PlanBaseline;
use App\Models\PlanBudgetLine;
use App\Models\PlanCostEntry;
use App\Models\User;
use App\Services\Planning\BudgetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Planning's budget belongs to the owner and the project manager (money.overview), and a cost entry is
 * never deleted: a mistake is cancelled by a reversal that stays on record next to it.
 */
class BudgetControlTest extends TestCase
{
    use BuildsPlan, RefreshDatabase;

    private User $manager;

    private User $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpPlan();
        $this->manager = $this->member($this->organization, 'pm');
        $this->estimator = $this->member($this->organization, 'qs');
    }

    private function url(string $suffix = ''): string
    {
        return '/projects/'.$this->project->id.'/planning'.$suffix;
    }

    private function cost(string $amount = '2500.00', string $head = 'material'): PlanCostEntry
    {
        return app(BudgetService::class)->addCost($this->project, ['head' => $head, 'entry_date' => '2026-11-01', 'amount' => $amount, 'description' => 'Shuttering hire'], $this->manager);
    }

    private function actualFor(string $head): string
    {
        return collect(app(BudgetService::class)->report($this->project)['rows'])->firstWhere('head', $head)['actual'];
    }

    public function test_the_estimator_keeps_the_schedule_but_cannot_touch_the_budget_baseline_or_costs(): void
    {
        $entry = $this->cost();
        $this->actingAs($this->estimator);

        // Planning work without money still works.
        $this->post($this->url('/wbs'), ['code' => '1', 'name' => 'Structure'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post($this->url('/alerts/scan'))->assertRedirect();

        $this->get($this->url('/budget'))->assertForbidden();
        $this->post($this->url('/budget'), ['head' => 'material', 'amount' => '999'])->assertForbidden();
        $this->post($this->url('/costs'), ['head' => 'material', 'entry_date' => '2026-11-01', 'amount' => '10', 'client_uuid' => (string) Str::uuid()])->assertForbidden();
        $this->post($this->url('/baseline'))->assertForbidden();
        $this->post(route('planning.costs.reverse', $entry), ['reason' => 'Entered twice by mistake'])->assertForbidden();

        $this->assertSame(0, PlanBudgetLine::count());
        $this->assertSame(1, PlanCostEntry::count());
        $this->assertSame(0, PlanBaseline::count());

        // The overview offers neither the budget nor the baseline button.
        $this->get($this->url())->assertOk()->assertDontSee('Save baseline')->assertDontSee('Open budget')->assertDontSee(route('planning.budget', $this->project));
    }

    public function test_the_accountant_and_engineer_cannot_write_the_budget_either(): void
    {
        foreach (['accounts', 'engineer', 'viewer'] as $role) {
            $this->actingAs($this->member($this->organization, $role));

            $this->post($this->url('/budget'), ['head' => 'material', 'amount' => '999'])->assertForbidden();
            $this->post($this->url('/baseline'))->assertForbidden();
        }

        $this->assertSame(0, PlanBudgetLine::count());
    }

    public function test_the_project_manager_sets_the_budget_records_costs_and_takes_the_baseline(): void
    {
        $this->actingAs($this->manager);

        $this->get($this->url())->assertOk()->assertSee('Save baseline')->assertSee('Open budget');
        $this->post($this->url('/budget'), ['head' => 'material', 'amount' => '50000'])->assertSessionHasNoErrors();
        $this->post($this->url('/costs'), ['head' => 'material', 'entry_date' => '2026-11-01', 'amount' => '1200', 'client_uuid' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->post($this->url('/baseline'))->assertSessionHasNoErrors();

        $this->assertSame('50000.00', PlanBudgetLine::sole()->baseline_amount);
        $this->assertSame('1200.00', $this->actualFor('material'));
    }

    public function test_a_reversal_cancels_a_cost_and_keeps_both_entries(): void
    {
        $entry = $this->cost('2500.00');
        $this->assertSame('2500.00', $this->actualFor('material'));

        $this->actingAs($this->manager)
            ->post(route('planning.costs.reverse', $entry), ['reason' => 'Booked against the wrong project'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reversal = PlanCostEntry::where('reversal_of_id', $entry->id)->sole();
        $this->assertSame('-2500.00', $reversal->amount);
        $this->assertSame('material', $reversal->head);
        $this->assertSame('Booked against the wrong project', $reversal->reversal_reason);
        $this->assertSame($this->manager->id, $reversal->created_by);
        $this->assertTrue($entry->fresh()->is($entry));
        $this->assertSame('2500.00', $entry->fresh()->amount);

        // The two net to zero in every total.
        $this->assertSame('0.00', $this->actualFor('material'));
        $this->assertSame('0.00', app(BudgetService::class)->report($this->project)['totals']['actual']);

        $log = AuditLog::where('action', 'planning.cost_reversed')->sole();
        $this->assertSame($entry->id, $log->auditable_id);
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertSame('Booked against the wrong project', $log->new_values['reason']);
        $this->assertSame($reversal->id, $log->new_values['reversal_id']);

        $this->get($this->url('/budget'))->assertOk()
            ->assertSee('Reversed on')
            ->assertSee('Booked against the wrong project')
            ->assertSee(inr('-2500.00'));
    }

    public function test_a_cost_is_reversed_once_and_a_reversal_is_never_reversed(): void
    {
        $entry = $this->cost();
        $service = app(BudgetService::class);
        $reversal = $service->reverseCost($entry, 'Duplicate of the hire bill', $this->manager);

        foreach ([$entry, $reversal] as $target) {
            try {
                $service->reverseCost($target, 'Again', $this->manager);
                $this->fail('A second reversal was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('reason', $exception->errors());
            }
        }

        $this->actingAs($this->manager)->post(route('planning.costs.reverse', $entry), ['reason' => 'Again'])->assertSessionHasErrors('reason');
        $this->assertSame(2, PlanCostEntry::count());
    }

    public function test_a_reversal_needs_a_reason(): void
    {
        $entry = $this->cost();

        $this->actingAs($this->manager)->post(route('planning.costs.reverse', $entry), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('planning.costs.reverse', $entry), ['reason' => str_repeat('x', 501)])->assertSessionHasErrors('reason');
        $this->assertSame(1, PlanCostEntry::count());
    }

    public function test_nobody_can_delete_or_change_a_cost_entry(): void
    {
        $entry = $this->cost();

        $this->assertFalse(Route::has('planning.costs.destroy'));
        // The owner holds everything, and there is still no way to delete.
        $this->assertContains($this->actingAs($this->owner)->delete('/planning/costs/'.$entry->id)->status(), [404, 405]);

        try {
            $entry->delete();
            $this->fail('A cost entry was deleted.');
        } catch (ImmutableRecordException) {
        }

        try {
            $entry->update(['amount' => '1.00']);
            $this->fail('A cost entry was changed.');
        } catch (ImmutableRecordException) {
        }

        $this->assertSame('2500.00', PlanCostEntry::sole()->amount);
    }

    public function test_another_company_cannot_reverse_or_write_here(): void
    {
        $entry = $this->cost();
        $rival = $this->member($this->tenant('Rival Infra'), 'owner');
        $this->actingAs($rival);

        $this->post(route('planning.costs.reverse', $entry), ['reason' => 'Not ours to touch'])->assertNotFound();
        $this->post($this->url('/budget'), ['head' => 'material', 'amount' => '1'])->assertNotFound();
        $this->post($this->url('/baseline'))->assertNotFound();

        $this->assertSame(1, PlanCostEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, PlanBudgetLine::withoutGlobalScopes()->count());
    }
}
