<?php

namespace App\Services\Imports;

use App\Services\Reports\BoqExcelService;
use App\Support\Dec;

/**
 * Finds the heading row of any BOQ, estimate or tender sheet and what each column holds.
 *
 * Every contractor lays a BOQ out differently, so no exact heading is required. Headings are compared
 * as words: lower case, Devanagari digits as ASCII, punctuation as spaces, Devanagari letters kept. A
 * column's heading names a field when it contains one of that field's phrases and little else. Every
 * row in the first SCAN_ROWS is tried as the heading, alone and joined with the one or two rows under
 * it ("Rate" over "(Rs.)"), and the row that names the most, best, is taken. A cell inside a merged
 * range counts as holding the merged value.
 *
 * Some words mean two things. प्रमाण and परिमाण can be a unit or a quantity, रुपये a rate or an amount:
 * the column's values decide. A unit written in the quantity heading, as in "Quantity (Cum)", is kept
 * for the lines of a sheet with no unit column.
 *
 * Every BOQ-like import uses this: the project BOQ sheet import, the tender BOQ import, and the heading
 * search of the PDF table reader.
 */
final class BoqHeadings
{
    /**
     * Rows of two cells or more looked at for the heading, from the top of the sheet. Rows of one cell, such
     * as the notes above a tender's schedule, are looked at too but are not counted, up to SCAN_LIMIT rows.
     */
    public const SCAN_ROWS = 40;

    /** The most rows looked at for the heading, of any kind. */
    public const SCAN_LIMIT = 400;

    /** The most rows one heading can take. */
    public const MAX_SPAN = 3;

    /** Data rows looked at to judge what a column holds. */
    private const SAMPLE_ROWS = 80;

    /**
     * Phrases that name each field, compared after normalise(): the headings seen printed on schedules, estimates
     * and tender BOQs across India (central, state and private), their common English variants and misspellings,
     * and the Hindi, Marathi and other words seen on a source. The template's and the PDF reader's own headings
     * (BoqExcelService::ALIASES) are added to these.
     */
    private const PHRASES = [
        'serial' => [
            'sr no', 'sr', 's no', 'sl no', 'sl', 'slno', 's n', 'srl no', 'si no', 'serial', 'serial no', 'serial number', 'item no', 'item number',
            'items no', 'item no of boq', 'no', 'sr no of item', 'order', 'अ क्र', 'अं क्र',
        ],
        'code' => [
            'code', 'item code', 'code no', 'dsr code', 'dsr no', 'dsr item no', 'dsr item', 'dsr ref', 'dsr reference', 'sor no', 'sor code',
            'sor item', 'sor item no', 'sor ref', 'ssr no', 'ssr item no', 'ssr code', 'csr no', 'csr ref', 'cpwd item no', 'cpwd code', 'cpwd ref',
            'bsr item no', 'hsr item no', 'apwd sor no', 'djb item code', 'material code', 'ref', 'ref no', 'reference', 'reference no',
            'rate reference', 'ref page no', 'chapter no item no', 'item ref', 'schedule item no', 'schedule no',
        ],
        'description' => [
            'description', 'descriptions', 'discription', 'item description', 'item desccription', 'item description in brief', 'short description',
            'description of item', 'description of items', 'description of the item', 'description of an item', 'description s of items',
            'amended description of item', 'description of work', 'description of works', 'description of the work', 'description of work item s',
            'particulars', 'particular', 'particulars of work', 'particulars of items', 'particulars of item of work', 'name of work', 'name of item',
            'name of the work', 'item name', 'work name', 'main item', 'details of work', 'details of item', 'details of items', 'item', 'items',
            'item of work', 'work', 'nature of work', 'work description', 'scope of work', 'activity name', 'activity taken', 'material description',
            'description of materials', 'common items', 'category description item size', 'specification', 'विवरण', 'कामाचे नाव', 'मालाचे नाव',
            'খরচের খাত', 'উপকরণ',
        ],
        'unit' => ['unit', 'units', 'per unit', 'uom', 'u o m', 'unit of measurement', 'unit of measure', 'ईकाई', 'इकाई'],
        'qty' => [
            'qty', 'qnty', 'quantity', 'quantities', 'total qty', 'total quantity', 'boq qty', 'boq quantity', 'estimated qty', 'estimated quantity',
            'est qty', 'tender qty', 'tender quantity', 'approximate quantity', 'no of qty', 'total units size qty', 'मात्रा', 'नग', 'আনুমানিক পরিমাণ',
        ],
        'rate' => [
            'rate', 'rates', 'unit rate', 'rate in rs', 'rate rs', 'rate inr', 'rate in inr', 'rate per unit', 'rate rupees', 'rate in rupees',
            'rate per unit size', 'unit cost', 'unit price', 'price', 'price per unit', 'basic rate', 'quoted rate', 'labour rate', 'completed rate',
            'csr deptt rates', 'rate to be quoted by contractor', 'rate with labour cess', 'rate as per sor', 'rate after gst', 'final rate',
            'दर', 'रेट', 'ధర', 'ದರ', 'রেট',
        ],
        'amount' => [
            'amount', 'amt', 'total', 'total amount', 'amount in rs', 'amount rs', 'amount inr', 'amount in inr', 'amount in rupees', 'amount i c gst',
            'amount after escallation', 'value', 'total value', 'cost', 'total cost', 'total inr', 'total charges', 'items amount', 'all charges',
            'राशि', 'राशी', 'रक्कम', 'தொகை', 'మొత్తం', 'মোট', 'টাকা', 'আনুমানিক খরচ',
        ],
        'remark' => ['remark', 'remarks', 'note', 'notes', 'comments', 'drawing ref', 'item code make'],
        'section' => ['section', 'section name', 'chapter', 'group', 'sub head', 'subhead'],
        'category' => ['category', 'work category', 'type of work', 'head of work', 'trade'],
        'details' => ['details', 'detail', 'item details', 'specification', 'specifications', 'spec', 'make', 'additional specification', 'brand is std'],
        // What the rate is for, when it is not one unit: "100 Sqm", "10 Cum", "Qtl".
        'per' => ['per'],
        // A handbook or specification reference beside the schedule code, kept in the line's details.
        'reference' => ['reference no', 'reference', 'ref no', 'specification no', 'spec no', 'morth spc no', 'apss spc no', 'sor reference'],
    ];

