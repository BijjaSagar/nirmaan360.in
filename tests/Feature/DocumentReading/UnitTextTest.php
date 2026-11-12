<?php

namespace Tests\Feature\DocumentReading;

use App\Services\DocumentReading\UnitText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnitTextTest extends TestCase
{
    public static function known(): array
    {
        return [
            // The app's own spellings, in any case.
            ['cum', 'cum'], ['CUM', 'cum'], ['Sqm', 'sqm'], ['RMT', 'rmt'], ['Nos', 'nos'], ['KG', 'kg'],
            ['MT', 'MT'], ['mt', 'MT'], ['Ltr', 'ltr'], ['Bag', 'bag'], ['LS', 'LS'], ['ls', 'LS'],

            // Dots and spaces do not matter.
            ['Cu. M.', 'cum'], ['cu.m', 'cum'], ['C U M', 'cum'], ['Sq. M.', 'sqm'], ['sq.m.', 'sqm'],
            ['R.Mt.', 'rmt'], ['Nos.', 'nos'], ['No.', 'nos'], ['M.T.', 'MT'], ['L.S.', 'LS'], ['Kg.', 'kg'],
            ["cu.\u{00A0}m", 'cum'],

            // Every spelling the estimate import accepts gives what it gives.
            ['cumt', 'cum'], ['cumtr', 'cum'], ['cubm', 'cum'], ['m3', 'cum'],
            ['sqmt', 'sqm'], ['sqmtr', 'sqm'], ['m2', 'sqm'],
            ['sqft', 'sqft'], ['sft', 'sqft'],
            ['rm', 'rmt'], ['rmtr', 'rmt'], ['lm', 'rmt'],
            ['m', 'm'], ['mtr', 'm'], ['metre', 'm'], ['meter', 'm'],
            ['ton', 'MT'], ['tons', 'MT'], ['tonne', 'MT'],

            // Raised figures and single-character unit signs.
            ['m³', 'cum'], ['m²', 'sqm'], ['M³', 'cum'], ['㎥', 'cum'], ['㎡', 'sqm'],

            // The unit behind a rate prefix.
            ['Rs/cu.m', 'cum'], ['Rs./Sqm', 'sqm'], ['₹/Rmt', 'rmt'], ['INR / MT', 'MT'], ['रु./घ.मी.', 'cum'],
            ['per cum', 'cum'], ['Per Bag', 'bag'], ['Per.Sqm', 'sqm'], ['/Cum', 'cum'], ['/ Kg', 'kg'], ['प्रति घ.मी.', 'cum'],

            // Other English spellings.
            ['Each', 'nos'], ['Number', 'nos'], ['Numbers', 'nos'], ['Kgs', 'kg'], ['Kilogram', 'kg'],
            ['Litre', 'ltr'], ['Liters', 'ltr'], ['Lit.', 'ltr'], ['Bags', 'bag'], ['Lump Sum', 'LS'], ['Lumpsum', 'LS'],
            ['Tonnes', 'MT'], ['Metric Ton', 'MT'], ['Sq. Ft.', 'sqft'], ['Square Feet', 'sqft'], ['Running Metre', 'rmt'],
            ['Cubic Metre', 'cum'], ['Square Meter', 'sqm'],

            // Marathi.
            ['घ.मी.', 'cum'], ['घ. मी.', 'cum'], ['घन मी.', 'cum'], ['घनमीटर', 'cum'], ['घ॰मी॰', 'cum'],
            ['चौ.मी.', 'sqm'], ['चौ. मी.', 'sqm'], ['चौरस मीटर', 'sqm'],
            ['चौ.फू.', 'sqft'], ['चौ.फूट', 'sqft'], ['चौरस फूट', 'sqft'],
            ['र.मी.', 'rmt'], ['रनिंग मीटर', 'rmt'], ['मी.', 'm'], ['मीटर', 'm'],
            ['नग', 'nos'], ['संख्या', 'nos'],
            ['कि.ग्रॅ.', 'kg'], ['किलो', 'kg'], ['किलोग्रॅम', 'kg'],
            ['मे.टन', 'MT'], ['मेट्रिक टन', 'MT'], ['टन', 'MT'],
            ['लि.', 'ltr'], ['लिटर', 'ltr'],
            ['बॅग', 'bag'], ['पोते', 'bag'], ['पोती', 'bag'], ['गोणी', 'bag'],
            ['एकरकमी', 'LS'],

            // Hindi.
            ['व.मी.', 'sqm'], ['वर्ग मीटर', 'sqm'], ['वर्ग मी.', 'sqm'], ['घन मीटर', 'cum'],
            ['वर्ग फुट', 'sqft'], ['वर्ग फीट', 'sqft'], ["वर्ग \u{095E}ुट", 'sqft'], ['व.फु.', 'sqft'],
            ['अदद', 'nos'], ['कि.ग्रा.', 'kg'], ['किलोग्राम', 'kg'], ['मीट्रिक टन', 'MT'],
            ['लीटर', 'ltr'], ['बैग', 'bag'], ['बोरी', 'bag'], ['एकमुश्त', 'LS'],
        ];
    }

    #[DataProvider('known')]
    public function test_a_known_spelling_becomes_the_apps_unit(string $raw, string $unit): void
    {
        $this->assertSame(['unit' => $unit, 'known' => true], UnitText::normalise($raw), "Unit \"{$raw}\"");
    }

    public static function unknown(): array
    {
        return [
            // Passed through as printed, with spaces tidied.
            ['Brass', 'Brass'], ['Trip', 'Trip'], ['Day', 'Day'], ['Quintal', 'Quintal'], ['  Set  ', 'Set'],
            ["Man\u{00A0}  day", 'Man day'], ['ब्रास', 'ब्रास'], ['Pair', 'Pair'],

            // Close to a known unit is not known.
            ['cumm', 'cumm'], ['sq', 'sq'], ['cu-m', 'cu-m'], ['m^3', 'm^3'], ['percent', 'percent'], ['perch', 'perch'],
            ['Rs', 'Rs'], ['12', '12'], ['%', '%'], ['/', '/'],

            // A prefix alone is not stripped from a unit the list does not have.
            ['per trip', 'per trip'], ['Rs/Trip', 'Rs/Trip'],

            // Cut to sixteen characters.
            ['A very long unit name here', 'A very long unit'],
            ['एक खूप लांब एककाचे नाव', 'एक खूप लांब एकका'],
        ];
    }

    #[DataProvider('unknown')]
    public function test_an_unknown_unit_passes_through_marked_unknown(string $raw, string $unit): void
    {
        $this->assertSame(['unit' => $unit, 'known' => false], UnitText::normalise($raw), "Unit \"{$raw}\"");
        $this->assertLessThanOrEqual(16, mb_strlen(UnitText::normalise($raw)['unit']));
    }

    public function test_a_blank_unit_stays_blank(): void
    {
        foreach (['', '   ', "\u{00A0}", "\t\n", "cum\xFF"] as $raw) {
            $this->assertSame(['unit' => '', 'known' => false], UnitText::normalise($raw));
        }
    }

    public function test_the_word_list_is_in_order(): void
    {
        $list = config('modules.document_reading.unit_words');
        $appUnits = config('buildos.units');

        // Every unit the list writes is one of the app's, or one of the two the estimate import already writes.
        $this->assertSame([], array_diff(array_keys($list), [...$appUnits, 'sqft', 'm']));

        $seen = [];

        foreach ($list as $unit => $spellings) {
            foreach ($spellings as $spelling) {
                $this->assertSame(UnitText::key($spelling), $spelling, "\"{$spelling}\" must be written in lower case without dots or spaces");
                $this->assertNotSame('', $spelling);
                $this->assertArrayNotHasKey($spelling, $seen, "\"{$spelling}\" is listed under two units");
                $seen[$spelling] = $unit;
            }
        }

        // No spelling of one unit is another unit's own name.
        foreach ([...$appUnits, ...array_keys($list)] as $unit) {
            $this->assertContains($seen[UnitText::key($unit)] ?? $unit, [$unit], "\"{$unit}\" is a unit and also a spelling of another unit");
        }

        // What the app itself writes always reads back as itself.
        foreach ($appUnits as $unit) {
            $this->assertSame(['unit' => $unit, 'known' => true], UnitText::normalise($unit));
        }
    }

    public function test_spellings_are_a_list_in_config_not_a_rule(): void
    {
        config(['modules.document_reading.unit_words' => ['cum' => ['brass']]]);

        $this->assertSame(['unit' => 'cum', 'known' => true], UnitText::normalise('Brass'));
        $this->assertSame(['unit' => 'Cu.Mtr', 'known' => false], UnitText::normalise('Cu.Mtr'), 'a spelling taken off the list is no longer known');
        $this->assertSame(['unit' => 'cum', 'known' => true], UnitText::normalise('CUM'), 'the unit itself always is');
    }
}
