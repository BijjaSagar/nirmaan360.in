<?php

namespace App\Services\Imports;

use App\Support\Dec;
use Brick\Math\BigDecimal;

/**
 * How the cells of a contractor's sheet are read: numbers written the Indian way or in Devanagari
 * digits, units in their many spellings, and the marks that say a line is priced by rate only.
 *
 * Nothing here guesses a figure. A cell that is not plainly a number reads as no number.
 */
final class CellValues
{
    /** Devanagari digits and the ASCII digits they stand for. */
    public const DIGITS = ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9'];

    /**
     * Units and the spellings that mean them, as seen in schedules, estimates and BOQs across India, compared after
     * the text is lower-cased and its spaces, dots, slashes and brackets taken out. Metre is the running metre, as
     * the schedules print it for linear work. Brass stays brass (100 cft of sand or aggregate); it is never turned
     * into another unit. A unit not listed here is kept as it was typed.
     */
    private const UNITS = [
        'cum' => ['cum', 'cumt', 'cumtr', 'cumetre', 'cumeter', 'cubm', 'cubicmetre', 'cubicmeter', 'cubicmetres', 'cubicmeters', 'cubicmtr', 'metercube', 'metrecube',
            'm3', 'm³', 'क्यूबिकमीटर', 'घनमीटर', 'घमी'],
        'sqm' => ['sqm', 'sqmt', 'sqmtr', 'sqmetre', 'sqmeter', 'squaremetre', 'squaremeter', 'squaremetres', 'squaremeters', 'sq', 'm2', 'm²', 'स्क्वायरमीटर', 'वर्गमीटर', 'वमी'],
        'rmt' => ['rmt', 'rmtr', 'rm', 'runningmetre', 'runningmeter', 'runningmetres', 'runningmeters', 'runningm', 'runningmtr', 'lm', 'metre', 'meter', 'metres', 'meters',
            'met', 'm', 'mtr', 'mtrs', 'रनिंगमीटर', 'मीटर', 'मि', 'मी'],
        'nos' => ['nos', 'no', 'number', 'numbers', 'each', 'ea', 'pcs', 'pc', 'piece', 'pieces', 'संख्या', 'सं', 'प्रत्येक', 'नग', 'পিস', 'টি'],
        'kg' => ['kg', 'kgs', 'kilogram', 'kilograms', 'kilogramme', 'kilogrammes', 'किलोग्राम', 'किग्रा', 'किलो', 'கிலோ', 'কেজি'],
        'MT' => ['metricton', 'metrictonne', 'ton', 'tons', 'tonne', 'tonnes', 'टन', 'டன்', 'టన్నుల', 'টন'],
        'qtl' => ['qtl', 'qntl', 'quintal', 'quintals', 'क्विंटल'],
        'ltr' => ['ltr', 'ltrs', 'litre', 'liter', 'litres', 'liters', 'lit', 'l', 'लिटर', 'लि'],
        'brass' => ['brass'],
        'sqft' => ['sqft', 'sft', 'sqfeet', 'squarefeet', 'squarefoot', 'स्क्वेअरफुट', 'சதுரஅடி', 'చదరపుఅడుగుల', 'ಚದರಅಡಿ', 'ચોરસફૂટ', 'বর্গফুট', 'স্কয়ারফিট'],
        'cft' => ['cft', 'cuft', 'cubicft', 'cubicfeet', 'கனஅடி', 'క్యూబిక్ఫీట్ల', 'ঘনফুট', 'সিএফটি'],
        'rft' => ['rft', 'runningft', 'runningfeet', 'runningfoot', 'రన్నింగ్ఫీట్లు', 'फुट', 'फूट'],
        'LS' => ['ls', 'lumpsum', 'lumsum', 'lump', 'job', 'eachjob', 'lot', 'कार्य'],
        'set' => ['set', 'sets', 'eachset', 'समूह', 'सैट'],
        'pair' => ['pair', 'pairs', 'जोड़ा'],
        'point' => ['point', 'points', 'pt', 'pts', 'बिन्दु', 'పాయింట్లు'],
        'bag' => ['bag', 'bags', 'गोणी'],
        'day' => ['day', 'days'],
        'month' => ['month', 'months'],
        'hr' => ['hr', 'hrs', 'hour', 'hours'],
        'km' => ['km', 'kms', 'kilometre', 'kilometer', 'kilometres', 'kilometers'],
        'joint' => ['joint', 'joints'],
        'sqcm' => ['sqcm', 'cm2'],
        'mm' => ['mm'],
        'cm' => ['cm'],
        '%' => ['%', 'percent', 'percentage', 'pct', 'टक्के', 'प्रतिशत'],
    ];