    /**
     * Heading words no source was seen to print as a column heading: the Hindi, Marathi and other local words a
     * contractor may still use. A column headed with one is read only when the values under it agree with that
     * meaning, and the page says it was read from the values, never from a known heading.
     */
    private const UNCONFIRMED = [
        'serial' => ['क्र', 'क्रमांक', 'क्र सं', 'क्रम सं', 'क्रम संख्या', 'अनु क्र', 'अनुक्रमांक', 'अनु क्रमांक', 'अनुक्रम', 'अ नं', 'क्र नं', 'अनु', 'क्रमांक नं', 'ક્રમ', 'ক্রমিক নং', 'ಕ್ರ ಸಂ', 'வ எண்', 'క్ర సం', 'ക്രമ നമ്പർ'],
        'code' => ['कोड', 'संदर्भ', 'संदर्भ क्र', 'डीएसआर क्र'],
        'description' => [
            'तपशील', 'तपशिल', 'कामाचा तपशील', 'कामाचे वर्णन', 'वर्णन', 'कार्य का विवरण', 'कार्य विवरण', 'कार्य', 'काम', 'बाब', 'मद', 'मद का विवरण', 'कामाचा प्रकार',
            'कार्य का नाम', 'વિગત', 'ಕಾಮಗಾರಿಯ ವಿವರ', 'விவரம்', 'పని వివరము', 'বিবরণ', 'ବିବରଣୀ',
        ],
        'unit' => ['एकक', 'युनिट', 'मापन एकक', 'એકમ', 'ಘಟಕ', 'அலகு', 'యూనిట్', 'একক', 'ଏକକ'],
        'qty' => ['संख्या', 'परिमाण', 'एकूण नग', 'एकूण प्रमाण', 'कुल मात्रा', 'જથ્થો', 'ಪ್ರಮಾಣ', 'அளவு', 'పరిమాణము', 'পরিমাণ', 'ପରିମାଣ', 'അളവ്'],
        'rate' => ['दर रुपये', 'प्रति एकक दर', 'भाव', 'दर रु', 'ભાવ', 'દર', 'விலை', 'வீதம்', 'రేటు', 'നിരക്ക്'],
        'amount' => ['एकूण रक्कम', 'एकूण', 'कुल', 'कुल राशि', 'रकम', 'किंमत', 'धनराशि', 'રકમ', 'ಮೊತ್ತ', 'തുക'],
        'remark' => ['शेरा', 'टिप्पणी', 'अभिप्राय', 'टीप'],
        'section' => ['विभाग'],
        'per' => ['प्रति', 'प्रति एकक'],
    ];

    /** Phrases that name a field only loosely. A column headed this way loses to one headed plainly. */
    private const WEAK = ['item', 'items', 'work', 'specification', 'काम', 'कार्य', 'no', 'sl', 'sr', 'price', 'value', 'cost', 'total', 'group', 'अनु', 'make', 'spec', 'order'];

    /**
     * Words that mean one of two fields. The values in the column decide which: प्रमाण and परिमाण a unit or a
     * quantity, रुपये a rate or an amount, "Items" a schedule code (short figures, as 11010) or the description,
     * "Specification" the description (long text) or a reference ("APSS No. 402"), "Nos" the quantity when the
     * amount is it times the rate.
     */
    private const AMBIGUOUS = [
        'प्रमाण' => ['qty', 'unit'], 'परिमाण' => ['qty', 'unit'], 'रुपये' => ['rate', 'amount'], 'रुपए' => ['rate', 'amount'],
        'items' => ['description', 'code'], 'specification' => ['description', 'reference'],
    ];

    /** Headings of a table that gives material per unit of work, not quantities of work: never the quantity, rate or amount. */
    private const COEFFICIENT = '/per unit quantity of work|cement qty|unit for cement payment|coefficient|consumption|cement bags|bitumen/u';

    /** Headings of a measurement sheet's dimension columns: length, breadth, and depth or height. */
    private const DIMENSIONS = [
        'length' => ['l', 'length', 'l m', 'len'],
        'breadth' => ['b', 'breadth', 'width', 'w', 'b m', 'breadth width'],
        'depth' => ['d', 'depth', 'h', 'height', 'd h', 'h d', 'h m', 'd m', 'height depth', 'depth height', 'thickness'],
    ];

    /** How much finding each field counts towards a heading row. */
    private const WEIGHTS = [
        'description' => 3.0, 'qty' => 2.0, 'rate' => 2.0, 'amount' => 1.6, 'unit' => 1.6, 'code' => 1.2, 'serial' => 1.2,
        'remark' => 0.8, 'section' => 0.8, 'category' => 0.6, 'details' => 0.6, 'per' => 0.6, 'reference' => 0.6,
    ];

