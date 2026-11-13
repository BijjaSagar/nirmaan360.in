<?php

namespace Tests\Feature\DocumentReading;

use App\Services\DocumentReading\PrintedDate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrintedDateTest extends TestCase
{
    public static function read(): array
    {
        return [
            // Day, month, year with one separator.
            ['12-05-2026', '2026-05-12'],
            ['12/05/2026', '2026-05-12'],
            ['12.05.2026', '2026-05-12'],
            ['1-1-2026', '2026-01-01'],
            ['01/02/2026', '2026-02-01'],
            ['31/12/2025', '2025-12-31'],
            ['29-02-2028', '2028-02-29'],
            [' 12 / 05 / 2026 ', '2026-05-12'],

            // Always day first, also where a month-first reading would be a date too.
            ['05/06/2026', '2026-06-05'],
            ['06/05/2026', '2026-05-06'],

            // A two-digit year is 20yy.
            ['12-05-26', '2026-05-12'],
            ['12/05/26', '2026-05-12'],
            ['1.4.25', '2025-04-01'],
            ['31-12-99', '2099-12-31'],

            // Devanagari digits.
            ['१२/०५/२०२६', '2026-05-12'],
            ['१२-०५-२६', '2026-05-12'],
            ['०१.०४.२०२५', '2025-04-01'],

            // A month name, in English.
            ['12 May 2026', '2026-05-12'],
            ['12 may 2026', '2026-05-12'],
            ['12 MAY 2026', '2026-05-12'],
            ['1 January 2026', '2026-01-01'],
            ['12-Jan-2026', '2026-01-12'],
            ['12-Jan-26', '2026-01-12'],
            ['1-Apr-25', '2025-04-01'],
            ['12 Sept. 2026', '2026-09-12'],
            ['12 Sep 2026', '2026-09-12'],
            ['12 December, 2026', '2026-12-12'],
            ['12May2026', '2026-05-12'],
            ['3/Feb/2026', '2026-02-03'],

            // In Marathi.
            ['१२ मे २०२६', '2026-05-12'],
            ['१२ जानेवारी, २०२६', '2026-01-12'],
            ['5 फेब्रुवारी 2026', '2026-02-05'],
            ['१ एप्रिल २०२५', '2025-04-01'],
            ['15 ऑगस्ट 2026', '2026-08-15'],
            ['२ ऑक्टोबर २०२६', '2026-10-02'],
            ['30 नोव्हेंबर 2026', '2026-11-30'],
            ['25 डिसेंबर 2026', '2026-12-25'],
            ['7 जुलै 2026', '2026-07-07'],
            ['9 सप्टेंबर 2026', '2026-09-09'],

            // In Hindi, with either spelling of the nasal and either way a nukta is typed.
            ['5 मई 2026', '2026-05-05'],
            ['१५ जनवरी २०२६', '2026-01-15'],
            ['1 फरवरी 2026', '2026-02-01'],
            ["1 \u{095E}रवरी 2026", '2026-02-01'],
            ["1 \u{092B}\u{093C}रवरी 2026", '2026-02-01'],
            ['10 अप्रैल 2026', '2026-04-10'],
            ['4 जुलाई 2026', '2026-07-04'],
            ['15 अगस्त 2026', '2026-08-15'],
            ['5 सितंबर 2026', '2026-09-05'],
            ['5 सितम्बर 2026', '2026-09-05'],
            ['2 अक्टूबर 2026', '2026-10-02'],
            ['2 अक्तूबर 2026', '2026-10-02'],
            ['14 नवंबर 2026', '2026-11-14'],
            ['14 नवम्बर 2026', '2026-11-14'],
            ['25 दिसंबर 2026', '2026-12-25'],
            ['3 मार्च 2026', '2026-03-03'],
            ['21 जून 2026', '2026-06-21'],
        ];
    }

    #[DataProvider('read')]
    public function test_reads_a_printed_date_day_first(string $raw, string $date): void
    {
        $this->assertSame($date, PrintedDate::parse($raw), "Reading \"{$raw}\"");
    }

    public static function notRead(): array
    {
        return [
            // Not a day of the calendar.
            ['31-02-2026'], ['29-02-2026'], ['31/04/2026'], ['00-05-2026'], ['12-00-2026'], ['32/01/2026'], ['12-13-2026'],
            // Month first: never swapped round to make it fit.
            ['05/13/2026'], ['May 12, 2026'], ['Jan 2026'], ['2026-05-12'], ['2026/05/12'], ['20260512'],
            // Two separators, or none.
            ['12-05/2026'], ['12.05-2026'], ['12 05 2026'], ['12052026'],
            // Years that are not four or two digits, or far from any document.
            ['12-05-026'], ['12-05-20261'], ['12-05-1899'], ['12-05-2100'], ['12 May 1850'],
            // More than a date.
            ['12/05/2026 10:30'], ['Dt. 12/05/2026'], ['Date: 12-05-2026'], ['12-05-2026.'], ['12/05/2026 to 15/05/2026'],
            ['12th May 2026'], ['12 May'], ['May 2026'], ['12 day 2026'], ['12 Smarch 2026'], ['12 मे महिना 2026'],
            // Two digit scripts in one date, and raised figures.
            ['१2-05-2026'], ['12 जानेवारी, २०२६'], ['12-05-2026¹'],
            // Nothing.
            [''], ['   '], ['-'], ['nil'], ["12-05-2026\xFF"],
        ];
    }

    #[DataProvider('notRead')]
    public function test_anything_else_is_not_a_date(string $raw): void
    {
        $this->assertNull(PrintedDate::parse($raw), "\"{$raw}\" was read as a date");
    }

    public function test_month_names_come_from_the_module_config(): void
    {
        $months = config('modules.document_reading.month_words');

        $this->assertSame(range(1, 12), array_keys($months));

        $seen = [];

        foreach ($months as $month => $names) {
            foreach ($names as $name) {
                $this->assertSame(mb_strtolower($name), $name, "{$name} is compared in lower case");
                $this->assertArrayNotHasKey($name, $seen, "{$name} is listed under two months");
                $seen[$name] = $month;
            }
        }

        config(['modules.document_reading.month_words' => [5 => ['floreal']]]);

        $this->assertSame('2026-05-12', PrintedDate::parse('12 Floreal 2026'));
        $this->assertNull(PrintedDate::parse('12 May 2026'));
    }
}
