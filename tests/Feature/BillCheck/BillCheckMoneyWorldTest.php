<?php

namespace Tests\Feature\BillCheck;

use App\Models\BillCheck;
use App\Models\RaBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\SiteControl\BuildsMoneyWorld;
use Tests\TestCase;

/**
 * The control for the route walks. MoneyVisibilityTest proves that a site role receives no rupee
 * figure from the bill page or the check's JSON; its list of what the owner must see is fixed, so
 * this test proves the other half: the stored check of the money world does carry the figures, and
 * the owner does see them in both places. Without it the walk could pass by showing nothing at all.
 */
class BillCheckMoneyWorldTest extends TestCase
{
    use BuildsMoneyWorld, RefreshDatabase;

    /** Figures of the money world's bill that its check names. */
    private const NAMED = ['bill_amount', 'bill_gross', 'bill_deduction', 'bill_gst', 'bill_net'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildMoneyWorld();
    }

    public function test_the_stored_check_carries_the_figures_and_the_owner_sees_them(): void
    {
        $bill = $this->rows[RaBill::class];
        $check = $this->rows[BillCheck::class];
        $stored = $this->digits((string) json_encode([$check->flags, $check->expected]));

        foreach (self::NAMED as $key) {
            $this->assertStringContainsString($this->needle($key), $stored, "The stored check names the {$key} figure.");
        }

        $this->actingAs($this->users['owner']);
        $json = $this->digits($this->getJson(route('bill-checks.show', $bill))->assertOk()->getContent());
        $page = $this->digits($this->get(route('ra-bills.show', $bill))->assertOk()->assertSee('Bill pre-check')->assertSee('Testing charges')->getContent());

        foreach (self::NAMED as $key) {
            $this->assertStringContainsString($this->needle($key), $json, "The owner's JSON shows the {$key} figure.");
            $this->assertStringContainsString($this->needle($key), $page, "The owner's bill page shows the {$key} figure.");
        }
    }

    public function test_a_viewer_gets_the_same_findings_with_no_figure_in_them(): void
    {
        $bill = $this->rows[RaBill::class];
        $check = $this->rows[BillCheck::class];

        $this->actingAs($this->users['viewer']);
        $response = $this->getJson(route('bill-checks.show', $bill))->assertOk();
        $page = $this->get(route('ra-bills.show', $bill))->assertOk()->assertSee('Bill pre-check')->assertSee('The gross value of this bill is not the total of its lines.');

        $this->assertSame(array_column($check->flags, 'key'), array_column($response->json('check.flags'), 'key'));
        $this->assertNull($response->json('check.expected.lines'));

        foreach ([$response->getContent(), $page->getContent()] as $body) {
            $digits = $this->digits($body);

            foreach (array_keys(self::MONEY) as $key) {
                $this->assertStringNotContainsString($this->needle($key), $digits, "A viewer received the {$key} figure.");
            }

            $this->assertStringNotContainsString('₹', $body);
            $this->assertStringNotContainsString('₹', $body);
            $this->assertStringNotContainsString('6.25', $body, 'The contract percentage of a confirmed term.');
            $this->assertStringNotContainsString('schedule K', $body, 'Words typed on a contract term.');
            $this->assertStringNotContainsString('Testing charges', $body);
        }
    }
}
