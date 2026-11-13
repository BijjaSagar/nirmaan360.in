<?php

namespace App\Services\BillCheck\Explain;

use App\Models\BillCheck;
use App\Services\BillCheck\Flag;
use App\Services\BillCheck\FlagCatalog;
use App\Services\Wording\DocumentType;
use App\Services\Wording\Fact;
use App\Services\Wording\FactFormat;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Services\Wording\Slot;
use App\Services\Wording\WordingDocument;

/**
 * A stored pre-check as a document of slots, for App\Services\Wording.
 *
 * For each topic, before its first finding, one slot that says what the topic means: a free slot a
 * model may write, or, past the number of topics a model is asked about, the topic's own sentence.
 * Then, for each finding in the order the check listed it, one fixed slot: the finding's own sentence
 * from FlagCatalog, with every figure a {{token}} that the code fills from the check. So a model never
 * places a figure, and cannot put one finding's figure beside another.
 *
 * The document a model is asked about is always built from the sentences everyone may read (the
 * "plain" form): its slots carry no rupee figure, no contract percentage and no words of a contract
 * term, and the model is told nothing but what each topic slot is for. Someone who may see money is
 * shown the same slots with the full sentences (the "money" form). The free slots are the same in both.
 *
 * Nothing here depends on who is signed in or on the app's language, because the queue builds the
 * same document again with nobody signed in.
 */
final class ExplanationDocument
{
    public const TYPE = 'bill_check_explanation';

    /** Longest text a model may write for one topic. */
    public const ABOUT_CHARS = 450;

    /** Finding sentences the model is told about, at most, per topic. */
    private const SENTENCES_PER_TOPIC = 3;

    /** What each figure of a finding is, for the model's list of what stands beside a slot. Never shown to a reader. */
    private const LABELS = [
        'item' => 'BOQ or work order item', 'qty' => 'quantity', 'rate' => 'rate', 'stored' => 'figure on the bill',
        'expected' => 'figure worked out again', 'value' => 'lump sum value', 'prev' => 'previous quantity',
        'current' => 'quantity of this bill', 'head' => 'name of the deduction', 'net' => 'net payable',
        'stored_qty' => 'previous quantity on the bill', 'now_qty' => 'previous quantity worked out now',
        'stored_amount' => 'previous amount on the bill', 'now_amount' => 'previous amount worked out now',
        'now' => 'figure on the contract form now', 'number' => 'bill number', 'from' => 'start date', 'to' => 'end date',
        'bill' => 'figure on this bill', 'unit' => 'unit', 'cumulative' => 'quantity billed so far',
        'limit' => 'quantity it is held against', 'excess' => 'quantity above it', 'over' => 'share above it',
        'count' => 'how many', 'measured' => 'approved measured quantity', 'allowed' => 'share allowed above',
        'source' => 'where the term is written', 'earliest' => 'earliest date', 'reported' => 'quantity in daily reports',
        'total' => 'total', 'date' => 'date', 'pct' => 'figure on the contract form', 'term' => 'figure of the confirmed term',
        'due' => 'due date', 'outstanding' => 'amount unpaid', 'receipt' => 'expected receipt',
    ];

    /** The English sentence of a finding the catalogue does not know. FlagText says the same on the card. */
    private const UNKNOWN = 'A finding that this version of the app cannot put into words.';

    /** The document a model is asked about, and the one a reader without money.view is shown. Null when the check found nothing. */
    public function forModel(BillCheck $check, string $locale): ?WordingDocument
    {
        return $this->build($check, $locale, false);
    }

    /**
     * @param  bool  $money  Whether the reader may see money. The free slots are the same either way.
     */
    public function build(BillCheck $check, string $locale, bool $money): ?WordingDocument
    {
        FactFormat::locale($locale);

        $flags = $this->flags($check);

        if ($flags === []) {
            return null;
        }

        $asked = max(1, (int) config('modules.bill_check.explain.model_topics', 8));
        $slots = [];
        $facts = [];
        $topics = [];

        foreach ($flags as $index => $flag) {
            $topic = FindingTopics::of($flag->code);

            if (! isset($topics[$topic])) {
                $topics[$topic] = true;
                $about = FindingTopics::about($topic);

                $slots[] = count(array_filter($slots, fn (Slot $slot) => $slot->isFree())) < $asked
                    ? Slot::free(self::aboutId($topic), $this->purpose($topic, $flags), $about, self::ABOUT_CHARS)
                    : Slot::fixed(self::aboutId($topic), $about);
            }

            [$slot, $own] = $this->finding($check, $flag, $index + 1, $locale, $money);
            $slots[] = $slot;
            array_push($facts, ...$own);
        }

        return new WordingDocument(new DocumentType(self::TYPE, $slots), new FactSheet($facts), $locale);
    }

    /** @return list<Flag> The findings of the check, in the order it listed them. */
    public function flags(BillCheck $check): array
    {
        return array_values(array_map(fn ($stored) => Flag::fromArray(is_array($stored) ? $stored : []), $check->flags ?? []));
    }

