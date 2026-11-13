<?php

namespace Tests\Feature\VoiceDpr;

use App\Models\BoqItem;
use App\Services\VoiceDpr\VoiceDraftChecks;
use Tests\TestCase;

/**
 * Every figure of a proposal is tied by code to the exact words it came from, and to the trade or
 * unit beside it. Whatever the model says, these are what the phone is told.
 */
class VoiceDraftChecksTest extends TestCase
{
    private const TRANSCRIPT = 'आज ऊन होतं. सहा गवंडी, बारा हेल्पर. फूटिंगमध्ये PCC साडेबारा घनमीटर झालं. 12 mm सळई 40 नग आली. M 25 स्लॅबचं काम उद्या. सकाळी 9 वाजता सिमेंट आलं.';

    private function item(int $id, string $code, string $description, string $unit): BoqItem
    {
        $item = new BoqItem(['code' => $code, 'description' => $description, 'unit' => $unit]);
        $item->id = $id;

        return $item;
    }

    /** @return array<string, BoqItem> */
    private function items(): array
    {
        return ['B1' => $this->item(310, '7', 'PCC 1:4:8 in foundation', 'cum'), 'B2' => $this->item(311, '12', 'Steel reinforcement', 'kg')];
    }

    private function answer(array $manpower = [], array $work = [], array $text = []): array
    {
        return ['weather' => 'Clear', 'weather_spoken' => 'ऊन', 'manpower' => $manpower, 'work' => $work, 'hindrances' => '', 'summary' => '', ...$text];
    }

    private function people(string $trade, string $tradeSpoken, string $count, string $countSpoken): array
    {
        return ['trade' => $trade, 'trade_spoken' => $tradeSpoken, 'count' => $count, 'count_spoken' => $countSpoken];
    }

    private function work(string $item, string $qty, string $qtySpoken, string $unitSpoken, string $description = 'काम'): array
    {
        return ['item' => $item, 'description' => $description, 'qty' => $qty, 'qty_spoken' => $qtySpoken, 'unit_spoken' => $unitSpoken];
    }

    /** @return array<string, mixed> */
    private function propose(array $answer, string $transcript = self::TRANSCRIPT, string $locale = 'mr-IN'): array
    {
        return (new VoiceDraftChecks($transcript, $locale))->proposal($answer, $this->items())['proposal'];
    }

    public function test_numbers_said_with_their_trade_and_unit_are_heard_and_anchored(): void
    {
        $proposal = $this->propose($this->answer(
            [$this->people('Mason', 'गवंडी', '6', 'सहा'), $this->people('helper', 'हेल्पर', '12', 'बारा')],
            [$this->work('B1', '12.5', 'साडेबारा', 'घनमीटर')],
        ));

        $this->assertSame([
            ['trade' => 'Mason', 'trade_spoken' => 'गवंडी', 'count' => '6', 'count_spoken' => 'सहा', 'heard' => true, 'anchored' => true],
            ['trade' => 'Helper', 'trade_spoken' => 'हेल्पर', 'count' => '12', 'count_spoken' => 'बारा', 'heard' => true, 'anchored' => true],
        ], $proposal['manpower']);

        $this->assertSame([[
            'boq_item_id' => 310, 'code' => '7', 'description' => 'PCC 1:4:8 in foundation', 'qty' => '12.500', 'qty_spoken' => 'साडेबारा',
            'unit_spoken' => 'घनमीटर', 'unit_item' => 'cum', 'unit_ok' => true, 'heard' => true, 'anchored' => true,
        ]], $proposal['work']);

        $this->assertSame('Clear', $proposal['weather']);
        // The steel that came, the time and the grade back no field. Only the forty is a number of the day's work.
        $this->assertSame(['40'], $proposal['unused_numbers']);
        $this->assertFalse($proposal['text_has_unheard_number']);
    }

    public function test_counts_given_to_the_wrong_trades_are_heard_but_not_anchored(): void
    {
        // "सहा गवंडी, बारा हेल्पर" returned as Mason 12 and Helper 6.
        $proposal = $this->propose($this->answer([$this->people('Mason', 'गवंडी', '12', 'बारा'), $this->people('Helper', 'हेल्पर', '6', 'सहा')]));

        $this->assertSame([[true, false], [true, false]], array_map(fn ($row) => [$row['heard'], $row['anchored']], $proposal['manpower']));
    }

    public function test_without_a_comma_two_numbers_side_by_side_tie_no_trade_to_either(): void
    {
        $proposal = $this->propose($this->answer([$this->people('Mason', 'गवंडी', '6', 'सहा'), $this->people('Helper', 'हेल्पर', '12', 'बारा')]), 'सहा गवंडी बारा हेल्पर');

        // Both are heard; neither can be tied to its trade for certain, so both need a tap.
        $this->assertSame([[true, false], [true, false]], array_map(fn ($row) => [$row['heard'], $row['anchored']], $proposal['manpower']));

        // Said the other way round with a pause, each is its own.
        $proposal = $this->propose($this->answer([$this->people('Mason', 'गवंडी', '6', 'सहा'), $this->people('Helper', 'हेल्पर', '12', 'बारा')]), 'गवंडी सहा, हेल्पर बारा');
        $this->assertSame([[true, true], [true, true]], array_map(fn ($row) => [$row['heard'], $row['anchored']], $proposal['manpower']));
    }

