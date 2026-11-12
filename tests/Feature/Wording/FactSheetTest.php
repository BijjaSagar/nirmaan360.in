<?php

namespace Tests\Feature\Wording;

use App\Services\Wording\Fact;
use App\Services\Wording\FactFormat;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Services\Wording\WordingException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Facts keep one form per kind, print the same way every time, and a sheet's fingerprint follows the records. */
class FactSheetTest extends WordingTestCase
{
    private function fact(string $kind, mixed $value, string $key = 'figure'): Fact
    {
        return new Fact($key, $kind, $value, 'A figure', FactSource::record('ra_bill', 7, 'field'));
    }

    public function test_a_fact_keeps_one_form_per_kind(): void
    {
        $this->assertSame('1500.00', $this->fact(Fact::MONEY, '1500')->value);
        $this->assertSame('1500.01', $this->fact(Fact::MONEY, '1500.005')->value);
        $this->assertSame('-250.00', $this->fact(Fact::MONEY, -250)->value);
        $this->assertSame('12.500', $this->fact(Fact::QTY, '12.5')->value);
        $this->assertSame('42.5', $this->fact(Fact::PERCENT, '42.5000')->value);
        $this->assertSame('30', $this->fact(Fact::PERCENT, '30.00')->value);
        $this->assertSame('0', $this->fact(Fact::PERCENT, '0.00')->value);
        $this->assertSame('14', $this->fact(Fact::DAYS, '14.000')->value);
        $this->assertSame('1250', $this->fact(Fact::COUNT, 1250)->value);
        $this->assertSame('2026-11-05', $this->fact(Fact::DATE, Carbon::parse('2026-11-05 18:30', 'Asia/Kolkata'))->value);
        $this->assertSame('2026-11-05', $this->fact(Fact::DATE, '2026-11-05')->value);
        $this->assertSame('RA-P101-003', $this->fact(Fact::REFERENCE, '  RA-P101-003 ')->value);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function valuesThatAreNotAFigure(): array
    {
        return [
            'nothing, which Dec would read as zero' => [Fact::MONEY, null],
            'an empty string' => [Fact::MONEY, ''],
            'spaces only' => [Fact::QTY, '   '],
            'a float' => [Fact::MONEY, 1500.5],
            'a float quantity' => [Fact::QTY, 0.1],
            'an exponent' => [Fact::MONEY, '1e3'],
            'grouped digits' => [Fact::MONEY, '1,50,000.00'],
            'a rupee sign' => [Fact::MONEY, '₹1500'],
            'Devanagari digits' => [Fact::MONEY, '१५००'],
            'words' => [Fact::QTY, 'twelve'],
            'a fraction' => [Fact::PERCENT, '1/3'],
            'a count with a part' => [Fact::COUNT, '2.5'],
            'a count below zero' => [Fact::COUNT, '-1'],
            'days below zero' => [Fact::DAYS, -3],
            'a date that does not exist' => [Fact::DATE, '2026-02-30'],
            'a date day first' => [Fact::DATE, '05-11-2026'],
            'a date with a time' => [Fact::DATE, '2026-11-05 10:00:00'],
            'an array as text' => [Fact::TEXT, ['x']],
            'empty text' => [Fact::TEXT, ' '],
        ];
    }

    #[DataProvider('valuesThatAreNotAFigure')]
    public function test_a_fact_refuses_a_value_that_is_not_a_figure(string $kind, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->fact($kind, $value);
    }

    public function test_a_fact_refuses_a_wrong_key_kind_or_label(): void
    {
        $source = FactSource::computed('x');

        foreach ([
            fn () => new Fact('Bill', Fact::MONEY, '1', 'Label', $source),
            fn () => new Fact('bill-1', Fact::MONEY, '1', 'Label', $source),
            fn () => new Fact('1bill', Fact::MONEY, '1', 'Label', $source),
            fn () => new Fact('bill', 'rupees', '1', 'Label', $source),
            fn () => new Fact('bill', Fact::MONEY, '1', ' ', $source),
            fn () => new FactSource(''),
        ] as $wrong) {
            try {
                $wrong();
                $this->fail('A wrongly declared fact was accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_figures_print_the_same_in_every_language_with_ascii_digits(): void
    {
        foreach (FactFormat::LOCALES as $locale) {
            $this->assertSame('₹12,34,567.50', FactFormat::display(Fact::MONEY, '1234567.50', $locale));
            $this->assertSame('1,234.500', FactFormat::display(Fact::QTY, '1234.500', $locale));
            $this->assertSame('42.5%', FactFormat::display(Fact::PERCENT, '42.5', $locale));
            $this->assertSame('1,250', FactFormat::display(Fact::COUNT, '1250', $locale));
            $this->assertSame('14', FactFormat::display(Fact::DAYS, '14', $locale));
            $this->assertSame('RA-P101-003', FactFormat::display(Fact::REFERENCE, 'RA-P101-003', $locale));

            foreach ([Fact::MONEY, Fact::QTY, Fact::PERCENT, Fact::COUNT, Fact::DAYS] as $kind) {
                $printed = FactFormat::display($kind, '987654', $locale);
                $this->assertSame(0, preg_match('/\p{N}/u', (string) preg_replace('/[0-9]/', '', $printed)), "{$kind} in {$locale}: {$printed}");
            }

            $date = FactFormat::display(Fact::DATE, '2026-11-05', $locale);
            $this->assertSame(0, preg_match('/\p{N}/u', (string) preg_replace('/[0-9]/', '', $date)), $date);
        }

        $this->assertSame('5 November 2026', FactFormat::display(Fact::DATE, '2026-11-05', 'en'));
        $this->assertSame('5 नवंबर 2026', FactFormat::display(Fact::DATE, '2026-11-05', 'hi'));
        $this->assertSame('5 नोव्हेंबर 2026', FactFormat::display(Fact::DATE, '2026-11-05', 'mr'));
        $this->assertSame('31 मे 2027', FactFormat::display(Fact::DATE, '2027-05-31', 'mr'));
    }

    public function test_a_language_other_than_the_three_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FactFormat::display(Fact::MONEY, '10.00', 'gu');
    }

    public function test_a_sheet_holds_each_key_once_and_knows_its_facts(): void
    {
        $sheet = $this->sheet();

        $this->assertCount(5, $sheet);
        $this->assertSame(['client_name', 'bill_1_number', 'bill_1_outstanding', 'as_on', 'days_open'], $sheet->keys());
        $this->assertSame('150000.00', $sheet->get('bill_1_outstanding')->value);
        $this->assertSame('₹1,50,000.00', $sheet->get('bill_1_outstanding')->display('mr'));
        $this->assertNull($sheet->find('nothing'));
        $this->assertTrue($sheet->hasKind(Fact::MONEY));
        $this->assertFalse($sheet->hasKind(Fact::PERCENT));

        try {
            $sheet->get('nothing');
            $this->fail('A fact that is not on the sheet was returned.');
        } catch (WordingException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(InvalidArgumentException::class);
        FactSheet::of($this->fact(Fact::MONEY, '1'), $this->fact(Fact::MONEY, '2'));
    }

    public function test_the_fingerprint_is_the_same_for_the_same_records_however_they_were_read(): void
    {
        $built = fn (mixed $money, mixed $percent, int|string $id) => FactSheet::of(
            new Fact('amount', Fact::MONEY, $money, 'Amount', FactSource::record('ra_bill', $id, 'net_payable')),
            new Fact('done', Fact::PERCENT, $percent, 'Work done', FactSource::computed('done')),
        );

        // SQLite and PostgreSQL hand back the same decimal in different spellings.
        $this->assertSame($built('1500', '42.50', 7)->fingerprint(), $built('1500.00', '42.5000', '7')->fingerprint());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $built('1500', '42.5', 7)->fingerprint());

        $stored = FactSheet::fromArray(json_decode(json_encode($this->sheet()->toArray()), true));
        $this->assertSame($this->sheet()->fingerprint(), $stored->fingerprint());
        $this->assertTrue($this->sheet()->matches($stored->fingerprint()));
    }

    public function test_a_label_is_wording_and_does_not_change_the_fingerprint(): void
    {
        $source = FactSource::record('ra_bill', 7, 'net_payable');

        $this->assertSame(
            FactSheet::of(new Fact('amount', Fact::MONEY, '10', 'Amount', $source))->fingerprint(),
            FactSheet::of(new Fact('amount', Fact::MONEY, '10', 'रक्कम', $source))->fingerprint(),
        );
    }

    public function test_the_fingerprint_changes_with_any_value_key_kind_source_or_order(): void
    {
        $source = FactSource::record('ra_bill', 7, 'net_payable');
        $other = new Fact('days', Fact::DAYS, '14', 'Days', FactSource::computed('days'));
        $base = FactSheet::of(new Fact('amount', Fact::MONEY, '1500.00', 'Amount', $source), $other);

        $changed = [
            'value' => FactSheet::of(new Fact('amount', Fact::MONEY, '1500.01', 'Amount', $source), $other),
            'key' => FactSheet::of(new Fact('amount_due', Fact::MONEY, '1500.00', 'Amount', $source), $other),
            'kind' => FactSheet::of(new Fact('amount', Fact::QTY, '1500.00', 'Amount', $source), $other),
            'record' => FactSheet::of(new Fact('amount', Fact::MONEY, '1500.00', 'Amount', FactSource::record('ra_bill', 8, 'net_payable')), $other),
            'field' => FactSheet::of(new Fact('amount', Fact::MONEY, '1500.00', 'Amount', FactSource::record('ra_bill', 7, 'gross_amount')), $other),
            'record type' => FactSheet::of(new Fact('amount', Fact::MONEY, '1500.00', 'Amount', FactSource::record('vendor_bill', 7, 'net_payable')), $other),
            'order' => FactSheet::of($other, new Fact('amount', Fact::MONEY, '1500.00', 'Amount', $source)),
            'a fact more' => FactSheet::of(new Fact('amount', Fact::MONEY, '1500.00', 'Amount', $source), $other, new Fact('more', Fact::COUNT, '1', 'More', FactSource::computed('more'))),
            'a fact less' => FactSheet::of(new Fact('amount', Fact::MONEY, '1500.00', 'Amount', $source)),
        ];

        foreach ($changed as $what => $sheet) {
            $this->assertNotSame($base->fingerprint(), $sheet->fingerprint(), "A different {$what} gave the same fingerprint.");
            $this->assertFalse($sheet->matches($base->fingerprint()), $what);
        }

        $this->assertFalse($base->matches(null));
        $this->assertFalse($base->matches(''));
    }
}