    public static function aboutId(string $topic): string
    {
        return 'about_'.$topic;
    }

    /** The slot of the n-th finding, counting from one. */
    public static function findingId(int $number): string
    {
        return 'finding_'.$number;
    }

    /** The number of the finding a slot states, or null for a topic slot. */
    public static function findingNumber(string $slotId): ?int
    {
        return preg_match('/^finding_(\d+)$/', $slotId, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * What the model is told a topic slot is for: what to write, and the sentences of the findings of
     * that topic on this check, each with its figures taken out. English, never shown to a reader.
     *
     * @param  list<Flag>  $flags
     */
    private function purpose(string $topic, array $flags): string
    {
        $sentences = [];

        foreach ($flags as $flag) {
            if (FindingTopics::of($flag->code) !== $topic) {
                continue;
            }

            $template = FlagCatalog::template($flag->code, false) ?? self::UNKNOWN;
            $sentence = trim((string) preg_replace(['/:[a-z_]+/', '/\s+/'], ['…', ' '], $template));

            // A sentence is figure-free by construction; one that is not would never be sent.
            if (preg_match('/\p{N}/u', $sentence) !== 1) {
                $sentences[$sentence] = true;
            }
        }

        $found = array_slice(array_keys($sentences), 0, self::SENTENCES_PER_TOPIC);

        return 'In one or two plain sentences, say what this kind of finding of an RA bill pre-check means and what the person should look at before the bill is submitted. '
            .'Do not repeat the finding itself: the app prints it right after your sentences. '
            .'What the app found, with its figures left out: '.implode(' / ', $found);
    }

    /**
     * The fixed slot of one finding and the facts it states. The sentence is the catalogue's, in each
     * language, with each :placeholder turned into a token bound to a fact of this finding only.
     *
     * @return array{0: Slot, 1: list<Fact>}
     */
    private function finding(BillCheck $check, Flag $flag, int $number, string $locale, bool $money): array
    {
        $definition = FlagCatalog::definition($flag->code);
        $english = FlagCatalog::template($flag->code, $money);

        if ($definition === null || $english === null) {
            return [Slot::fixed(self::findingId($number), self::UNKNOWN), []];
        }

        preg_match_all('/:([a-z_]+)/', $english, $matches);
        $names = array_values(array_unique(array_intersect($matches[1], array_keys($definition['params']))));

        // Longest first is what strtr does anyway; listing every name keeps :stored from eating :stored_qty.
        $tokens = [];

        foreach ($names as $name) {
            $tokens[':'.$name] = '{{'.$name.'}}';
        }

        $templates = [];

        foreach (FactFormat::LOCALES as $language) {
            $line = $language === 'en' ? $english : __($english, [], $language);
            $templates[$language] = strtr(is_string($line) ? $line : $english, $tokens);
        }

        $bound = [];
        $facts = [];

        foreach ($names as $name) {
            $key = 'f'.$number.'_'.$name;
            $bound[$name] = $key;
            $facts[] = $this->fact($check, $key, $definition['params'][$name], $flag->params[$name] ?? null, $name, $number, $locale);
        }

        return [Slot::fixed(self::findingId($number), $templates, $bound), $facts];
    }

    /** One figure of a finding as a fact, printed the way the card prints it where the two can agree. */
    private function fact(BillCheck $check, string $key, string $type, mixed $value, string $name, int $number, string $locale): Fact
    {
        $label = self::LABELS[$name] ?? str_replace('_', ' ', $name);
        $source = FactSource::record('bill_check', $check->id, "flags.{$number}.{$name}");

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return new Fact($key, Fact::TEXT, '—', $label, $source);
        }

        $text = is_scalar($value) ? (string) $value : '';
        $kind = match ($type) {
            'money' => Fact::MONEY,
            'qty' => Fact::QTY,
            'pct', 'ratio' => Fact::PERCENT,
            'count' => Fact::COUNT,
            'date' => Fact::DATE,
            default => Fact::TEXT,
        };

        if ($type === 'head') {
            $text = (string) __(FlagCatalog::HEADS[$text] ?? $text, [], $locale);
        }

        if ($kind !== Fact::TEXT && ! self::fits($kind, $text)) {
            // A figure stored in a form its kind does not take is printed as it was stored, never as zero.
            $kind = Fact::TEXT;
        }

        return new Fact($key, $kind, $kind === Fact::TEXT ? ($text === '' ? '—' : $text) : $text, $label, $source);
    }

    private static function fits(string $kind, string $value): bool
    {
        if ($kind === Fact::DATE) {
            return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
        }

        // A count is a whole number that is not below zero; the other figures are plain decimals.
        return preg_match($kind === Fact::COUNT ? '/^\d+$/' : '/^-?\d+(\.\d+)?$/', $value) === 1;
    }
}