    /**
     * Spellings read as a unit that no schedule or BOQ studied was seen to print, or only with the meaning
     * inferred: the local-language short forms and a few rare English ones. They are read, and the page lists
     * each one with the unit it was read as, so the person can check it.
     */
    private const SHOWN = [
        'cmt' => 'cum', 'घनमी' => 'cum', 'घमि' => 'cum',
        'चौमी' => 'sqm', 'चौमि' => 'sqm', 'चौरसमीटर' => 'sqm',
        'rgm' => 'rmt', 'rnm' => 'rmt', 'रमी' => 'rmt', 'रनिंगमी' => 'rmt', 'रनिंगमिटर' => 'rmt',
        'नं' => 'nos', 'मेटन' => 'MT',
        'चौफुट' => 'sqft', 'चौफू' => 'sqft', 'चौफूट' => 'sqft', 'वर्गफुट' => 'sqft', 'घनफुट' => 'cft', 'रनिंगफुट' => 'rft',
        'ब्रास' => 'brass', 'लमसम' => 'LS', 'एकमुश्त' => 'LS', 'एकरकमी' => 'LS', 'लीटर' => 'ltr', 'जोडी' => 'pair', 'जोड़ी' => 'pair',
        'पॉइंट' => 'point', 'बोरी' => 'bag', 'दिवस' => 'day', 'दिन' => 'day', 'रोज' => 'day', 'महिना' => 'month', 'महीना' => 'month',
        'तास' => 'hr', 'घंटा' => 'hr', 'perday' => 'day',
    ];

    /**
     * Spellings that mean more than one unit, or that no source expands: "Mt" (tonne or metre), "q" and "t", "Smt",
     * and "unit" itself (a southern measure of sand, or the heading printed again). They are kept as written and
     * the person is asked to check them; "Mt" is read from the item's own words when they say which it is.
     */
    private const ASK = ['mt', 'q', 't', 'smt', 'unit', 'units'];

    /** Words for how many units a rate is for: "PER TEN SQUARE (metre)", "Dozen", "Thousand". */
    private const COUNTS = ['ten' => '10', 'hundred' => '100', 'thousand' => '1000', 'dozen' => '12', 'डजन' => '12'];

    /** What a quantity cell says when a line is priced by rate only: quote rate only, rate only, R.O. */
    private const RATE_ONLY = ['qro', 'rateonly', 'ro', 'qrateonly', 'quoterateonly', 'quoterate', 'rateonlyitem', 'ratesonly', 'फक्तदर', 'केवलदर', 'सिर्फदर'];

    /** @var array<string, string>|null Spelling => unit, built from UNITS on first use. */
    private static ?array $lookup = null;

    /** Devanagari digits written as ASCII ones. */
    public static function asciiDigits(string $text): string
    {
        return strtr($text, self::DIGITS);
    }

    /**
     * An exact decimal string, or null when the cell holds no plain number.
     *
     * Takes 1,23,456.50 and 123,456.50, Devanagari digits, a rupee sign, Rs. or INR before the figure,
     * "/-" after it, and a minus sign or brackets for a negative figure.
     */
    public static function number(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? self::trimZeros(number_format($value, 6, '.', '')) : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = trim(self::asciiDigits($value));

        if ($text === '') {
            return null;
        }

        $text = (string) preg_replace('/^(?:₹|rs\.?|inr|रु\.?|रुपये)\s*/iu', '', $text);
        $text = (string) preg_replace('/\s*(?:\/-|\/=|-\/-)$/u', '', $text);
        $negative = false;

        if (preg_match('/^\((.*)\)$/u', $text, $match)) {
            $negative = true;
            $text = $match[1];
        }

        $text = str_replace([',', ' ', "\u{00A0}", '₹'], '', $text);

        if (str_starts_with($text, '-') || str_starts_with($text, '−')) {
            $negative = true;
            $text = (string) preg_replace('/^[-−]/u', '', $text);
        }

        if (preg_match('/^(\d+(\.\d*)?|\.\d+)$/', $text) !== 1 || strlen($text) > 30) {
            return null;
        }

        $number = BigDecimal::of(str_starts_with($text, '.') ? '0'.$text : rtrim($text, '.'));
        $text = self::trimZeros((string) $number);

        return $negative && ! $number->isZero() ? '-'.$text : $text;
    }

