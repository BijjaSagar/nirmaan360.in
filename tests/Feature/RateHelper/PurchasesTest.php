<?php

namespace Tests\Feature\RateHelper;

use App\Models\BoqCurrentRate;
use App\Models\RateSuggestionLine;
use App\Services\RateHelper\RateSources;
use App\Services\RateHelper\Sources\PurchasesSource;
use Illuminate\Support\Facades\DB;

/** The material cost of a line at the company's own purchase prices: shown for reference, never a rate. */
class PurchasesTest extends RateHelperTestCase
{
    public function test_the_material_cost_is_worked_out_from_norms_and_the_latest_purchases(): void
    {
        $line = $this->line('RCC M25 in slab');
        $cement = $this->material('Cement', 'bag');
        $sand = $this->material('River sand', 'brass');
        $this->norm($line, $cement, '8.000000', '2.00');
        $this->norm($line, $sand, '0.450000');

        // Cement: an older order, a newer order, and a still newer draft that was never sent.
        $this->purchase($cement, '100.000', '36000.00', '2026-08-01');
        $order = $this->purchase($cement, '100.000', '38000.00', '2026-10-01');
        $this->purchase($cement, '100.000', '99000.00', '2026-10-20', null, 'DRAFT');
        $this->purchase($cement, '100.000', '98000.00', '2026-10-21', null, 'CANCELLED');
        // Sand: bought on a challan, with the bill entered later. A reversed challan does not count.
        $challan = $this->challanBill($sand, '10.000', '42000.00', '2026-09-15');
        $this->challanBill($sand, '10.000', '97000.00', '2026-10-25', null, 'REVERSED');

        $run = $this->startRun($this->pm, $line);
        $ref = $this->refOf($run, $line, PurchasesSource::KEY, $line->id);
        $this->assertNotNull($ref);
        $this->assertNull($this->lineOf($run, $line)->candidate($ref)['score']);

        // 8 × 1.02 × 380.00 = 3,100.80 and 0.45 × 4,200.00 = 1,890.00.
        $this->actingAs($this->pm);
        $figure = app(RateSources::class)->open()[PurchasesSource::KEY]->rateOf($this->pm, $line->id);
        $this->assertSame('4990.80', $figure->rate);
        $this->assertFalse($figure->acceptable);
        $this->assertFalse($figure->incomplete);
        $this->assertSame('2026-09-15', $figure->date->toDateString());
        $this->assertSame(['3100.80', '1890.00'], array_column($figure->parts, 'amount'));

        $this->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Your purchases, for reference only')
            ->assertSee('Material cost for one cum:')
            ->assertSee('₹4,990.80')
            ->assertSee('8 bag for one cum, plus 2% wastage, at ₹380.00 per bag. Purchase order '.$order->number.', 01 Oct 2026, before GST.')
            ->assertSee('0.45 brass for one cum, plus 0% wastage, at ₹4,200.00 per brass. Challan bill '.$challan->bill_no.', 15 Sep 2026, as entered on the bill.')
            ->assertSee('Materials only: labour, plant, overheads and profit are not in this figure. It cannot be accepted as the rate.')
            ->assertDontSee('incomplete')
            ->assertDontSee('Accept this rate')
            ->assertDontSee('990.00')->assertDontSee('980.00')->assertDontSee('9,700.00');
    }

    public function test_the_material_cost_cannot_be_accepted_as_a_rate(): void
    {
        $line = $this->line('RCC M25 in slab');
        $cement = $this->material('Cement', 'bag');
        $this->norm($line, $cement, '8.000000');
        $this->purchase($cement, '100.000', '38000.00', '2026-10-01');

        $run = $this->startRun($this->pm, $line);
        $ref = $this->refOf($run, $line, PurchasesSource::KEY, $line->id);

        $refusal = 'This figure is shown for reference only and cannot be accepted as a rate.';

        foreach (RateSuggestionLine::MODES as $mode) {
            $this->accept($this->pm, $run, $line, $ref, ['mode' => $mode, 'checked_warnings' => '1'])->assertSessionHasErrors(['candidate' => $refusal]);
        }

        $this->assertSame('0.00', $line->fresh()->rate);
        $this->assertSame(0, BoqCurrentRate::withoutGlobalScopes()->count());
        $this->assertTrue($this->lineOf($run, $line)->isOpen());
    }

    public function test_a_material_with_no_purchase_makes_the_figure_incomplete(): void
    {
        $line = $this->line('RCC M25 in slab');
        $cement = $this->material('Cement', 'bag');
        $steel = $this->material('TMT steel', 'kg');
        $this->norm($line, $cement, '8.000000');
        $this->norm($line, $steel, '95.000000', '3.00');
        $this->purchase($cement, '100.000', '38000.00', now()->subDays(10)->toDateString());

        $run = $this->startRun($this->pm, $line);

        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('₹3,040.00')
            ->assertSee('incomplete')
            ->assertSee('TMT steel')
            ->assertSee('No purchase of this material was found.');

        // A line whose materials were never bought, and a line with no norms, have no such figure at all.
        $unbought = $this->line('Brick masonry');
        $this->norm($unbought, $this->material('Bricks', 'nos'), '500.000000');
        $plain = $this->line('Cement plaster', 'sqm');
        $run = $this->startRun($this->pm, $unbought, $plain);

        $this->assertSame([], $this->shortlist($run, $unbought));
        $this->assertSame([], $this->shortlist($run, $plain));
    }

