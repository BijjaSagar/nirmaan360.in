<?php

namespace Tests\Feature\DocumentReading;

use App\Services\DocumentReading\PrintedNumber;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PrintedNumberTest extends TestCase
{
    /** raw, kind, value, script, had a comma */
    public static function read(): array
    {
        return [
            // Plain numbers.
            ['12', 'qty', '12', 'ascii', false],
            ['12.5', 'qty', '12.5', 'ascii', false],
            ['12.500', 'qty', '12.500', 'ascii', false],
            ['0', 'qty', '0', 'ascii', false],
            ['0.00', 'amount', '0.00', 'ascii', false],
            ['0.5', 'rate', '0.5', 'ascii', false],
            ['02', 'count', '2', 'ascii', false],
            ['0012.50', 'rate', '12.50', 'ascii', false],
            ['385.75', 'rate', '385.75', 'ascii', false],

            // Commas in threes.
            ['12,500', 'amount', '12500', 'ascii', true],
            ['1,234', 'qty', '1234', 'ascii', true],
            ['1,234,567', 'amount', '1234567', 'ascii', true],
            ['123,456,789.25', 'amount', '123456789.25', 'ascii', true],
            ['999,999', 'count', '999999', 'ascii', true],

            // Commas the Indian way.
            ['1,00,000.50', 'amount', '100000.50', 'ascii', true],
            ['1,00,000', 'amount', '100000', 'ascii', true],
            ['12,34,567', 'amount', '1234567', 'ascii', true],
            ['1,23,45,678.90', 'amount', '12345678.90', 'ascii', true],
            ['10,00,00,000', 'amount', '100000000', 'ascii', true],
            ['2,50,000.000', 'qty', '250000.000', 'ascii', true],

            // Devanagari digits, converted one for one.
            ['१२.५००', 'qty', '12.500', 'devanagari', false],
            ['०', 'qty', '0', 'devanagari', false],
            ['१,००,०००.५०', 'amount', '100000.50', 'devanagari', true],
            ['१२,५००', 'amount', '12500', 'devanagari', true],
            ['१२३४५६७८९०', 'amount', '1234567890', 'devanagari', false],
            ['७५', 'count', '75', 'devanagari', false],

            // Digits of one other script, and wide forms of the plain digits.
            ['૧૨.૫', 'qty', '12.5', 'other', false],
            ['১২', 'count', '12', 'other', false],
            ['１２，５００', 'amount', '12500', 'ascii', true],

            // Rupee marks and the closing "/-" are set aside.
            ['500/-', 'amount', '500', 'ascii', false],
            ['500/=', 'amount', '500', 'ascii', false],
            ['500 /-', 'amount', '500', 'ascii', false],
            ['1,250/-', 'amount', '1250', 'ascii', true],
            ['₹500', 'amount', '500', 'ascii', false],
            ['₹ 1,00,000.00', 'amount', '100000.00', 'ascii', true],
            ['Rs 500', 'rate', '500', 'ascii', false],
            ['Rs. 500', 'rate', '500', 'ascii', false],
            ['Rs.500', 'rate', '500', 'ascii', false],
            ['RS. 12.50', 'rate', '12.50', 'ascii', false],
            ['INR 500', 'amount', '500', 'ascii', false],
            ['₨ 500', 'amount', '500', 'ascii', false],
            ['रु 500', 'amount', '500', 'ascii', false],
            ['रु. ५००', 'amount', '500', 'devanagari', false],
            ['रू ५००', 'amount', '500', 'devanagari', false],
            ['५०० रु.', 'amount', '500', 'devanagari', false],
            ['500 Rs', 'amount', '500', 'ascii', false],
            ['500 Rs.', 'amount', '500', 'ascii', false],
            ['500 ₹', 'amount', '500', 'ascii', false],
            ['Rs. 500/-', 'amount', '500', 'ascii', false],
            ['500/- Rs', 'amount', '500', 'ascii', false],
            ['500 Rs/-', 'amount', '500', 'ascii', false],

            // Space around the figure, of any kind.
            ['  12,500  ', 'amount', '12500', 'ascii', true],
            ["\u{00A0}12.5\u{00A0}", 'qty', '12.5', 'ascii', false],
            ["₹\u{00A0}500", 'amount', '500', 'ascii', false],
            ["\t42\n", 'count', '42', 'ascii', false],

            // A closing percent sign, on a percentage only.
            ['5%', 'percent', '5', 'ascii', false],
            ['2.5 %', 'percent', '2.5', 'ascii', false],
            ['०.१२५%', 'percent', '0.125', 'devanagari', false],
            ['12.3456', 'percent', '12.3456', 'ascii', false],

            // The largest values taken.
            ['99999999999', 'qty', '99999999999', 'ascii', false],
            ['99999999999.000', 'qty', '99999999999.000', 'ascii', false],
            ['9999999999999', 'rate', '9999999999999', 'ascii', false],
            ['99,99,99,99,99,999.00', 'amount', '9999999999999.00', 'ascii', true],
            ['999.9999', 'percent', '999.9999', 'ascii', false],
            ['999999999', 'count', '999999999', 'ascii', false],
        ];
    }

    #[DataProvider('read')]
    public function test_reads_a_printed_number(string $raw, string $kind, string $value, string $script, bool $hadComma): void
    {
        $result = PrintedNumber::parse($raw, $kind);

        $this->assertSame(
            ['ok' => true, 'value' => $value, 'reason' => null, 'script' => $script, 'had_comma' => $hadComma],
            $result->toArray(),
            "Reading \"{$raw}\" as {$kind}",
        );
        $this->assertTrue($result->ok);
        $this->assertSame($value, $result->value);
        $this->assertSame($hadComma, $result->hadComma);
    }

    /** raw, kind, reason */
    public static function refused(): array
    {
        return [
            // A comma that could be a decimal point, or groups of the wrong size.
            ['12,50', 'amount', 'ambiguous_separator'],
            ['1,23', 'amount', 'ambiguous_separator'],
            ['1,23,45', 'amount', 'ambiguous_separator'],
            ['1,2345', 'amount', 'ambiguous_separator'],
            ['1,5', 'qty', 'ambiguous_separator'],
            ['12,5', 'qty', 'ambiguous_separator'],
            ['1234,567', 'amount', 'ambiguous_separator'],
            ['123,45,678', 'amount', 'ambiguous_separator'],
            ['1,00,00', 'amount', 'ambiguous_separator'],
            ['1,000,00', 'amount', 'ambiguous_separator'],
            ['1,00,0000', 'amount', 'ambiguous_separator'],
            ['12,,500', 'amount', 'ambiguous_separator'],
            [',500', 'amount', 'ambiguous_separator'],
            ['500,', 'amount', 'ambiguous_separator'],
            ['1,234,56.00', 'amount', 'ambiguous_separator'],
            ['12,500.5,0', 'amount', 'ambiguous_separator'],
            ['१२,५०', 'amount', 'ambiguous_separator'],

            // A grouped number that starts with a zero is more likely a misread decimal point.
            ['0,500', 'qty', 'ambiguous_separator'],
            ['00,500', 'amount', 'ambiguous_separator'],
            ['01,00,000', 'amount', 'ambiguous_separator'],

            // Dots that are not one decimal point.
            ['1.234.567', 'amount', 'ambiguous_separator'],
            ['1.00.000', 'amount', 'ambiguous_separator'],
            ['12.', 'qty', 'ambiguous_separator'],
            ['.5', 'qty', 'ambiguous_separator'],
            ['12..5', 'qty', 'ambiguous_separator'],
            ['1.234,56', 'amount', 'ambiguous_separator'],

            // Two figures, or a gap inside one.
            ['12 500', 'amount', 'ambiguous_separator'],
            ['1 00 000', 'amount', 'ambiguous_separator'],
            ['12, 500', 'amount', 'ambiguous_separator'],
            ['1250 1', 'amount', 'ambiguous_separator'],
            ["12\u{00A0}500", 'amount', 'ambiguous_separator'],
            ["12\u{2009}500", 'amount', 'ambiguous_separator'],
            ['10-12', 'qty', 'ambiguous_separator'],
            ['12/05/2026', 'qty', 'ambiguous_separator'],
            ['3 x 4', 'qty', 'has_letters'],
            ['3*4', 'qty', 'ambiguous_separator'],
            ['1:2', 'qty', 'ambiguous_separator'],
            ["12'500", 'amount', 'ambiguous_separator'],
            ['+500', 'amount', 'ambiguous_separator'],
            ['500/', 'amount', 'ambiguous_separator'],
            ['Rs Rs 500', 'amount', 'has_letters'],
            ['12.5%', 'qty', 'ambiguous_separator'],
            ['5%%', 'percent', 'ambiguous_separator'],
            ["12\u{200B}500", 'amount', 'ambiguous_separator'],
            ['१२।५', 'qty', 'ambiguous_separator'],

            // Letters: a unit in the cell, words, or a letter standing in for a digit.
            ['12.5 cum', 'qty', 'has_letters'],
            ['12.5cum', 'qty', 'has_letters'],
            ['LS', 'amount', 'has_letters'],
            ['L.S.', 'rate', 'has_letters'],
            ['1O0', 'amount', 'has_letters'],
            ['O.5', 'qty', 'has_letters'],
            ['l2', 'qty', 'has_letters'],
            ['12 nos', 'count', 'has_letters'],
            ['१२ नग', 'count', 'has_letters'],
            ['Nil', 'amount', 'has_letters'],
            ['500 only', 'amount', 'has_letters'],
            ['रुपये 500', 'amount', 'has_letters'],
            ['500 रुपये', 'amount', 'has_letters'],
            ['Rsx 500', 'amount', 'has_letters'],
            ['1.2e3', 'qty', 'has_letters'],
            ['0x1F', 'count', 'has_letters'],
            ['5 lakh', 'amount', 'has_letters'],
            ['५ लाख', 'amount', 'has_letters'],
            ['500 Cr', 'amount', 'has_letters'],
            ['12.5 percent', 'percent', 'has_letters'],

            // Two digit scripts in one cell.
            ['१2.५', 'qty', 'mixed_script'],
            ['1२', 'count', 'mixed_script'],
            ['१२,500', 'amount', 'mixed_script'],
            ['૧२', 'count', 'mixed_script'],
            ['१२ 5', 'qty', 'mixed_script'],

            // Raised figures, circled figures and one-character fractions are not the digits they turn into.
            ['1250¹', 'amount', 'unusual_digits'],
            ['12²', 'qty', 'unusual_digits'],
            ['2½', 'qty', 'unusual_digits'],
            ['½', 'qty', 'unusual_digits'],
            ['①', 'count', 'unusual_digits'],
            ['12₃', 'qty', 'unusual_digits'],

            // Below zero, however it is written.
            ['-500', 'amount', 'negative'],
            ['−500', 'amount', 'negative'],
            ['–500', 'amount', 'negative'],
            ['500-', 'amount', 'negative'],
            ['500/-/-', 'amount', 'negative'],
            ['(500)', 'amount', 'negative'],
            ['(1,250.00)', 'amount', 'negative'],
            ['-१२', 'qty', 'negative'],
            ['Rs. -500', 'amount', 'negative'],
            ['-0', 'qty', 'negative'],

            // More decimals than the kind holds.
            ['12.5001', 'qty', 'too_many_decimals'],
            ['12.5000', 'qty', 'too_many_decimals'],
            ['12.500', 'rate', 'too_many_decimals'],
            ['12.500', 'amount', 'too_many_decimals'],
            ['1,00,000.505', 'amount', 'too_many_decimals'],
            ['१२.५००', 'amount', 'too_many_decimals'],
            ['5.0', 'count', 'too_many_decimals'],
            ['12.34567', 'percent', 'too_many_decimals'],

            // Over the largest value.
            ['100000000000', 'qty', 'too_large'],
            ['99999999999.001', 'qty', 'too_large'],
            ['10000000000000', 'rate', 'too_large'],
            ['1,00,00,00,00,00,000', 'amount', 'too_large'],
            ['1000', 'percent', 'too_large'],
            ['1000000000', 'count', 'too_large'],
            [str_repeat('9', 400), 'amount', 'too_large'],

            // Nothing to read.
            ['', 'qty', 'empty'],
            ['   ', 'qty', 'empty'],
            ["\u{00A0}", 'amount', 'empty'],
            ['-', 'rate', 'no_digits'],
            ['—', 'rate', 'no_digits'],
            ['--', 'amount', 'no_digits'],
            ['.', 'amount', 'no_digits'],
            ['/-', 'amount', 'no_digits'],
            ['Rs.', 'amount', 'no_digits'],
            ['₹', 'amount', 'no_digits'],
            ['%', 'percent', 'no_digits'],
            ['( )', 'amount', 'no_digits'],

            // Bytes that are not text.
            ["12\xFF", 'qty', 'not_text'],
        ];
    }

    #[DataProvider('refused')]
    public function test_refuses_what_it_cannot_read_one_way(string $raw, string $kind, string $reason): void
    {
        $result = PrintedNumber::parse($raw, $kind);

        $this->assertFalse($result->ok, "\"{$raw}\" as {$kind} was read as {$result->value}");
        $this->assertNull($result->value);
        $this->assertSame($reason, $result->reason, "Refusing \"{$raw}\" as {$kind}");
    }

    public function test_a_refusal_still_says_which_script_and_whether_a_comma_was_printed(): void
    {
        $this->assertSame(
            ['ok' => false, 'value' => null, 'reason' => 'ambiguous_separator', 'script' => 'devanagari', 'had_comma' => true],
            PrintedNumber::parse('१२,५०', 'amount')->toArray(),
        );
        $this->assertSame('mixed', PrintedNumber::parse('१2.५', 'qty')->script);
        $this->assertSame('ascii', PrintedNumber::parse('LS', 'rate')->script);
        $this->assertFalse(PrintedNumber::parse('12.5 cum', 'qty')->hadComma);
    }

    public function test_the_same_text_is_judged_by_its_kind(): void
    {
        $this->assertTrue(PrintedNumber::parse('12.500', 'qty')->ok);
        $this->assertFalse(PrintedNumber::parse('12.500', 'rate')->ok);
        $this->assertFalse(PrintedNumber::parse('12.5', 'count')->ok);
        $this->assertTrue(PrintedNumber::parse('5%', 'percent')->ok);
        $this->assertFalse(PrintedNumber::parse('5%', 'amount')->ok);
    }

    public function test_every_kind_has_its_decimals_and_its_largest_value(): void
    {
        $this->assertSame(['qty', 'rate', 'amount', 'percent', 'count'], array_keys(PrintedNumber::DECIMALS));
        $this->assertSame(array_keys(PrintedNumber::DECIMALS), array_keys(PrintedNumber::LARGEST));
    }

    public function test_an_unknown_kind_is_a_mistake_in_the_caller(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PrintedNumber::parse('12', 'weight');
    }

    public function test_the_value_is_always_a_plain_decimal_string(): void
    {
        foreach (self::read() as [$raw, $kind]) {
            $this->assertMatchesRegularExpression('/^(0|[1-9]\d*)(\.\d+)?$/', PrintedNumber::parse($raw, $kind)->value);
        }
    }
}
