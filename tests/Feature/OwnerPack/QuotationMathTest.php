<?php

namespace Tests\Feature\OwnerPack;

use App\Models\BoqItem;
use App\Models\ClientPaymentStage;
use App\Services\OwnerPack\QuotationMath;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** The arithmetic of a quotation, to the paisa. Every percentage here is a typed test value. */
class QuotationMathTest extends TestCase
{
    private function percent(string $percent): array
    {
        return ['basis' => ClientPaymentStage::PERCENT, 'percent' => $percent];
    }

    private function amount(string $amount): array
    {
        return ['basis' => ClientPaymentStage::AMOUNT, 'amount' => $amount];
    }

    public function test_a_line_amount_is_quantity_times_rate_exactly_as_the_boq_works_it_out(): void
    {
        foreach ([['12.500', '4200.10', '52501.25'], ['0.333', '100.00', '33.30'], ['1.005', '0.99', '0.99'], ['3.335', '1.00', '3.34'], ['0.000', '500.00', '0.00']] as [$qty, $rate, $expected]) {
            $this->assertSame($expected, QuotationMath::lineAmount($qty, $rate));
            $this->assertSame((new BoqItem(['qty' => $qty, 'rate' => $rate]))->amount(), QuotationMath::lineAmount($qty, $rate));
        }
    }

    public function test_totals_add_the_lines_and_take_gst_on_the_percentage_that_was_typed(): void
    {
        $this->assertSame(
            ['subtotal' => '146834.58', 'gst_amount' => '11012.59', 'total' => '157847.17'],
            QuotationMath::totals(['52501.25', '76000.00', '18333.33'], '7.5'),
        );

        // 0.1 + 0.2 is not 0.30000000000000004 here.
        $this->assertSame('0.30', QuotationMath::totals(['0.10', '0.20'], null)['total']);
    }

    public function test_no_percentage_typed_means_no_gst_and_never_an_assumed_rate(): void
    {
        foreach ([null, ''] as $nothing) {
            $this->assertSame(['subtotal' => '1000.00', 'gst_amount' => '0.00', 'total' => '1000.00'], QuotationMath::totals(['1000.00'], $nothing));
        }

        $this->assertSame('0.00', QuotationMath::totals(['1000.00'], '0')['gst_amount']);
    }

    public function test_percentage_stages_that_make_the_whole_add_up_to_the_total_to_the_paisa(): void
    {
        // A third each of 100000.01 would lose a paisa. The last stage takes what is left.
        $amounts = QuotationMath::stageAmounts('100000.01', [$this->percent('33.3333'), $this->percent('33.3333'), $this->percent('33.3334')]);

        $this->assertSame(['33333.30', '33333.30', '33333.41'], $amounts);
        $this->assertSame('100000.01', (string) QuotationMath::sum($amounts));

        $amounts = QuotationMath::stageAmounts('157847.17', [$this->percent('30'), $this->percent('33.3333'), $this->percent('36.6667')]);

        $this->assertSame(['47354.15', '52615.67', '57877.35'], $amounts);
        $this->assertSame('157847.17', (string) QuotationMath::sum($amounts));
    }

    public function test_seven_equal_looking_stages_still_add_up_exactly(): void
    {
        $stages = [...array_fill(0, 6, $this->percent('14.2857')), $this->percent('14.2858')];
        $amounts = QuotationMath::stageAmounts('999999.99', $stages);

        $this->assertSame('999999.99', (string) QuotationMath::sum($amounts));
        $this->assertSame('142857.00', $amounts[0]);
        $this->assertSame('142857.99', $amounts[6]);
    }

    public function test_percentages_short_of_the_whole_are_each_their_share_and_nothing_is_topped_up(): void
    {
        $this->assertSame(['25000.00', '25000.00'], QuotationMath::stageAmounts('100000.00', [$this->percent('25'), $this->percent('25')]));
    }

    public function test_percentages_beyond_the_whole_are_refused(): void
    {
        $this->expectException(ValidationException::class);

        QuotationMath::stageAmounts('100000.00', [$this->percent('60'), $this->percent('40.0001')]);
    }

    public function test_a_typed_amount_keeps_its_amount_and_mixes_with_percentages(): void
    {
        $this->assertSame(['20000.00', '40000.00', '12345.67'], QuotationMath::stageAmounts('100000.00', [$this->amount('20000'), $this->percent('40'), $this->amount('12345.67')]));
    }

    public function test_stages_may_never_come_to_more_than_the_total(): void
    {
        $this->assertSame(['60000.00', '40000.00'], QuotationMath::stageAmounts('100000.00', [$this->amount('60000'), $this->amount('40000')]));

        try {
            QuotationMath::stageAmounts('100000.00', [$this->amount('60000'), $this->amount('40000.01')]);
            $this->fail('Stages of more than the total were accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['amount'], array_keys($e->errors()));
        }

        try {
            QuotationMath::stageAmounts('100000.00', [$this->amount('60000'), $this->percent('40.01')]);
            $this->fail('A percentage that takes the stages past the total was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['amount'], array_keys($e->errors()));
        }
    }

    public function test_a_split_that_would_leave_the_last_stage_below_zero_is_refused(): void
    {
        // Two paise in four quarters: three of them round up to a paisa each.
        try {
            QuotationMath::stageAmounts('0.02', array_fill(0, 4, $this->percent('25')));
            $this->fail('A last stage below zero was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['percent'], array_keys($e->errors()));
        }
    }

    public function test_no_stages_is_no_schedule(): void
    {
        $this->assertSame([], QuotationMath::stageAmounts('100000.00', []));
        $this->assertFalse(QuotationMath::allPercent([]));
    }

    public function test_a_percentage_is_written_without_padding(): void
    {
        $this->assertSame('40', QuotationMath::plain('40.0000'));
        $this->assertSame('33.3333', QuotationMath::plain('33.3333'));
        $this->assertSame('7.5', QuotationMath::plain('7.50'));
        $this->assertSame('0', QuotationMath::plain('0.00'));
        $this->assertSame('100', QuotationMath::plain(QuotationMath::percentTotal([$this->percent('30'), $this->percent('33.3333'), $this->percent('36.6667')])));
    }
}
