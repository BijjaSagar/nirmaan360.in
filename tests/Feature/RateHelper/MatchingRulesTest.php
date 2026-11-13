<?php

namespace Tests\Feature\RateHelper;

use App\Services\RateHelper\CandidatePool;
use App\Services\RateHelper\TextGuards;
use App\Services\RateHelper\TextMatch;
use App\Services\RateHelper\UnitGate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The plain-code rules behind a shortlist: which units go together, how alike two descriptions are, and what must be checked by eye. */
class MatchingRulesTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function unitPairs(): array
    {
        return [
            'the same unit' => ['cum', 'cum', true],
            'two spellings of a volume' => ['cum', 'Cu. M.', true],
            'cum and m3' => ['cum', 'm3', true],
            'cum and m³' => ['CUM', 'm³', true],
            'sqm and m2' => ['sqm', 'M2', true],
            'rmt and m' => ['rmt', 'm', true],
            'nos and each' => ['Nos.', 'each', true],
            'volume against area' => ['cum', 'sqm', false],
            'area against length' => ['sqm', 'rmt', false],
            'count against volume' => ['nos', 'cum', false],
            'kg in two letter cases' => ['kg', 'KG', true],
            'kg with a dot' => ['Kg.', 'kg', true],
            'kg against tonne' => ['kg', 'MT', false],
            'square feet against square metres' => ['sqft', 'sqm', false],
            'bag against kg' => ['bag', 'kg', false],
            'two lump sums' => ['LS', 'L.S.', false],
            'lump sum against a real unit' => ['LS', 'cum', false],
            'a job against a job' => ['job', 'Job', false],
            'blank against blank' => ['', ' ', false],
        ];
    }

    #[DataProvider('unitPairs')]
    public function test_a_rate_is_only_offered_in_the_same_kind_of_unit(string $one, string $other, bool $same): void
    {
        $this->assertSame($same, UnitGate::same($one, $other));
        $this->assertSame($same, UnitGate::same($other, $one));
    }

    public function test_every_way_of_writing_a_lump_sum_is_one(): void
    {
        foreach (['LS', 'L.S.', 'l.s', 'Lumpsum', 'lump sum', 'Lump Sum', 'JOB', 'job'] as $unit) {
            $this->assertTrue(UnitGate::isLumpSum($unit), $unit);
            $this->assertNull(UnitGate::key($unit), $unit);
        }

        foreach (['cum', 'nos', 'kg', 'lot', 'set'] as $unit) {
            $this->assertFalse(UnitGate::isLumpSum($unit), $unit);
            $this->assertNotNull(UnitGate::key($unit), $unit);
        }

        // The list is the company's to extend.
        config(['modules.rate_helper.lump_sum_units' => ['ls', 'lumpsum', 'job', 'lot']]);
        $this->assertTrue(UnitGate::isLumpSum('Lot'));
    }

    public function test_words_are_compared_without_filler_plurals_or_letter_case(): void
    {
        $this->assertSame(['rcc', 'm25', 'slab'], TextMatch::words('Providing and laying RCC M25 in slabs, including all work.'));
        $this->assertSame(['brick', 'masonry', 'cm', '230', 'mm', 'thick'], TextMatch::words('Brick masonry in CM 1:6, 230 mm thick'));
        $this->assertSame(['glass'], TextMatch::words('Glass'));
        $this->assertSame(['काँक्रीट', 'm25'], TextMatch::words('काँक्रीट M२५'));
        $this->assertSame([], TextMatch::words(' - / , '));
    }

    public function test_the_score_is_the_share_of_words_in_common(): void
    {
        $this->assertSame(100, TextMatch::score(3, 3, 3));
        $this->assertSame(66, TextMatch::score(2, 3, 3));
        $this->assertSame(0, TextMatch::score(0, 3, 4));
        $this->assertSame(0, TextMatch::score(0, 0, 0));
        // A short library name inside a long description still scores.
        $this->assertSame(46, TextMatch::score(3, 3, 10));
    }

    public function test_a_pool_lists_only_the_same_unit_best_first_and_never_a_lump_sum(): void
    {
        $pool = new CandidatePool;
        $pool->add(1, 'RCC M25 slab', 'cum');
        $pool->add(2, 'RCC M25 slab with shuttering and curing', 'm3');
        $pool->add(3, 'RCC M25 slab', 'sqm');
        $pool->add(4, 'RCC M25 slab', 'LS');
        $pool->add(5, 'Brick masonry', 'cum');
        $pool->add(6, 'RCC M25 slab', 'cum');
        $pool->add(7, '...', 'cum');

        // Equal scores: the newer row first.
        $this->assertSame([6 => 100, 1 => 100, 2 => 75], $pool->matches('RCC M25 in slab', 'cum', 20, 5));
        $this->assertSame([6 => 100], $pool->matches('RCC M25 in slab', 'cum', 20, 1));
        $this->assertSame([6 => 100, 1 => 100], $pool->matches('RCC M25 in slab', 'cum', 76, 5));
        $this->assertSame([3 => 100], $pool->matches('RCC M25 in slab', 'sqm', 20, 5));
        $this->assertSame([], $pool->matches('RCC M25 in slab', 'LS', 20, 5));
        $this->assertSame([], $pool->matches('RCC M25 in slab', 'rmt', 20, 5));
        $this->assertSame([], $pool->matches('Waterproofing', 'cum', 20, 5));
        $this->assertSame([], $pool->matches('', 'cum', 20, 5));
    }

    /** @return array<string, array{0: string, 1: array<string, list<string>>}> */
    public static function descriptions(): array
    {
        return [
            'grade, written four ways' => ['RCC M25, M-30, M 20 and m15 concrete', ['grade' => ['M15', 'M20', 'M25', 'M30']]],
            'grade in Devanagari digits' => ['काँक्रीट M२५', ['grade' => ['M25']]],
            'a length is not a grade' => ['Pipe 6 m long, M.S. flat, 20 mm metal, MS 25 angle, M 20 mm bolts', ['size' => ['20']]],
            'mix of two and of three' => ['Plaster in CM 1:4 and PCC 1 : 2 : 4, also 1:1.5:3', ['mix' => ['1:1.5:3', '1:2:4', '1:4']]],
            'a clock time is not a mix' => ['Work between 11:30 and 21:45', []],
            'sizes in mm' => ['150 mm thick slab with 12mm and 10-16 mm bars, 230 x 115 mm brick, 12.50 MM board', ['size' => ['10', '115', '12', '12.5', '150', '16', '230']]],
            'a grade beside a size' => ['M25 150 mm thick', ['grade' => ['M25'], 'size' => ['150']]],
            'steel grade' => ['TMT bars Fe500, Fe 500D, FE-415 and fe550 d', ['steel' => ['Fe415', 'Fe500', 'Fe500D', 'Fe550D']]],
            'lead and lift with figures' => ['Excavation with lead upto 50 m and lift upto 1.5 m', ['lead' => ['50 m'], 'lift' => ['1.5 m']]],
            'figure before the word' => ['Carting with 2 km lead and 3 m lift', ['lead' => ['2 km'], 'lift' => ['3 m']]],
            'all leads and lifts' => ['Earthwork including all leads and lifts', ['lead' => ['all'], 'lift' => ['all']]],
            'initial lead in kilometres' => ['Disposal, initial lead of 1 kilometre, all lifts', ['lead' => ['1 km'], 'lift' => ['all']]],
            'lead the metal and lift the machine' => ['CI pipe with lead caulked joints near the lift well 2 m wide', []],
            'nothing to read' => ['Supplying and fixing wash basin', []],
        ];
    }

    #[DataProvider('descriptions')]
    public function test_the_figures_that_change_a_rate_are_read_from_a_description(string $text, array $expected): void
    {
        $blank = array_fill_keys(array_keys(TextGuards::KINDS), []);

        $this->assertSame([...$blank, ...$expected], TextGuards::read($text));
    }

    public function test_a_figure_that_differs_or_is_missing_on_one_side_is_a_warning(): void
    {
        $this->assertSame([], TextGuards::compare('RCC M25 in slab 150 mm thick', 'Slab 150mm in M-25 RCC'));
        $this->assertSame([], TextGuards::compare('Brick work', 'Brick masonry'));

        $this->assertSame(
            [['kind' => 'grade', 'label' => 'Concrete grade', 'line' => ['M25'], 'candidate' => ['M20']]],
            TextGuards::compare('RCC M25 in slab', 'RCC M20 in slab'),
        );

        // Present on one side only, either way round.
        $this->assertSame(
            [['kind' => 'size', 'label' => 'Thickness or size in mm', 'line' => [], 'candidate' => ['150']]],
            TextGuards::compare('RCC M25 in slab', 'RCC M25 in slab 150 mm thick'),
        );
        $this->assertSame(
            [['kind' => 'mix', 'label' => 'Mix ratio', 'line' => ['1:4'], 'candidate' => []]],
            TextGuards::compare('Plaster in CM 1:4', 'Plaster in cement mortar'),
        );

        $kinds = array_column(TextGuards::compare(
            'RCC M25 with Fe500 steel, 1:1.5:3, 125 mm, lead 50 m, lift 1.5 m',
            'RCC M30 with Fe415 steel, 1:2:4, 150 mm, lead 100 m, all lifts',
        ), 'kind');

        $this->assertSame(array_keys(TextGuards::KINDS), $kinds);
    }
}