    /** Whether the cell holds a number. */
    public static function isNumber(mixed $value): bool
    {
        return self::number($value) !== null;
    }

    /**
     * A quantity written with its unit, as "12.5 Cum", "6 Nos" or "१० घ.मी.", or a lump sum written as
     * "L.S.", "Lump sum" or "Job": the number and the unit. Null for anything else, a plain number included.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function quantityWithUnit(mixed $value): ?array
    {
        if (! is_string($value) || self::number($value) !== null) {
            return null;
        }

        $text = trim(self::asciiDigits($value));

        if (self::knownUnit($text) === 'LS') {
            return ['1', 'LS'];
        }

        if (preg_match('/^([\d,]*\.?\d+)\s*([^\d\s].*)$/u', $text, $match) !== 1 || ($number = self::number($match[1])) === null) {
            return null;
        }

        $unit = self::knownUnit($match[2]);

        return $unit === null || $unit === '%' ? null : [$number, $unit];
    }

    /**
     * What a "Per" cell says the rate is for: "100 Sqm", "10 cum", "Qtl", "per 1 MT". The count and the
     * unit, the unit null when the cell names none. Null when the cell is not of that form.
     *
     * @return array{0: string, 1: ?string}|null
     */
    public static function per(mixed $value): ?array
    {
        if (($number = self::number($value)) !== null) {
            return Dec::of($number)->isPositive() ? [$number, null] : null;
        }

        $reading = is_string($value) ? self::unitReading($value) : null;

        if ($reading === null || $reading['unit'] === '%' || in_array($reading['how'], ['raw', 'ask'], true)) {
            return null;
        }

        return [$reading['count'] ?? '1', $reading['unit']];
    }

    /** A spreadsheet error shown in a cell, such as #REF! or #VALUE!: a formula that went wrong, never a value. */
    public static function isError(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#(REF!|VALUE!|N\/A|DIV\/0!|NAME\?|NUM!|NULL!|SPILL!|CALC!|GETTING_DATA)$/i', trim($value)) === 1;
    }

    /** Whether a quantity or rate cell marks the line as priced by rate only, such as QRO or "Rate only". */
    public static function isRateOnly(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $key = (string) preg_replace('/[^\p{L}\p{M}]/u', '', mb_strtolower($value));

        // "Rate only basis", "Quote rate only", "Rates only*": the mark with a word around it.
        return $key !== '' && (in_array($key, self::RATE_ONLY, true) || str_contains($key, 'rateonly') || str_contains($key, 'ratesonly') || str_starts_with($key, 'quoterate'));
    }

    /**
     * The unit in its usual short form: cum, sqm, rmt, nos, kg, MT, LS and so on. "Rs/cu.m", "per sqm", "One
     * Cubic Metre", "1 Cum (One cubic metre)" and "प्रति घन मीटर" become the unit alone. A unit for many, as "100
     * Sqm", is the unit; unitReading() says how many. Null when the cell is empty. A unit not known here, or one
     * that could mean two units ("Mt"), is returned as it was typed, without the "Rs/" in front of it.
     */
    public static function unit(mixed $value): ?string
    {
        return self::unitReading($value)['unit'] ?? null;
    }

    /**
     * How a unit cell reads: the unit, how many of it the rate is for ("100 Sqm", "1000 Nos", "Dozen"; null for
     * one), the cell as written, and how sure the reading is: "known" for a spelling seen in schedules, "shown"
     * for one read as a unit but to be shown to the person, "ask" for one kept as written because it could mean
     * two units or none, and "raw" for one not known at all.
     *
     * @return array{unit: string, count: ?string, written: string, how: string}|null
     */
    public static function unitReading(mixed $value): ?array
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $written = trim((string) preg_replace('/\s+/u', ' ', (string) $value));
        $text = self::bareUnit($written);

        if ($text === '') {
            return null;
        }

        if (($found = self::lookUp($text)) !== null) {
            return ['unit' => $found[0], 'count' => null, 'written' => $written, 'how' => $found[1]];
        }

        // A count before the unit, in figures or words: "100 Sqm", "10No", "PER TEN SQUARE (metre)", "Dozen".
        $ascii = self::asciiDigits($text);

