<?php

namespace Tests\Feature\SiteAlerts;

use App\Models\Alert;
use App\Models\User;
use App\Services\SiteAlerts\AlertEngine;
use App\Services\SiteAlerts\Card;
use App\Services\SiteAlerts\SiteDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContractorControl\BuildsContractorControl;
use Tests\TestCase;

/** Section 7, example 1: a ₹1,00,000 job, ₹90,000 already paid, 70% of the work done. */
class ContractorAlertsTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        $this->site();
    }

    private function scan(): array
    {
        return app(AlertEngine::class)->scanProject($this->project->fresh());
    }

    private function card(User $viewer, string $key): ?Card
    {
        return collect(app(SiteDashboard::class)->cards($this->project->fresh(), $viewer))->firstWhere('key', $key);
    }

    private function noticesAbout(User $user, string $title): int
    {
        return $user->notifications()->get()->filter(fn ($notice) => str_starts_with((string) ($notice->data['title'] ?? ''), $title))->count();
    }

    public function test_paid_90_percent_with_70_percent_of_the_work_done_is_a_money_alert(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70', $order);
        $this->paid($order, '90000');

        $this->assertSame(1, $this->scan()['new']);

        $alert = Alert::where('type', 'payment.ahead')->sole();
        $this->assertSame("{$order->number} ({$order->party->name}): paid 90%, work 70%, 20% ahead against a 5% limit.", $alert->messageIn('en'));
        $this->assertSame('Payment ahead of work', $alert->titleIn('en'));
        $this->assertSame('money.view', $alert->needs_permission);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('work_order:'.$order->id, $alert->reference);
        $this->assertSame(['100000.00', '90000.00', '90.00', '70.00', '20.00'], array_map(fn (string $key) => $alert->data[$key], ['contract_value', 'paid', 'paid_pct', 'work_pct', 'gap_pct']));
        $this->assertStringContainsString('/contractor-control/work-orders/', $alert->url);

        $this->assertTrue(Alert::visibleTo($this->manager)->whereKey($alert->id)->exists());
        $this->assertFalse(Alert::visibleTo($this->supervisor)->whereKey($alert->id)->exists());
        $this->assertSame(1, $this->noticesAbout($this->manager, 'Payment ahead of work'));
        $this->assertSame(0, $this->noticesAbout($this->supervisor, 'Payment ahead of work'));
    }

    public function test_a_contract_inside_the_limit_raises_nothing(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70', $order);
        $this->paid($order, '72000');

        $this->assertSame(0, $this->scan()['new']);
        $this->assertSame(0, Alert::count());
    }

    public function test_the_contractor_card_shows_paid_percent_only_with_money_view(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70', $order);
        $this->paid($order, '90000');

        $money = $this->card($this->manager, 'contractors');
        $this->assertSame('1', $money->value);
        $this->assertSame('1 of 1 contracts paid ahead of work, 1 over the limit', $money->note);
        $this->assertSame('Paid 90%, Work 70%', $money->rows[0]['value']);
        $this->assertSame($order->party->name, $money->rows[0]['label']);
        $this->assertTrue($money->money);

        $site = $this->card($this->supervisor, 'contractors');
        $this->assertSame('1', $site->value);
        $this->assertSame('1 contracts, work done against each', $site->note);
        $this->assertSame('70%', $site->rows[0]['value']);
        $this->assertFalse($site->money);
        $this->assertStringNotContainsString('Paid', json_encode($site));
        $this->assertStringNotContainsString('90', json_encode($site));
    }
}
