<?php

namespace Tests\Feature\SiteDiary;

use App\Models\Hindrance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\SiteControl\BuildsMoneyWorld;
use Tests\TestCase;

/**
 * The register inside the company the route walks use. MoneyVisibilityTest proves that no site role
 * is handed a rupee figure there; its list of what the owner must see is fixed, so this test is the
 * control: the entry and its notice date are really on the page, for the owner and for the engineer.
 */
class SiteDiaryMoneyWorldTest extends TestCase
{
    use BuildsMoneyWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildMoneyWorld();
    }

    public function test_the_walks_open_a_register_that_has_an_entry_and_a_notice_date_on_it(): void
    {
        $entry = $this->rows[Hindrance::class];
        $url = route('site-diary.hindrances.index', $this->project);
        $date = $entry->started_on->addDays(6)->format('d M Y');

        $owner = $this->actingAs($this->users['owner'])->get($url)->assertOk()
            ->assertSee('Lift shaft drawings awaited')
            ->assertSee('Lift shaft drawings not received')
            ->assertSee('Notice for extension of time')
            ->assertSee($date)
            ->assertSee('in 3 days')
            ->assertSee('Sample agreement, annexure Q')
            ->assertSee(route('contract-terms.index', $this->project), false);

        // The engineer keeps the register and sees the same date, without the clause and without the way to the terms.
        $engineer = $this->actingAs($this->users['engineer'])->get($url)->assertOk()
            ->assertSee('Lift shaft drawings awaited')
            ->assertSee('Notice for extension of time')
            ->assertSee($date)
            ->assertSee('Make an entry')
            ->assertDontSee('Sample agreement')
            ->assertDontSee(route('contract-terms.index', $this->project), false);

        // Nothing on the page is money, for either of them.
        foreach ([$owner, $engineer] as $page) {
            $page->assertDontSee('₹');

            foreach (array_keys(self::MONEY) as $figure) {
                $this->assertStringNotContainsString($this->needle($figure), preg_replace('/\D+/', '', $page->getContent()), $figure);
            }
        }

        // The store keeper and the viewer have no register.
        foreach (['store', 'viewer', 'accounts'] as $role) {
            $this->actingAs($this->users[$role])->get($url)->assertForbidden();
        }
    }
}