    public function test_one_spoken_number_cannot_back_two_fields(): void
    {
        $proposal = $this->propose($this->answer([$this->people('Mason', 'गवंडी', '12', 'बारा'), $this->people('Helper', 'हेल्पर', '12', 'बारा')]), 'बारा गवंडी आणि हेल्पर');

        $this->assertTrue($proposal['manpower'][0]['heard']);
        $this->assertFalse($proposal['manpower'][1]['heard']);
        $this->assertFalse($proposal['manpower'][1]['anchored']);

        // Said twice, it backs two.
        $proposal = $this->propose($this->answer([$this->people('Mason', 'गवंडी', '12', 'बारा'), $this->people('Helper', 'हेल्पर', '12', 'बारा')]), 'बारा गवंडी, बारा हेल्पर');
        $this->assertSame([[true, true], [true, true]], array_map(fn ($row) => [$row['heard'], $row['anchored']], $proposal['manpower']));
    }

    public function test_a_size_a_grade_or_a_time_is_not_heard_as_a_quantity(): void
    {
        $proposal = $this->propose($this->answer([], [
            $this->work('B2', '12', '12', 'mm'),
            $this->work('', '25', '25', ''),
            $this->work('', '9', '9', ''),
        ]));

        $this->assertSame([false, false, false], array_column($proposal['work'], 'heard'));

        // Written on to the unit, or after a grade, the same holds.
        foreach (['12mm सळई आली', 'Fe-500 सळई आली', '53 grade सिमेंट', 'काम 9:30 ला सुरू झालं', 'एम 25 काँक्रीट'] as $said) {
            $value = preg_replace('/\D+/', ' ', $said);
            $first = trim(explode(' ', trim($value))[0]);
            $proposal = $this->propose($this->answer([], [$this->work('', $first, $first, '')]), $said);
            $this->assertFalse($proposal['work'][0]['heard'], $said);
        }
    }

    public function test_a_number_that_was_not_said_or_that_says_something_else_is_not_heard(): void
    {
        $proposal = $this->propose($this->answer([
            $this->people('Carpenter', 'सुतार', '4', 'चार'),
            $this->people('Mason', 'गवंडी', '7', 'सहा'),
            $this->people('Helper', 'हेल्पर', '12', 'बारा हेल्पर'),
        ], [$this->work('B1', '125', 'साडेबारा', 'घनमीटर')]));

        $this->assertSame([false, false, false], array_column($proposal['manpower'], 'heard'));
        $this->assertFalse($proposal['work'][0]['heard']);
        // The figures stay for the person to see and correct.
        $this->assertSame(['4', '7', '12'], array_column($proposal['manpower'], 'count'));
        $this->assertSame('125.000', $proposal['work'][0]['qty']);
    }

    public function test_a_part_of_a_longer_number_is_not_heard(): void
    {
        $proposal = $this->propose($this->answer([], [$this->work('', '50', 'पन्नास', 'नग')]), 'दोनशे पन्नास नग विटा आल्या');

        $this->assertFalse($proposal['work'][0]['heard']);
        $this->assertSame(['250'], $proposal['unused_numbers']);
    }

    public function test_the_unit_is_compared_with_the_lines_own(): void
    {
        $proposal = $this->propose($this->answer([], [
            $this->work('B1', '40', '40', 'नग'),
            $this->work('B1', '12.5', 'साडेबारा', 'घनमीटर'),
            $this->work('B2', '12.5', 'साडेबारा', 'घनमीटर'),
        ]));

        // Forty pieces are not cubic metres. The second line is steel in kg, and the words were already used by the first.
        $this->assertSame([false, true, false], array_column($proposal['work'], 'unit_ok'));
        $this->assertSame([true, true, false], array_column($proposal['work'], 'heard'));

        // No unit said: there is nothing to tie the quantity to, so it counts as anchored and the unit as not matching.
        $proposal = $this->propose($this->answer([], [$this->work('B1', '12.5', 'साडेबारा', '')]), 'PCC साडेबारा झालं');
        $this->assertSame([true, true, false], [$proposal['work'][0]['heard'], $proposal['work'][0]['anchored'], $proposal['work'][0]['unit_ok']]);
    }

    public function test_a_unit_said_far_away_does_not_anchor_a_quantity(): void
    {
        $said = 'साडेबारा झालं आणि नंतर बऱ्याच वेळाने तिकडे खूप लांब घनमीटर';
        $proposal = $this->propose($this->answer([], [$this->work('B1', '12.5', 'साडेबारा', 'घनमीटर')]), $said);

        $this->assertTrue($proposal['work'][0]['heard']);
        $this->assertFalse($proposal['work'][0]['anchored']);
    }