    /**
     * Words that only qualify a quantity, rate or amount heading: "Estimated Rate", "Rate including GST",
     * "TOTAL AMOUNT Without Taxes", "BASIC RATE In Figures To be entered by the Bidder Rs. P".
     */
    private const QUALIFIERS = [
        'estimated', 'estimate', 'est', 'quoted', 'offered', 'including', 'incl', 'inclusive', 'inc', 'excluding', 'excl', 'exclusive',
        'exc', 'with', 'without', 'gst', 'tax', 'taxes', 'figures', 'figure', 'basic', 'net', 'sor', 'dsr', 'ssr', 'csr', 'approx',
        'approximate', 'approximately', 'revised', 'approved', 'contractor', 'contractors', 'tender', 'tendered', 'entered', 'bidder',
        'bidders', 'by', 'p', 'all', 'applicable', 'final', 'actual', 'current', 'total', 'gross', 'agreed', 'proposed',
        'अंदाजित', 'अंदाजे', 'अनुमानित', 'लगभग', 'जीएसटी', 'सहित', 'सह', 'कर', 'शिवाय', 'रहित', 'निविदा',
    ];

    /** Words that say a column holds an amount written in words: a remark, never the amount. */
    private const IN_WORDS = ['words', 'word', 'अक्षरी', 'अक्षरात', 'शब्दात', 'शब्दों', 'शब्द'];

    /** Small words a heading may carry beside its phrase: "Amount in Rs.", "Rate per Cum". */
    private const FILLERS = [
        'in', 'of', 'the', 'per', 'rs', 'inr', 'rupees', 'rupee', 'ps', 'paise', 'and', 'for', 'as', 'at', 'on', 'to', 'be', 'a',
        'में', 'मध्ये', 'का', 'की', 'के', 'चे', 'ची', 'चा', 'च्या', 'रु', 'प्रति', 'प्रत्येकी', 'आणि', 'और', 'व',
    ];

    /** @var array<string, array{0: list<array{tokens: list<string>, compact: string, weak: bool, unconfirmed: bool}>, 1: array<string, true>}>|null */
    private static ?array $fields = null;

    /** @var array<string, array{scores: array<string, float>, units: ?string, unconfirmed: array<string, true>}> */
    private static array $cache = [];

    /** @var array<string, array{filled: int, numbers: float, units: float, text: float, length: float}> What the values under a column look like, for the sheet being read. */
    private array $profiles = [];

    /** Heading text the way it is compared: lower case, ASCII digits, punctuation as spaces, Devanagari letters and marks kept. */
    public static function normalise(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $text = (string) (\Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text);
        }

