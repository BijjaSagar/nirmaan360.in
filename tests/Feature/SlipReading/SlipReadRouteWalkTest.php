<?php

namespace Tests\Feature\SlipReading;

use App\Models\SlipRead;
use App\Services\SlipReading\SlipReadKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\SiteControl\BuildsMoneyWorld;
use Tests\TestCase;

/**
 * The control for the module's file in SiteControl/MoneyWorld: the reading it adds belongs to the store
 * keeper, who may open it and finds no money figure in it, while every other site role is refused.
 */
class SlipReadRouteWalkTest extends TestCase
{
    use BuildsMoneyWorld, RefreshDatabase;

    public function test_the_walked_reading_is_the_store_keepers_and_carries_no_money(): void
    {
        $this->withoutVite();
        config(['modules.slip_reading.read_kinds' => [SlipReadKind::class]]);
        $this->buildMoneyWorld();

        $slip = $this->rows[SlipRead::class];
        $this->assertSame([$this->project->id, $this->users['store']->id], [$slip->project_id, $slip->user_id]);

        $page = $this->actingAs($this->users['store'])->getJson("/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('fields.challan_no.value', 'DC-0457');
        $this->assertDoesNotMatchRegularExpression('/"(rate|amount|bill_amount|grand_total)"/', $page->getContent());

        foreach (['engineer', 'pm', 'accounts', 'viewer'] as $role) {
            $this->actingAs($this->users[$role])->getJson("/slip-reads/{$slip->id}")->assertForbidden();
        }

        $this->actingAs($this->users['owner'])->getJson("/slip-reads/{$slip->id}")->assertOk();
    }
}