    public function test_the_price_is_per_base_unit_whatever_unit_the_order_was_written_in(): void
    {
        $line = $this->line('Reinforcement steel', 'MT');
        $steel = $this->material('TMT steel', 'kg');
        $this->norm($line, $steel, '1000.000000', '2.50');

        // 2 MT ordered: 2,000 kg in base units, for 1,25,000 before GST.
        $order = $this->purchase($steel, '2000.000', '125000.00', '2026-10-05');
        DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->update(['qty' => '2.000', 'rate' => '62500.00', 'gst_amount' => '22500.00']);

        $this->actingAs($this->pm);
        $figure = app(RateSources::class)->open()[PurchasesSource::KEY]->rateOf($this->pm, $line->id);

        // 1000 × 1.025 × 62.50, and the GST of the order is not in it.
        $this->assertSame('64062.50', $figure->rate);
    }

    public function test_purchases_of_a_project_outside_a_persons_list_are_not_read(): void
    {
        $line = $this->line('RCC M25 in slab');
        $cement = $this->material('Cement', 'bag');
        $sand = $this->material('River sand', 'brass');
        $this->norm($line, $cement, '8.000000');
        $this->norm($line, $sand, '0.450000');

        $this->purchase($cement, '100.000', '36000.00', '2026-08-01');
        // Newer, but bought for the other project.
        $hidden = $this->purchase($cement, '100.000', '41300.00', '2026-10-01', $this->other);
        $this->challanBill($sand, '10.000', '43700.00', '2026-10-02', $this->other);

        $everyone = $this->startRun($this->pm, $line);
        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $everyone))->assertOk()
            ->assertSee('₹413.00')->assertSee($hidden->number)->assertSee('₹4,370.00')->assertDontSee('incomplete');

        $limited = $this->limited('pm', $this->project);
        $own = $this->startRun($limited, $line);

        foreach ([$everyone, $own] as $run) {
            $this->actingAs($limited)->get(route('rate-suggestions.show', $run))->assertOk()
                ->assertSee('₹360.00')
                ->assertSee('incomplete')
                ->assertDontSee('413.00')->assertDontSee($hidden->number)->assertDontSee('4,370.00');
        }
    }

    public function test_another_companys_purchases_are_never_read(): void
    {
        [$rivalOrg, , $rivalProject] = $this->rival();
        $line = $this->line('RCC M25 in slab');
        $cement = $this->material('Cement', 'bag');
        $this->norm($line, $cement, '8.000000');

        // The rival bought the very same catalogue row, which cannot happen through the screens.
        $this->purchase($cement, '100.000', '47100.00', '2026-10-01', $rivalProject);
        $this->challanBill($cement, '100.000', '47200.00', '2026-10-02', $rivalProject);
        $this->assertSame($rivalOrg->id, $rivalProject->organization_id);

        $run = $this->startRun($this->pm, $line);

        $this->assertSame([], $this->shortlist($run, $line));
        $this->actingAs($this->pm)->get(route('rate-suggestions.show', $run))->assertOk()->assertDontSee('471.00')->assertDontSee('472.00');
    }

    public function test_each_kind_of_purchase_is_read_only_by_a_role_that_may_open_it(): void
    {
        $line = $this->line('RCC M25 in slab');
        $cement = $this->material('Cement', 'bag');
        $this->norm($line, $cement, '10.000000');
        $this->purchase($cement, '100.000', '36000.00', '2026-08-01');
        $this->challanBill($cement, '100.000', '41300.00', '2026-10-01');

        $base = ['project.view', 'boq.view', 'boq.manage', 'money.view'];
        $both = $this->holding([...$base, 'procurement.view', 'material_control.view']);
        $ordersOnly = $this->holding([...$base, 'procurement.view']);
        $challansOnly = $this->holding([...$base, 'material_control.view']);
        $neither = $this->holding($base);

        $run = $this->startRun($this->owner, $line);

        // The newer of the two for someone who may open both.
        $this->actingAs($both)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('₹4,130.00')->assertSee('as entered on the bill');
        $this->actingAs($ordersOnly)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('₹3,600.00')->assertSee('before GST')->assertDontSee('4,130.00');
        $this->actingAs($challansOnly)->get(route('rate-suggestions.show', $run))->assertOk()->assertSee('₹4,130.00')->assertDontSee('3,600.00');
        $this->actingAs($neither)->get(route('rate-suggestions.show', $run))->assertOk()
            ->assertSee('Not open to your role')
            ->assertDontSee('4,130.00')->assertDontSee('3,600.00')->assertDontSee('Material cost for one');

        $this->assertSame([], $this->shortlist($this->startRun($neither, $line), $line));
    }
}