        $text = CellValues::asciiDigits(mb_strtolower($text));
        $text = (string) preg_replace('/[^\p{L}\p{M}\p{N}%]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * How strongly one heading cell names each field, by the heading words seen on sources. Empty when it names
     * none. With $unconfirmed, words no source was seen to print count too: only to find where a table is, as
     * the PDF reader does, never to say what a column holds.
     *
     * @return array<string, float>
     */
    public static function scores(string $heading, bool $unconfirmed = false): array
    {
        $read = self::read($heading);

        return $unconfirmed ? $read['scores'] : array_diff_key($read['scores'], $read['unconfirmed']);
    }

    /** The unit a quantity, rate or amount heading names, such as cum in "Quantity (Cum)". */
    public static function unitIn(string $heading): ?string
    {
        return self::read($heading)['units'];
    }

    /**
     * The fields a heading word names. The PDF table reader asks this of each heading piece.
     *
     * @return list<string>
     */
    public static function fieldsOf(string $heading): array
    {
        return array_keys(self::scores($heading));
    }

    /**
     * The best heading row of a sheet and what each column holds. When no row names a description and a
     * quantity or rate, the layout returned is not found(), and the person chooses the row on the preview.
     *
     * @param  array<int, array<int, mixed>>  $grid  Row => column => value.
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $merges  Merged ranges: first row, first column, last row, last column.
     */
    public function detect(array $grid, array $merges = []): SheetLayout
    {
        $this->profiles = [];
        $best = null;
        $rows = $this->scanRows($grid);
        $merged = $this->mergedValues($grid, $merges);

        foreach ($rows as $index => $row) {
            for ($span = 1; $span <= self::MAX_SPAN; $span++) {
                $group = array_slice($rows, $index, $span);

                if (count($group) < $span || end($group) - $row > self::MAX_SPAN + 1) {
                    break;
                }

                $layout = $this->evaluate($grid, $merged, $group);

                if ($layout === null || ! $layout->found()) {
                    continue;
                }

                // A heading taking more rows has to name more to win. On a tie the shorter heading, lower down, wins:
                // the rows it leaves out add nothing, as a letterhead line joined to the heading would not.
                if ($best === null || $layout->score > $best->score + 0.01 || (abs($layout->score - $best->score) <= 0.01 && $layout->span < $best->span)) {
                    $best = $layout;
                }
            }
        }

        if ($best === null) {
            return SheetLayout::none();
        }

        return $this->extend($grid, $merged, $best, $rows, $merges);
    }

    /**
     * The layout when the heading is the given row, taking $span rows. Used when the person picks the heading row.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $merges
     */
    public function at(array $grid, int $row, int $span = 1, array $merges = []): SheetLayout
    {
        $this->profiles = [];
        $rows = array_values(array_filter(array_keys($grid), fn (int $number) => $number >= $row));
        $group = array_slice($rows, 0, max(1, min(self::MAX_SPAN, $span)));

        if ($group === [] || $group[0] !== $row) {
            return new SheetLayout($row, max(1, $span));
        }

        return $this->evaluate($grid, $this->mergedValues($grid, $merges, end($group)), $group, force: true) ?? new SheetLayout($row, count($group));
    }

    /**
     * The rows a person can pick as the heading, with the start of their text.
     *
     * @return array<int, string>
     */
    public function candidateRows(array $grid): array
    {
        $rows = [];

        foreach ($this->scanRows($grid) as $number) {
            $texts = array_filter(array_map(fn ($value) => CellValues::text($value), $grid[$number] ?? []), fn (string $text) => $text !== '');
            $rows[$number] = mb_strimwidth(implode(' | ', $texts), 0, 90, '…');
        }

        return $rows;
    }

    /** How many rows under the heading carry a rate or an amount: how much a sheet looks like a priced BOQ. */
    public function pricedRows(array $grid, SheetLayout $layout, string $fields = 'rate,amount'): int
    {
        if (! $layout->found()) {
            return 0;
        }

        $columns = $layout->columns();
        $count = 0;

        foreach ($grid as $number => $cells) {
            if ($number <= $layout->lastRow() || CellValues::text($cells[$columns['description']] ?? null) === '') {
                continue;
            }

            foreach (explode(',', $fields) as $field) {
                if (isset($columns[$field]) && CellValues::isNumber($cells[$columns[$field]] ?? null)) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /** @return list<int> The rows that have anything in them, from the top, until SCAN_ROWS rows of two cells or more. */
    private function scanRows(array $grid): array
    {
        $rows = [];
        $counted = 0;

        foreach ($grid as $number => $cells) {
            $filled = count(array_filter($cells, fn ($value) => CellValues::text($value) !== ''));

            if ($filled === 0) {
                continue;
            }

            $rows[] = (int) $number;
            $counted += $filled >= 2 ? 1 : 0;

            if ($counted >= self::SCAN_ROWS || count($rows) >= self::SCAN_LIMIT) {
                break;
            }
        }

        return $rows;
    }

    /**
     * What each column of these heading rows holds.
     *
     * @param  list<int>  $group  The heading rows.
     */
    private function evaluate(array $grid, array $merged, array $group, bool $force = false): ?SheetLayout
    {
        [$headings, $figures] = $this->headingsOf($grid, $merged, $group);

        if ($headings === [] || (! $force && ($figures || count($headings) < 2))) {
            return null;
        }
        $last = end($group);
        $triples = [];
        $units = [];
        $ambiguous = [];

        foreach ($headings as $column => $heading) {
            $read = self::read($heading);

            if ($read['units'] !== null) {
                $units[$column] = $read['units'];
            }

            $key = self::normalise($heading);

            if (isset(self::AMBIGUOUS[$key])) {
                $ambiguous[$column] = self::AMBIGUOUS[$key];
            }

            foreach ($read['scores'] as $field => $score) {
                $profile = $this->profile($grid, $column, $last);
                $score *= $this->plausible($field, $profile);
                $unconfirmed = isset($read['unconfirmed'][$field]);

                // A word no source printed as this heading counts only when the values under it agree.
                if ($unconfirmed && ! self::agrees($field, $profile)) {
                    continue;
                }

                if ($score > 0.2) {
                    $triples[] = [$score * ($unconfirmed ? 0.9 : 1.0), $column, $field, $unconfirmed];
                }
            }
        }

        usort($triples, fn (array $a, array $b) => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);
        $fields = array_fill_keys(array_keys($headings), null);
        $how = [];
        $taken = [];
        $score = 0.0;

        foreach ($triples as [$value, $column, $field, $unconfirmed]) {
            if ($fields[$column] !== null || isset($taken[$field]) || isset($ambiguous[$column])) {
                continue;
            }

            $fields[$column] = $field;
            $how[$column] = $unconfirmed ? 'values' : 'heading';
            $taken[$field] = $column;
            $score += $value;
        }

        foreach ($ambiguous as $column => $choices) {
            $field = $this->resolve($grid, $column, $last, $choices, $taken);

            if ($field !== null) {
                $fields[$column] = $field;
                $how[$column] = 'values';
                $taken[$field] = $column;
                $score += self::WEIGHTS[$field] * 0.8;
            }
        }

        $score += $this->byValues($grid, $last, $fields, $how, $taken);
        $this->amountThatIsTheProduct($grid, $last, $headings, $fields, $how, $taken);

        // "Description" merged over two columns, the item in one and its parts in the next: both are read.
        if (isset($taken['description'])) {
            $first = $taken['description'];

            for ($column = $first + 1; array_key_exists($column, $fields) && $fields[$column] === null && ($headings[$column] ?? null) === $headings[$first]; $column++) {
                if (array_filter($group, fn (int $row) => isset($merged[$row][$column])) === []) {
                    break;
                }

                $fields[$column] = 'description';
                $how[$column] = 'heading';
            }
        }

        return new SheetLayout($group[0], count($group), $fields, $headings, $how, $units, round($score, 3));
    }

    /**
     * The heading text of each column over these rows, and whether a row among them holds figures,
     * which a heading does not, unless it is the "1 2 3 4" row under some headings.
     *
     * @param  list<int>  $group
     * @return array{0: array<int, string>, 1: bool}
     */
    private function headingsOf(array $grid, array $merged, array $group): array
    {
        $headings = [];
        $figures = false;

        foreach ($group as $row) {
            $numbers = 0;
            $cells = ($merged[$row] ?? []) + ($grid[$row] ?? []);
            ksort($cells);

            foreach ($cells as $column => $value) {
                $text = CellValues::text($value);

                if ($text === '') {
                    continue;
                }

                $numbers += CellValues::isNumber($value) ? 1 : 0;

                if (! str_contains(' '.($headings[$column] ?? '').' ', ' '.$text.' ')) {
                    $headings[$column] = trim(($headings[$column] ?? '').' '.$text);
                }
            }

            $figures = $figures || ($numbers >= 2 && ! $this->isColumnNumbers($grid[$row] ?? []));
        }

        ksort($headings);

        return [$headings, $figures];
    }

    /**
     * The signature every row near the top would have as the heading, alone or with the rows under it,
     * so a saved layout can be found again even where no heading is recognised.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $merges
     * @return array<string, array{0: int, 1: int}> Signature => [row, span].
     */
    public function signatures(array $grid, array $merges = []): array
    {
        $merged = $this->mergedValues($grid, $merges);
        $rows = $this->scanRows($grid);
        $signatures = [];

        foreach ($rows as $index => $row) {
            for ($span = 1; $span <= self::MAX_SPAN; $span++) {
                $group = array_slice($rows, $index, $span);

                if (count($group) < $span) {
                    break;
                }

                [$headings] = $this->headingsOf($grid, $merged, $group);
                $signatures[(new SheetLayout($row, $span, headings: $headings))->signature()] ??= [$row, $span];
            }
        }

        return $signatures;
    }

    /**
     * Columns whose heading named nothing but whose values plainly say what they are: a column of units,
     * a column of long text when no description column was found, a rate that the amount is quantity
     * times, an amount that is quantity times rate.
     *
     * @param  array<int, ?string>  $fields
     * @param  array<int, string>  $how
     * @param  array<string, int>  $taken
     */
    private function byValues(array $grid, int $last, array &$fields, array &$how, array &$taken): float
    {
        $score = 0.0;

        foreach (['unit', 'description', 'rate', 'amount', 'qty'] as $wanted) {
            if (isset($taken[$wanted])) {
                continue;
            }

            foreach ($fields as $column => $field) {
                if ($field !== null) {
                    continue;
                }

                $profile = $this->profile($grid, $column, $last);
                $fits = match ($wanted) {
                    'unit' => $profile['filled'] >= 3 && $profile['units'] >= 0.6,
                    'description' => $profile['filled'] >= 3 && $profile['text'] >= 0.7 && $profile['length'] >= 15,
                    // The amount is the quantity times this column.
                    'rate' => isset($taken['qty'], $taken['amount']) && $profile['numbers'] >= 0.6 && $this->product($grid, $taken['amount'], $taken['qty'], $column, $last) >= 0.6,
                    'amount' => isset($taken['qty'], $taken['rate']) && $profile['numbers'] >= 0.6 && $this->product($grid, $column, $taken['qty'], $taken['rate'], $last) >= 0.6,
                    // A column headed "Nos" or naming nothing, which the amount is the rate times: the quantity.
                    'qty' => isset($taken['rate'], $taken['amount']) && $profile['filled'] >= 2 && $profile['numbers'] >= 0.6 && $this->product($grid, $taken['amount'], $column, $taken['rate'], $last) >= 0.6,
                };

                if ($fits) {
                    $fields[$column] = $wanted;
                    $how[$column] = 'values';
                    $taken[$wanted] = $column;
                    $score += self::WEIGHTS[$wanted] * 0.5;
                    break;
                }
            }
        }

        return $score;
    }

    /**
     * Two columns headed as the amount, as "TOTAL AMOUNT With Taxes" and "TOTAL AMOUNT Without Taxes", or one
     * in rupees and one in lakhs: the one that is the quantity times the rate is read, the other is not.
     *
     * @param  array<int, string>  $headings
     * @param  array<int, ?string>  $fields
     * @param  array<int, string>  $how
     * @param  array<string, int>  $taken
     */
    private function amountThatIsTheProduct(array $grid, int $last, array $headings, array &$fields, array &$how, array &$taken): void
    {
        if (! isset($taken['amount'], $taken['qty'], $taken['rate'])) {
            return;
        }

        $share = $this->product($grid, $taken['amount'], $taken['qty'], $taken['rate'], $last);

        foreach ($fields as $column => $field) {
            if ($field !== null || ! isset(self::read($headings[$column] ?? '')['scores']['amount'])) {
                continue;
            }

            $other = $this->product($grid, $column, $taken['qty'], $taken['rate'], $last);

            if ($other >= 0.6 && $other > $share + 0.2) {
                $fields[$taken['amount']] = null;
                unset($how[$taken['amount']]);
                $fields[$column] = 'amount';
                $how[$column] = 'values';
                $taken['amount'] = $column;
                $share = $other;
            }
        }
    }

    /**
     * Which of two meanings a column with an ambiguous heading has, by its values.
     *
     * @param  list<string>  $choices
     * @param  array<string, int>  $taken
     */
    private function resolve(array $grid, int $column, int $last, array $choices, array $taken): ?string
    {
        $free = array_values(array_filter($choices, fn (string $field) => ! isset($taken[$field])));

        if ($free === []) {
            return null;
        }

        if (count($free) === 1) {
            return $free[0];
        }

        $profile = $this->profile($grid, $column, $last);

        if ($choices === ['qty', 'unit']) {
            return $profile['units'] >= 0.5 && $profile['units'] > $profile['numbers'] ? 'unit' : 'qty';
        }

        // "Items" holding short figures (11010, 22031) holds schedule codes; holding text, the description.
        if ($choices === ['description', 'code']) {
            return $profile['numbers'] >= 0.6 || ($profile['text'] > 0 && $profile['length'] < 10) ? 'code' : 'description';
        }

        // "Specification" holding long text is the description; holding short references, a reference.
        if ($choices === ['description', 'reference']) {
            return $profile['length'] >= 25 ? 'description' : 'reference';
        }

        // रुपये: an amount when it is the quantity times another column, a rate otherwise.
        if (isset($taken['qty'])) {
            $columns = [];

            foreach ($grid as $number => $cells) {
                if ($number > $last) {
                    $columns += array_fill_keys(array_keys($cells), true);
                }
            }

            foreach (array_keys($columns) as $other) {
                if ($other !== $column && $other !== $taken['qty'] && $this->product($grid, $column, $taken['qty'], $other, $last) >= 0.6) {
                    return 'amount';
                }
            }
        }

        return 'rate';
    }

    /** Share of rows, out of 1, where this column is the product of the other two. */
    private function product(array $grid, int $column, int $first, int $second, int $last): float
    {
        $checked = 0;
        $matching = 0;

        foreach ($grid as $number => $cells) {
            if ($number <= $last) {
                continue;
            }

            $value = CellValues::number($cells[$column] ?? null);
            $a = CellValues::number($cells[$first] ?? null);
            $b = CellValues::number($cells[$second] ?? null);

            if ($value === null || $a === null || $b === null || (float) $value == 0.0) {
                continue;
            }

            $checked++;
            $difference = Dec::of($a)->multipliedBy(Dec::of($b))->minus(Dec::of($value))->abs();
            $matching += $difference->isLessThanOrEqualTo(max(1, abs((float) $value) * 0.005)) ? 1 : 0;

            if ($checked >= self::SAMPLE_ROWS) {
                break;
            }
        }

        return $checked === 0 ? 0.0 : $matching / $checked;
    }

    /**
     * How a field fits the values under its column, out of 1. A description of short codes, or a rate of
     * words, is not what it says.
     *
     * @param  array{filled: int, numbers: float, units: float, text: float, length: float}  $profile
     */
    private function plausible(string $field, array $profile): float
    {
        if ($profile['filled'] < 3) {
            return 1.0;
        }

        return match ($field) {
            'description' => $profile['length'] < 4 || $profile['numbers'] > 0.7 ? 0.3 : 1.0,
            // A column of words is never the quantity, rate or amount, whatever its heading says.
            'qty', 'rate', 'amount' => $profile['numbers'] < 0.2 && $profile['units'] < 0.5 ? 0.0 : 1.0,
            'unit' => $profile['numbers'] > 0.7 ? 0.4 : 1.0,
            default => 1.0,
        };
    }

    /** @return array{filled: int, numbers: float, units: float, text: float, length: float} What the values under a column look like. */
    private function profile(array $grid, int $column, int $last): array
    {
        $key = $last.':'.$column.':'.count($grid).':'.array_key_last($grid);

        if (isset($this->profiles[$key])) {
            return $this->profiles[$key];
        }

        $filled = $numbers = $units = $texts = 0;
        $length = 0;

        foreach ($grid as $number => $cells) {
            if ($number <= $last) {
                continue;
            }

            $value = $cells[$column] ?? null;
            $text = CellValues::text($value);

            if ($text === '') {
                continue;
            }

            $filled++;

            if (CellValues::isNumber($value) || CellValues::isRateOnly($value) || CellValues::quantityWithUnit($value) !== null) {
                $numbers++;
            } else {
                $texts++;
                $length += mb_strlen($text);
                $units += CellValues::knownUnit((string) preg_replace('/^(rs|inr|₹)\.?\s*\/\s*/iu', '', $text)) !== null ? 1 : 0;
            }

            if ($filled >= self::SAMPLE_ROWS) {
                break;
            }
        }

        return $this->profiles[$key] = [
            'filled' => $filled,
            'numbers' => $filled ? $numbers / $filled : 0.0,
            'units' => $filled ? $units / $filled : 0.0,
            'text' => $filled ? $texts / $filled : 0.0,
            'length' => $texts ? $length / $texts : 0.0,
        ];
    }

    /**
     * Take in the rows under the heading that only finish it: "(Rs.)" under "Rate", "Cum" under "Quantity".
     *
     * @param  list<int>  $rows
     */
    private function extend(array $grid, array $merged, SheetLayout $layout, array $rows, array $merges = []): SheetLayout
    {
        while ($layout->span < self::MAX_SPAN) {
            $index = array_search($layout->lastRow(), $rows, true);
            $next = $index === false ? null : ($rows[$index + 1] ?? null);

            if ($next === null || $next !== $layout->lastRow() + 1
                || (! $this->finishesHeading($grid[$next] ?? [], $layout) && ! $this->underMergedHeadings($grid[$next] ?? [], $layout, $merges))) {
                break;
            }

            $longer = $this->evaluate($grid, $merged, range($layout->row, $next));

            if ($longer === null || ! $longer->found()) {
                break;
            }

            $layout = $longer;
        }

        return $layout;
    }

    /**
     * A row of words, each under a heading merged across several columns, as "Supply" and "Installation" under a
     * "Rate" spread over two: the second level of a two-level heading.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $merges
     */
    private function underMergedHeadings(array $cells, SheetLayout $layout, array $merges): bool
    {
        $any = false;

        foreach ($cells as $column => $value) {
            $text = CellValues::text($value);

            if ($text === '') {
                continue;
            }

            if (CellValues::isNumber($value) || mb_strlen($text) > 40) {
                return false;
            }

            $under = false;

            foreach ($merges as [$top, $left, $bottom, $right]) {
                if ($right > $left && $top >= ($layout->row ?? 0) && $bottom <= $layout->lastRow() && $column >= $left && $column <= $right) {
                    $under = true;
                    break;
                }
            }

            if (! $under) {
                return false;
            }

            $any = true;
        }

        return $any;
    }

    /** Words that only finish a heading written above them: "No." under "Sr.", "Rs." under "Amount in", "(Cum)" under "Qty". */
    public static function isFragment(string $text): bool
    {
        $key = self::normalise($text);

        if ($key === '' || mb_strlen($text) > 25 || CellValues::isNumber($text)) {
            return false;
        }

        foreach (explode(' ', $key) as $token) {
            if (! in_array($token, self::FILLERS, true) && CellValues::knownUnit($token) === null && self::vocabularyOf($token) === []) {
                return false;
            }
        }

        return true;
    }

    /** A row of short words under heading columns, with no figures: units, "Rs.", "No.". */
    private function finishesHeading(array $cells, SheetLayout $layout): bool
    {
        $any = false;

        foreach ($cells as $column => $value) {
            $text = CellValues::text($value);

            if ($text === '') {
                continue;
            }

            if (CellValues::isNumber($value) || mb_strlen($text) > 25 || ! array_key_exists($column, $layout->headings)) {
                return false;
            }

            if (! self::isFragment($text)) {
                return false;
            }

            $any = true;
        }

        return $any;
    }

    /**
     * The row of column numbers or letters some sheets print under the heading: "1 2 3 4 5 6", "1 2 4 5 13 53 55"
     * (a tender template numbers only some columns), "(A) (B) (C)", "I II III IV", "A B C D E F=(D*E)". Every cell
     * holds such a label and nothing else; a row with a description is never one.
     */
    public function isColumnNumbers(array $cells): bool
    {
        $texts = [];

        foreach ($cells as $value) {
            $text = CellValues::text($value);

            if ($text !== '') {
                $texts[] = $text;
            }
        }

        if (count($texts) < 3) {
            return false;
        }

        $numbers = [];

        foreach ($texts as $text) {
            $number = CellValues::number($text);

            if ($number === null || ! ctype_digit($number) || (int) $number > 99) {
                $numbers = null;
                break;
            }

            $numbers[] = (int) $number;
        }

        if ($numbers !== null) {
            foreach ($numbers as $index => $number) {
                if ($index > 0 && $number <= $numbers[$index - 1]) {
                    return false;
                }
            }

            return true;
        }

        foreach ($texts as $text) {
            if (preg_match('/^\(?(?:[A-Z]{1,2}|[IVXL]{1,6}|\d{1,2})\)?(?:\s*=\s*[\(\)A-Z0-9*+\-\/xX ]+)?$/u', $text) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cells hidden under a merged range, given the merged range's value, for the rows a heading can be on.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $merges
     * @return array<int, array<int, mixed>>
     */
    private function mergedValues(array $grid, array $merges, ?int $upTo = null): array
    {
        $values = [];
        $scanned = $this->scanRows($grid);
        $limit = max($upTo ?? 0, $scanned === [] ? 1 : (int) end($scanned)) + self::MAX_SPAN * 2;

        foreach ($merges as [$top, $left, $bottom, $right]) {
            $value = $grid[$top][$left] ?? null;

            if (CellValues::text($value) === '' || $top > $limit) {
                continue;
            }

            for ($row = $top; $row <= min($bottom, $top + self::MAX_SPAN); $row++) {
                for ($column = $left; $column <= min($right, $left + 30); $column++) {
                    if (($row !== $top || $column !== $left) && CellValues::text($grid[$row][$column] ?? null) === '') {
                        // Down a merged column only the first row counts, so "Sr. No." is not read twice.
                        if ($column !== $left || $row === $top) {
                            $values[$row][$column] = $value;
                        }
                    }
                }
            }
        }

        return $values;
    }

    /** @return array{scores: array<string, float>, units: ?string} */
    private static function read(string $heading): array
    {
        $key = self::normalise($heading);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        if (count(self::$cache) > 5000) {
            self::$cache = [];
        }

        $tokens = $key === '' ? [] : explode(' ', $key);
        $result = ['scores' => [], 'units' => null, 'unconfirmed' => []];

        if ($tokens === [] || count($tokens) > 14) {
            return self::$cache[$key] = $result;
        }

        // A column of cement or bitumen per unit of work is a coefficient, never a quantity, rate or amount.
        if (preg_match(self::COEFFICIENT, $key) === 1) {
            return self::$cache[$key] = $result;
        }

        $compact = implode('', $tokens);
        $leftover = [];

        foreach (self::fields() as $field => [$phrases, $vocabulary]) {
            $best = 0.0;
            $confirmed = false;

            foreach ($phrases as $phrase) {
                $length = count($phrase['tokens']);

                if ($phrase['compact'] !== $compact && ! self::contains($tokens, $phrase['tokens'])) {
                    continue;
                }

                $covered = 0;
                $rest = [];

                $figure = in_array($field, ['qty', 'rate', 'amount'], true);

                foreach ($tokens as $token) {
                    $qualifier = $figure && in_array($token, self::QUALIFIERS, true);

                    if (isset($vocabulary[$token]) || in_array($token, self::FILLERS, true) || ctype_digit($token) || $qualifier
                        || (in_array($field, ['qty', 'rate', 'amount', 'unit'], true) && CellValues::knownUnit($token) !== null)) {
                        $covered++;
                    }

                    if (! isset($vocabulary[$token]) && ! in_array($token, self::FILLERS, true) && ! ctype_digit($token) && ! $qualifier) {
                        $rest[] = $token;
                    }
                }

                $coverage = $phrase['compact'] === $compact ? 1.0 : $covered / count($tokens);

                if ($coverage < 0.6) {
                    continue;
                }

                $score = self::WEIGHTS[$field] * ($phrase['weak'] ? 0.6 : 1.0) * (0.5 + 0.5 * $coverage) * (1 + 0.15 * ($length - 1));

                if (! $phrase['unconfirmed']) {
                    $confirmed = true;
                }

                if ($score > $best) {
                    $best = $score;

                    if (in_array($field, ['qty', 'rate', 'amount'], true) && $rest !== []) {
                        $leftover[$field] = implode(' ', $rest);
                    }
                }
            }

            if ($best > 0) {
                $result['scores'][$field] = round($best, 4);

                // Named only by words no source printed as a heading: read only when the values agree.
                if (! $confirmed) {
                    $result['unconfirmed'][$field] = true;
                }
            }
        }

        // "Item Code / Make" is a portal's own number for the item, not a schedule code.
        if (self::contains($tokens, ['code', 'make'])) {
            unset($result['scores']['code'], $result['unconfirmed']['code']);
        }

        foreach ($leftover as $field => $text) {
            if (isset($result['scores'][$field]) && ($unit = CellValues::knownUnit($text)) !== null && $unit !== '%') {
                $result['units'] = $unit;
                break;
            }
        }

        // "Amount in words" holds words, not the amount.
        if (array_intersect($tokens, self::IN_WORDS) !== []) {
            unset($result['scores']['qty'], $result['scores']['rate'], $result['scores']['amount'], $result['unconfirmed']['remark']);
            $result['scores']['remark'] = max($result['scores']['remark'] ?? 0, self::WEIGHTS['remark']);
            $result['units'] = null;
        }

        return self::$cache[$key] = $result;
    }

    /**
     * Whether the values under a column agree with a meaning its heading gave only by a word no source printed:
     * numbers under a quantity, rate or amount, units under a unit, text under a description, short numbers or
     * labels under a serial. Two values at least are needed to say so.
     *
     * @param  array{filled: int, numbers: float, units: float, text: float, length: float}  $profile
     */
    private static function agrees(string $field, array $profile): bool
    {
        if ($profile['filled'] < 2) {
            return false;
        }

        return match ($field) {
            'qty', 'rate', 'amount' => $profile['numbers'] >= 0.6,
            'unit' => $profile['units'] >= 0.5,
            'description' => $profile['text'] >= 0.6 && $profile['length'] >= 4 && $profile['units'] < 0.5,
            'serial', 'code' => $profile['numbers'] >= 0.5 || ($profile['text'] > 0 && $profile['length'] <= 12),
            'per' => $profile['units'] >= 0.5 || $profile['numbers'] >= 0.6,
            default => $profile['text'] >= 0.5,
        };
    }

    /**
     * Whether a sheet's headings are those of a measurement sheet: a length, a breadth, and a depth or height
     * column. Its rows are measurements, never BOQ lines.
     *
     * @param  array<int, string>  $headings
     */
    public static function isMeasurement(array $headings): bool
    {
        $found = [];

        foreach ($headings as $heading) {
            $key = self::normalise($heading);

            foreach (self::DIMENSIONS as $dimension => $words) {
                if (in_array($key, $words, true)) {
                    $found[$dimension] = true;
                }
            }
        }

        return count($found) === 3;
    }

    /** @return list<string> The fields whose phrases use this word. */
    private static function vocabularyOf(string $token): array
    {
        $fields = [];

        foreach (self::fields() as $field => [, $vocabulary]) {
            if (isset($vocabulary[$token])) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @param  list<string>  $tokens  @param  list<string>  $phrase */
    private static function contains(array $tokens, array $phrase): bool
    {
        $length = count($phrase);

        for ($start = 0; $start + $length <= count($tokens); $start++) {
            if (array_slice($tokens, $start, $length) === $phrase) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{0: list<array{tokens: list<string>, compact: string, weak: bool}>, 1: array<string, true>}> */
    private static function fields(): array
    {
        if (self::$fields !== null) {
            return self::$fields;
        }

        $phrases = self::PHRASES;

        // The template's headings and those the PDF reader looked for always count.
        foreach (BoqExcelService::ALIASES as $field => $aliases) {
            if (isset($phrases[$field])) {
                array_push($phrases[$field], ...$aliases);
            }
        }

        self::$fields = [];

        foreach ($phrases as $field => $list) {
            $entries = [];
            $vocabulary = [];
            $all = array_fill_keys(array_unique($list), false) + array_fill_keys(self::UNCONFIRMED[$field] ?? [], true);

            foreach ($all as $phrase => $unconfirmed) {
                $normal = self::normalise((string) $phrase);

                if ($normal === '') {
                    continue;
                }

                $tokens = explode(' ', $normal);
                $entries[] = ['tokens' => $tokens, 'compact' => implode('', $tokens), 'weak' => in_array($normal, self::WEAK, true), 'unconfirmed' => $unconfirmed];

                foreach ($tokens as $token) {
                    $vocabulary[$token] = true;
                }
            }

            self::$fields[$field] = [$entries, $vocabulary];
        }

        return self::$fields;
    }
}
