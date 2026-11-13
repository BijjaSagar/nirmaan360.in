<?php

namespace Tests\Feature\SiteDiary;

use App\Models\Hindrance;
use App\Models\SiteLetter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_the_walks_open_a_draft_letter_that_only_people_who_write_letters_can_read(): void
    {
        $letter = $this->rows[SiteLetter::class];
        $entry = $this->rows[Hindrance::class];
        $list = route('site-diary.letters.index', $this->project);
        $page = route('site-diary.letters.show', $letter);

        $this->assertSame([SiteLetter::DELAY_NOTICE, SiteLetter::DRAFT, 'mr'], [$letter->type, $letter->status, $letter->language]);
        $this->assertNull($entry->refresh()->locked_at, 'A draft locks nothing, so the register in the walks is as it was.');

        foreach (['owner', 'pm'] as $role) {
            $this->actingAs($this->users[$role])->get($list)->assertOk()->assertSee($page, false)->assertSee('Prepare a letter');
            $shown = $this->get($page)->assertOk()
                ->assertSee('Lift shaft drawings awaited')
                // The clause typed for the other notice is no part of a notice of delay.
                ->assertDontSee('Sample agreement, annexure Q')
                ->assertSee('Figures in this letter')
                ->assertSee('title="Name of your company">Sentinel Infra</mark> करिता', false)
                ->assertDontSee('The records behind this draft have changed');

            // Dates and days only: nothing in the page itself is money. (The bell above it may name a bill sent to this person for approval.)
            $main = Str::between($shown->getContent(), '<main', '</main>');
            $this->assertStringContainsString('Lift shaft drawings awaited', $main);
            $this->assertStringNotContainsString('₹', $main);

            foreach (array_keys(self::MONEY) as $figure) {
                $this->assertStringNotContainsString($this->needle($figure), preg_replace('/\D+/', '', $main), $figure);
            }
        }

        // The site roles and accounts have no letters at all.
        foreach (['engineer', 'store', 'viewer', 'accounts', 'qs'] as $role) {
            $this->actingAs($this->users[$role])->get($list)->assertForbidden();
            $this->get($page)->assertForbidden();
        }
    }
}