        if (preg_match('/^([\d,]*\.?\d+)\s*(.*)$/u', $ascii, $match) === 1 || preg_match('/^(ten|hundred|thousand|dozen|डजन)\b\s*(.*)$/iu', $ascii, $match) === 1) {
            $count = self::COUNTS[mb_strtolower($match[1])] ?? self::number($match[1]);
            $rest = trim($match[2]) === '' && isset(self::COUNTS[mb_strtolower($match[1])]) ? 'nos' : trim($match[2]);
            $found = $rest === '' ? null : self::lookUp(self::bareUnit($rest));

            if ($count !== null && $found !== null && $found[0] !== '%' && Dec::of($count)->isPositive()) {
                return ['unit' => $found[0], 'count' => Dec::of($count)->isEqualTo(1) ? null : $count, 'written' => $written, 'how' => $found[1]];
            }
        }

        // "Each cut", "Each Sleeper": counted in numbers.
        if (preg_match('/^each\s+\S/iu', $text) === 1) {
            return ['unit' => 'nos', 'count' => null, 'written' => $written, 'how' => 'shown'];
        }

        $ask = in_array(self::key($text), self::ASK, true);

        return ['unit' => mb_substr($ask ? $written : $text, 0, 16), 'count' => null, 'written' => $written, 'how' => $ask ? 'ask' : 'raw'];
    }

    /** The unit a text names, or null when it names none of the usual ones, or could mean two. */
    public static function knownUnit(string $text): ?string
    {
        return self::lookUp(self::bareUnit(trim((string) preg_replace('/\s+/u', ' ', $text))))[0] ?? null;
    }

    /**
     * A unit spelling without what is written around it: "Rs/" or "Rs. per" before it, "Per", "One" or "1" in
     * front, a bracketed expansion after it ("Cum (Cubic Meter)"), a closing dot, letters spaced out ("E a c h").
     */
    private static function bareUnit(string $text): string
    {
        $text = trim((string) preg_replace('/^(?:(?:rs|inr|₹|रु)\.?\s*(?:\/|per|प्रति)?\s*|\/\s*)/iu', '', $text));
        $text = trim((string) preg_replace('/^(?:per|प्रति)\s+/iu', '', $text));
        $text = trim((string) preg_replace('/^(?:one|1)\s*(?=\p{L})/iu', '', $text));

        // A bracket after a unit expands it ("1 Cum (One cubic metre)"); one after a word finishes it ("SQUARE (metre)").
        if (preg_match('/^(.*?)\s*\(([^()]*)\)\s*\.?$/u', $text, $match) === 1 && $match[1] !== '') {
            $text = self::lookUp($match[1]) !== null ? $match[1] : $match[1].' '.$match[2];
        }

        if (preg_match('/^(?:\p{L}\s){2,}\p{L}\.?$/u', $text) === 1) {
            $text = str_replace(' ', '', $text);
        }

        return trim($text);
    }

    /** @return array{0: string, 1: string}|null The unit and how sure the reading is, for a bare spelling. */
    private static function lookUp(string $text): ?array
    {
        // Tonne in capitals ("MT", "M.T."); "Mt" alone could be a metre too.
        if (preg_match('/^M\.?\s?T\.?$/u', trim($text)) === 1) {
            return ['MT', 'known'];
        }

        $key = self::key($text);

        if ($key === '' || in_array($key, self::ASK, true)) {
            return null;
        }

        if (self::$lookup === null) {
            self::$lookup = [];

            foreach (self::UNITS as $unit => $spellings) {
                foreach ($spellings as $spelling) {
                    self::$lookup[$spelling] = [$unit, 'known'];
                }
            }

            foreach (self::SHOWN as $spelling => $unit) {
                self::$lookup[$spelling] ??= [$unit, 'shown'];
            }
        }

        return self::$lookup[$key] ?? null;
    }

    private static function key(string $text): string
    {
        return (string) preg_replace('/[\s.\/\-_()]+/u', '', mb_strtolower(self::asciiDigits(trim($text))));
    }

    /** Text as it reads on the sheet: a whole number without .0, a decimal without float noise, spaces folded. */
    public static function text(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return is_float($value) && ! is_finite($value) ? '' : self::trimZeros(number_format((float) $value, 6, '.', ''));
        }

        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private static function trimZeros(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
