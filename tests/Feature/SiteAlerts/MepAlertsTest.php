<?php

namespace Tests\Feature\SiteAlerts;

use App\Models\Alert;
use App\Models\ProjectFloor;
use App\Services\SiteAlerts\AlertEngine;
use App\Services\SiteAlerts\SiteDashboard;
use App\Services\SiteProgress\MepTracker;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\SiteProgress\SiteProgressTestCase;

/** Section 9: the 2nd floor passage wiring left pending while plaster starts on that floor. */
class MepAlertsTest extends SiteProgressTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    /** The tracker insists on a remark for anything marked pending, so one is supplied unless the test gives its own. */
    private function set(ProjectFloor $floor, string $trade, string $stage, string $status, array $extra = []): void
    {
        $this->actingAs($this->owner);

        if ($status === 'pending' && ! isset($extra['remark'])) {
            $extra['remark'] = 'Left for later';
        }

        app(MepTracker::class)->update($this->project, ['project_floor_id' => $floor->id, 'trade' => $trade, 'stage' => $stage, 'status' => $status, ...$extra], $this->owner);
    }

    private function plasterOn(ProjectFloor $floor): void
    {
        $plaster = $this->item('500.000');
        $plaster->update(['category' => 'plaster', 'description' => 'Internal plaster 12 mm']);
        $this->work($plaster, '50', $floor);
    }

    private function scan(): array
    {
        return app(AlertEngine::class)->scanProject($this->project->fresh());
    }

    public function test_pending_wiring_on_a_floor_where_plaster_has_started_is_a_critical_alert(): void
    {
        $second = $this->floor('2nd floor');
        $this->set($second, 'electrical', 'wiring', 'pending', ['remark' => 'Outside passage wiring not done']);
        $this->plasterOn($second);

        $this->assertSame(1, $this->scan()['new']);

        $alert = Alert::where('type', 'mep.pending_before_finishing')->sole();
        $this->assertSame('Finishing started with plumbing or electrical not done', $alert->titleIn('en'));
        $this->assertSame('Finishing work has started on 2nd floor with 1 plumbing or electrical items pending and 10 not yet done.', $alert->messageIn('en'));
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('floor:'.$second->id, $alert->reference);
        $this->assertNull($alert->needs_permission);
        $this->assertSame('progress.create', $alert->notify_permission);
        $this->assertSame('Outside passage wiring not done', $alert->data['items'][0]['remark']);
        $this->assertStringContainsString('/before-finishing', $alert->url);
        $this->assertSame(1, $this->owner->notifications()->get()->filter(fn ($notice) => str_starts_with($notice->data['title'], 'Finishing started'))->count());
    }

    public function test_nothing_before_finishing_starts_and_nothing_where_the_tracker_is_not_used(): void
    {
        $second = $this->floor('2nd floor');
        $this->set($second, 'electrical', 'wiring', 'pending');
        $this->assertSame(0, $this->scan()['new']);

        $this->plasterOn($this->floor('3rd floor'));
        $this->assertSame(0, $this->scan()['new']);
        $this->assertSame(0, Alert::count());
    }

    public function test_the_alert_clears_once_every_stage_on_the_floor_is_done(): void
    {
        $second = $this->floor('2nd floor');
        $this->set($second, 'electrical', 'wiring', 'pending');
        $this->plasterOn($second);
        $this->scan();

        foreach (config('modules.site_progress.mep_trades') as $trade => $details) {
            foreach (array_keys($details['stages']) as $stage) {
                $this->set($second, $trade, $stage, 'done');
            }
        }

        $this->assertSame(1, $this->scan()['resolved']);
        $this->assertSame(0, Alert::open()->count());
    }

    public function test_the_stages_card_counts_pending_items_by_floor(): void
    {
        $second = $this->floor('2nd floor');
        $this->set($second, 'electrical', 'wiring', 'pending');
        $this->set($second, 'plumbing', 'drainage', 'pending');
        $this->floor('3rd floor');

        $card = collect(app(SiteDashboard::class)->cards($this->project->fresh(), $this->owner))->firstWhere('key', 'stages');

        $this->assertSame('2', $card->value);
        $this->assertCount(1, $card->rows);
        $this->assertSame(['2nd floor', '2'], [$card->rows[0]['label'], $card->rows[0]['value']]);
        $this->assertNoMoney(json_encode($card));
    }
}
