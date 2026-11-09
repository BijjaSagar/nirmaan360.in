<?php

namespace Tests\Feature\SiteAlerts;

use App\Models\Alert;
use App\Models\BoqCurrentRate;
use App\Models\BoqItem;
use App\Services\SiteAlerts\AlertEngine;
use App\Services\SiteAlerts\SiteDashboard;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ProjectSetup\ProjectSetupTestCase;

/** Section 10: a budget with a margin of 5%, and the BOQ against it at BOQ rates and at the latest rates. */
class BudgetAlertsTest extends ProjectSetupTestCase
{
    private BoqItem $rcc;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // 5,00,000 + 4,00,000 + 30,000 = 9,30,000 against a 10,00,000 budget.
        $this->rcc = $this->line('100.000', '5000.00', ['code' => 'R1', 'category' => 'rcc']);
        $this->line('200.000', '2000.00', ['code' => 'B1', 'category' => 'brick_work', 'description' => 'Brick masonry']);
        $this->line('10.000', '3000.00', ['code' => 'S1', 'category' => 'plumbing', 'unit' => 'nos', 'description' => 'Jaguar wash basin']);
        $this->project->update(['budget_amount' => '1000000.00', 'budget_margin_pct' => '5.00']);
    }

    private function scan(): array
    {
        return app(AlertEngine::class)->scanProject($this->project->fresh());
    }

    private function budgetAlerts()
    {
        return Alert::whereIn('type', ['budget.over', 'budget.heading_over']);
    }

    public function test_a_boq_inside_the_margin_raises_nothing(): void
    {
        $this->scan();

        $this->assertSame(0, $this->budgetAlerts()->count());
    }

    public function test_a_boq_beyond_the_margin_is_a_critical_alert_for_money_overview_only(): void
    {
        $this->project->update(['budget_amount' => '800000.00']);

        $this->scan();

        $alert = $this->budgetAlerts()->sole();
        $this->assertSame('budget.over', $alert->type);
        $this->assertSame('Budget beyond margin', $alert->titleIn('en'));
        $this->assertSame('The BOQ comes to ₹9,30,000.00, 16.25% over the ₹8,00,000.00 budget and beyond the 5% margin.', $alert->messageIn('en'));
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('money.overview', $alert->needs_permission);
        $this->assertSame(['boq' => '930000.00', 'ceiling' => '840000.00', 'variance_pct' => '16.25'], ['boq' => $alert->data['boq'], 'ceiling' => $alert->data['ceiling'], 'variance_pct' => $alert->data['variance_pct']]);

        $this->assertTrue(Alert::visibleTo($this->pm)->whereKey($alert->id)->exists());
        $this->assertFalse(Alert::visibleTo($this->qs)->whereKey($alert->id)->exists());
        $this->assertFalse(Alert::visibleTo($this->engineer)->whereKey($alert->id)->exists());
        $this->assertSame(1, $this->pm->notifications()->count());
        $this->assertSame(0, $this->qs->notifications()->count());
        $this->assertSame(0, $this->engineer->notifications()->count());
    }

    public function test_latest_rates_pushing_the_boq_past_the_margin_are_a_warning(): void
    {
        // RCC at 6,500 instead of 5,000 adds 1,50,000: 10,80,000 against a 10,50,000 ceiling.
        BoqCurrentRate::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'boq_item_id' => $this->rcc->id, 'rate' => '6500.00', 'noted_on' => '2026-11-05']);

        $this->scan();

        $alert = $this->budgetAlerts()->sole();
        $this->assertSame('budget.heading_over', $alert->type);
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('At the latest rates the BOQ comes to ₹10,80,000.00, 8% over the ₹10,00,000.00 budget and beyond the 5% margin.', $alert->messageIn('en'));
        $this->assertSame('1080000.00', $alert->data['projected']);
    }

    public function test_the_money_card_sets_the_boq_against_the_budget(): void
    {
        BoqCurrentRate::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'boq_item_id' => $this->rcc->id, 'rate' => '6500.00', 'noted_on' => '2026-11-05']);

        $card = collect(app(SiteDashboard::class)->cards($this->project->fresh(), $this->pm))->firstWhere('key', 'money');
        $rows = collect($card->rows)->keyBy('label');

        $this->assertSame('₹9,30,000.00', $rows['BOQ value']['value']);
        $this->assertSame('-7% against budget', $rows['BOQ value']['sub']);
        $this->assertSame('₹10,80,000.00', $rows['BOQ at the latest rates']['value']);
        $this->assertSame('1 of 3 lines priced · +8% against budget', $rows['BOQ at the latest rates']['sub']);
        $this->assertStringContainsString('of the ₹10,00,000.00 budget, margin 5%', $card->note);

        $this->assertNull(collect(app(SiteDashboard::class)->cards($this->project->fresh(), $this->engineer))->firstWhere('key', 'money'));
    }
}