    public function test_an_unknown_trade_is_left_empty_with_the_spoken_word_kept(): void
    {
        $proposal = $this->propose($this->answer([$this->people('Labour', 'गवंडी', '6', 'सहा')]));

        $this->assertSame('', $proposal['manpower'][0]['trade']);
        $this->assertSame('गवंडी', $proposal['manpower'][0]['trade_spoken']);
        $this->assertTrue($proposal['manpower'][0]['heard']);
    }

    public function test_an_item_ref_outside_the_list_keeps_its_own_description_and_no_line(): void
    {
        $proposal = $this->propose($this->answer([], [$this->work('B99', '12.5', 'साडेबारा', 'घनमीटर', 'फूटिंग PCC')]));

        $this->assertSame([
            'boq_item_id' => null, 'code' => '', 'description' => 'फूटिंग PCC', 'qty' => '12.500', 'qty_spoken' => 'साडेबारा',
            'unit_spoken' => 'घनमीटर', 'unit_item' => null, 'unit_ok' => false, 'heard' => true, 'anchored' => true,
        ], $proposal['work'][0]);
    }

    public function test_weather_must_be_from_the_list_and_said(): void
    {
        $this->assertSame('', $this->propose(['weather' => 'Sunny', 'weather_spoken' => 'ऊन'] + $this->answer())['weather']);
        $this->assertSame('', $this->propose(['weather' => 'Rain', 'weather_spoken' => 'पाऊस आला'] + $this->answer())['weather']);
        $this->assertSame('Rain', $this->propose(['weather' => 'rain', 'weather_spoken' => 'पाऊस'] + $this->answer(), 'आज पाऊस होता')['weather']);
    }

    public function test_figures_outside_what_a_dpr_takes_are_left_blank_and_not_heard(): void
    {
        $proposal = $this->propose($this->answer(
            [$this->people('Mason', 'गवंडी', '6.5', 'सहा'), $this->people('Helper', 'हेल्पर', 'many', 'बारा')],
            [$this->work('B1', '12.5005', 'साडेबारा', 'घनमीटर'), $this->work('B1', '100000000', '100000000', '')],
        ), self::TRANSCRIPT.' 100000000');

        $this->assertSame(['', ''], array_column($proposal['manpower'], 'count'));
        $this->assertSame(['', ''], array_column($proposal['work'], 'qty'));
        $this->assertSame([false, false, false, false], [...array_column($proposal['manpower'], 'heard'), ...array_column($proposal['work'], 'heard')]);
    }

    public function test_a_figure_nobody_said_in_the_hindrances_or_summary_is_reported(): void
    {
        $clean = $this->propose($this->answer([], [], ['hindrances' => 'सिमेंट उशिरा आलं', 'summary' => '6 गवंडी आणि बारा हेल्पर होते, 40 नग सळई']));
        $this->assertFalse($clean['text_has_unheard_number']);

        // Digits that nobody said.
        $this->assertTrue($this->propose($this->answer([], [], ['summary' => '7 गवंडी होते']))['text_has_unheard_number']);
        // A number word beside a word of quantity.
        $this->assertTrue($this->propose($this->answer([], [], ['hindrances' => 'पंधरा मजूर आले नाहीत']))['text_has_unheard_number']);
        // A number word that counts nothing is not looked at.
        $this->assertFalse($this->propose($this->answer([], [], ['summary' => 'एक काम राहिलं']))['text_has_unheard_number']);
    }

    public function test_hindi_and_english_reports_are_checked_the_same_way(): void
    {
        $hindi = $this->propose(
            $this->answer([$this->people('Mason', 'मिस्त्री', '6', 'छह')], [$this->work('B1', '150', 'डेढ़ सौ', 'घन मीटर')]),
            'आज छह मिस्त्री आए। डेढ़ सौ घन मीटर खुदाई हुई।',
            'hi-IN',
        );
        $this->assertSame([[true, true]], array_map(fn ($row) => [$row['heard'], $row['anchored']], $hindi['manpower']));
        $this->assertTrue($hindi['work'][0]['heard']);
        $this->assertTrue($hindi['work'][0]['anchored']);

        $english = $this->propose(
            $this->answer([$this->people('Mason', 'masons', '6', 'six'), $this->people('Helper', 'helpers', '12', 'twelve')], [$this->work('B1', '2.5', 'two and a half', 'cubic metre')]),
            'Six masons and twelve helpers. Two and a half cubic metre PCC done.',
            'en-IN',
        );
        $this->assertSame([[true, true], [true, true]], array_map(fn ($row) => [$row['heard'], $row['anchored']], $english['manpower']));
        $this->assertSame([true, true, true], [$english['work'][0]['heard'], $english['work'][0]['anchored'], $english['work'][0]['unit_ok']]);
    }
}
