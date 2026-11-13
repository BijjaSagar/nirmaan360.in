<?php

namespace App\Services\OwnerPack;

use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\ClientQuotationItem;
use App\Services\Wording\DocumentType;
use App\Services\Wording\Fact;
use App\Services\Wording\FactFormat;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Services\Wording\FinalCheck;
use App\Services\Wording\Rendered;
use App\Services\Wording\Renderer;
use App\Services\Wording\Slot;
use App\Services\Wording\SlotText;
use App\Services\Wording\TemplateLoader;
use App\Support\Dec;
use App\Support\RupeeWords;
use Illuminate\Validation\ValidationException;

/**
 * A quotation as a document of the wording service.
 *
 * The fact sheet holds every figure the quotation states, read from its own rows: the header, each
 * line and each payment stage. The cover note is a list of slots: every sentence that states an
 * amount, a percentage, a date or a count is a fixed sentence the code fills from the sheet, and
 * only the opening and the closing are free sentences a person may rewrite.
 *
 * The lines are printed as a table, not as sentences, so each line has one tick of its own
 * ("line_3") that stands for its quantity, rate and amount.
 */
final class QuotationDocument
{
    public const TYPE = 'owner_quotation';

    /** The free sentences of the cover note, by slot id. */
    public const FREE_SLOTS = ['opening', 'closing'];

    private const OPENING = 'Thank you for asking us to quote for your work. The details are below.';

    private const CLOSING = 'Please tell us if you would like any line explained.';

    private const REFERENCE = 'Quotation {{number}}, revision {{revision}}, prepared for {{client}}.';

    private const LINES = 'It lists {{lines}} lines of work, each with its quantity, rate and amount.';

    private const SUBTOTAL = 'The amounts of these lines add up to {{subtotal}}.';

    private const GST = 'GST at {{gst_pct}} on this value comes to {{gst_amount}}.';

    private const NO_GST = 'GST is not included in the amounts of this quotation.';

    private const TOTAL = 'The total of this quotation is {{total}}.';

    private const TOTAL_WORDS = 'In words: {{words}}.';

    private const VALIDITY = 'This quotation is valid until {{valid_until}}.';

    private const STAGES_INTRO = 'Number of payment stages: {{stages}}.';

    private const STAGE_PERCENT = '{{title}}: {{percent}} of the total, which is {{amount}}.';

    private const STAGE_AMOUNT = '{{title}}: {{amount}}.';

    private const STAGE_CONDITION = 'Condition for this stage: {{condition}}';

    private const STAGES_TOTAL = 'The stages add up to {{stages_total}}.';

    private const STAGES_BALANCE = 'The balance of {{balance}} is not covered by a stage.';

    public function __construct(private Renderer $renderer, private FinalCheck $check, private TemplateLoader $templates) {}

    /**
     * The facts of a quotation as its rows now stand. Always built in the same order, because the
     * fingerprint of this sheet is what issuing and sharing compare.
     *
     * @param  iterable<ClientQuotationItem>  $items  In the order they are printed.
     * @param  iterable<ClientPaymentStage>  $stages  In the order they fall due.
     */
    public function sheet(ClientQuotation $quotation, iterable $items, iterable $stages): FactSheet
    {
        $header = fn (string $field) => FactSource::record('client_quotation', $quotation->id, $field);
        $facts = [
            new Fact('quotation_number', Fact::REFERENCE, $quotation->number, 'Quotation number', $header('number')),
            new Fact('revision', Fact::COUNT, (int) $quotation->revision, 'Revision', $header('revision')),
            new Fact('client_name', Fact::TEXT, $quotation->client_name, 'Client name', $header('client_name')),
        ];

        $lines = [];
        $n = 0;

        foreach ($items as $item) {
            $n++;
            $line = fn (string $field) => FactSource::record('client_quotation_item', $item->id, $field);
            $lines[] = new Fact("line_{$n}_work", Fact::TEXT, trim($item->code.' '.$item->description), "Line {$n}: work", $line('description'));

            // A translation a person accepted is printed beside the description, so it is part of what is issued.
            if (QuotationTranslations::printed($item)) {
                $lines[] = new Fact("line_{$n}_work_translated", Fact::TEXT, $item->description_translated, "Line {$n}: work, translated", $line('description_translated'));
            }

            if (trim((string) $item->unit) !== '') {
                $lines[] = new Fact("line_{$n}_unit", Fact::TEXT, $item->unit, "Line {$n}: unit", $line('unit'));
            }

            $lines[] = new Fact("line_{$n}_qty", Fact::QTY, (string) $item->qty, "Line {$n}: quantity", $line('qty'));
            $lines[] = new Fact("line_{$n}_rate", Fact::MONEY, (string) $item->rate, "Line {$n}: rate", $line('rate'));
            $lines[] = new Fact("line_{$n}_amount", Fact::MONEY, (string) $item->amount, "Line {$n}: amount", $line('amount'));
        }

        $facts[] = new Fact('line_count', Fact::COUNT, $n, 'Number of lines', FactSource::computed('line_count'));
        array_push($facts, ...$lines);
        $facts[] = new Fact('subtotal', Fact::MONEY, (string) $quotation->subtotal, 'Value of the work', $header('subtotal'));

        if ($quotation->gst_pct !== null) {
            // Typed by a person for this quotation. The app holds no tax rate of its own.
            $facts[] = new Fact('gst_pct', Fact::PERCENT, (string) $quotation->gst_pct, 'GST percentage typed for this quotation', FactSource::entered('gst_pct'));
            $facts[] = new Fact('gst_amount', Fact::MONEY, (string) $quotation->gst_amount, 'GST amount', $header('gst_amount'));
        }

        $facts[] = new Fact('total', Fact::MONEY, (string) $quotation->total, 'Quotation total', $header('total'));
        $facts[] = new Fact('total_words', Fact::TEXT, RupeeWords::of($quotation->total), 'Total in words', FactSource::computed('total_words'));

        if ($quotation->valid_until !== null) {
            $facts[] = new Fact('valid_until', Fact::DATE, $quotation->valid_until, 'Valid until', FactSource::entered('valid_until'));
        }

        $schedule = [];
        $amounts = [];
        $n = 0;

        foreach ($stages as $stage) {
            $n++;
            $row = fn (string $field) => FactSource::record('client_payment_stage', $stage->id, $field);
            $schedule[] = new Fact("stage_{$n}_title", Fact::TEXT, $stage->title, "Stage {$n}: name", $row('title'));

            if ($stage->isPercent()) {
                $schedule[] = new Fact("stage_{$n}_percent", Fact::PERCENT, (string) $stage->percent, "Stage {$n}: share of the total", $row('percent'));
            }

            $schedule[] = new Fact("stage_{$n}_amount", Fact::MONEY, (string) $stage->amount, "Stage {$n}: amount", $row('amount'));

            if (trim((string) $stage->condition_text) !== '') {
                $schedule[] = new Fact("stage_{$n}_condition", Fact::TEXT, $stage->condition_text, "Stage {$n}: condition", $row('condition_text'));
            }

            $amounts[] = $stage->amount;
        }

        if ($n > 0) {
            $staged = QuotationMath::sum($amounts);
            $balance = Dec::of($quotation->total)->minus($staged);

            $facts[] = new Fact('stage_count', Fact::COUNT, $n, 'Number of payment stages', FactSource::computed('stage_count'));
            array_push($facts, ...$schedule);
            $facts[] = new Fact('stages_total', Fact::MONEY, (string) $staged, 'Total of the stages', FactSource::computed('stages_total'));

            if ($balance->isPositive()) {
                $facts[] = new Fact('stages_balance', Fact::MONEY, (string) $balance, 'Balance not covered by a stage', FactSource::computed('stages_balance'));
            }
        }

        return new FactSheet($facts);
    }

    /**
     * The cover note for this sheet. Which sentences it has follows from the facts: a GST sentence
     * when a percentage was typed, a validity sentence when a date was, one sentence per stage.
     *
     * @param  bool  $withSchedule  False for a revision that has been replaced: its payment schedule is no longer shown.
     */
    public function type(string $language, FactSheet $sheet, bool $withSchedule = true): DocumentType
    {
        $slots = [
            Slot::free('opening', 'Thank the client for the chance to quote and say that the details follow. State no figure, date or promise.', self::OPENING),
            Slot::fixed('reference', self::REFERENCE, ['number' => 'quotation_number', 'revision' => 'revision', 'client' => 'client_name']),
            Slot::fixed('lines', self::LINES, ['lines' => 'line_count']),
            Slot::fixed('subtotal', self::SUBTOTAL, ['subtotal']),
            $sheet->has('gst_pct') ? Slot::fixed('gst', self::GST, ['gst_pct', 'gst_amount']) : Slot::fixed('gst', self::NO_GST),
            Slot::fixed('total', self::TOTAL, ['total']),
        ];

        // The amount in words is written in English only.
        if ($language === 'en') {
            $slots[] = Slot::fixed('total_words', self::TOTAL_WORDS, ['words' => 'total_words']);
        }

        if ($sheet->has('valid_until')) {
            $slots[] = Slot::fixed('validity', self::VALIDITY, ['valid_until']);
        }

        if ($withSchedule && $sheet->has('stage_count')) {
            $slots[] = Slot::fixed('stages_intro', self::STAGES_INTRO, ['stages' => 'stage_count']);

            for ($n = 1; $sheet->has("stage_{$n}_title"); $n++) {
                $slots[] = $sheet->has("stage_{$n}_percent")
                    ? Slot::fixed("stage_{$n}", self::STAGE_PERCENT, ['title' => "stage_{$n}_title", 'percent' => "stage_{$n}_percent", 'amount' => "stage_{$n}_amount"])
                    : Slot::fixed("stage_{$n}", self::STAGE_AMOUNT, ['title' => "stage_{$n}_title", 'amount' => "stage_{$n}_amount"]);

                if ($sheet->has("stage_{$n}_condition")) {
                    $slots[] = Slot::fixed("stage_{$n}_condition", self::STAGE_CONDITION, ['condition' => "stage_{$n}_condition"]);
                }
            }

            $slots[] = Slot::fixed('stages_total', self::STAGES_TOTAL, ['stages_total']);

            if ($sheet->has('stages_balance')) {
                $slots[] = Slot::fixed('stages_balance', self::STAGES_BALANCE, ['balance' => 'stages_balance']);
            }
        }

        $slots[] = Slot::free('closing', 'Invite the client to ask about any line. State no figure, date or promise.', self::CLOSING);

        return new DocumentType(self::TYPE, $slots);
    }

    /**
     * @param  array<string, mixed>|null  $slots  The quotation's slots column: the free sentences as stored.
     */
    public function render(string $language, FactSheet $sheet, ?array $slots, bool $withSchedule = true): Rendered
    {
        return $this->renderer->render($this->type($language, $sheet, $withSchedule), $sheet, $language, $this->freeTexts($slots));
    }

    /**
     * The free sentences a person typed, ready for the slots column. An empty text, or the code's own
     * sentence unchanged, is not kept: the slot then follows the code text in whatever language is chosen.
     *
     * @param  array<string, ?string>  $typed  Slot id => what the form sent.
     * @param  array<string, mixed>|null  $stored
     * @param  ?string  $storedLanguage  The language the stored sentences were written for, when it may differ from $language.
     * @return array<string, array{text: string, source: string}>
     */
    public function withTyped(string $language, FactSheet $sheet, ?array $stored, array $typed, ?string $storedLanguage = null): array
    {
        [$stored, $typed] = AiSentences::forLanguage(self::FREE_SLOTS, $stored, $typed, $storedLanguage !== null && $storedLanguage !== $language);
        $kept = $this->freeTexts($stored);
        $standing = $this->render($language, $sheet, $stored)->freeTexts();
        $code = $this->codeTexts();

        foreach (self::FREE_SLOTS as $id) {
            if (! array_key_exists($id, $typed)) {
                continue;
            }

            $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $typed[$id]));

            if ($text === '' || in_array($text, $code[$id], true)) {
                // Empty, or the code's own sentence in any language (the form still shows the old one when the language is changed).
                unset($kept[$id]);
            } elseif ($text !== ($standing[$id]['text'] ?? null)) {
                $kept[$id] = SlotText::edited($text)->toArray();
            }
        }

        return $kept;
    }

    /** @return list<string> One tick per line of the table: "line_1", "line_2" and so on. */
    public function lineTicks(FactSheet $sheet): array
    {
        $ticks = [];

        for ($n = 1; $sheet->has("line_{$n}_amount"); $n++) {
            $ticks[] = "line_{$n}";
        }

        return $ticks;
    }

    /** @return list<string> Everything a person ticks before issuing: each figure of the cover note, each typed number, each line. */
    public function ticksNeeded(Rendered $document, FactSheet $sheet): array
    {
        return [...$document->ticksNeeded(), ...$this->lineTicks($sheet)];
    }

    /**
     * @param  array<int, mixed>  $ticks  The request's number_ticks.
     *
     * @throws ValidationException Naming what is not ticked yet.
     */
    public function assertTicked(Rendered $document, FactSheet $sheet, array $ticks): void
    {
        $given = array_filter($ticks, 'is_string');
        $missing = $this->check->missingTicks($document, $ticks);
        $names = [];

        $typed = [];

        foreach ($document->typedNumbers() as $number) {
            $typed[$number->id] = $number->text.' ('.FinalCheck::typedLabel().')';
        }

        foreach ($missing as $key) {
            $names[] = $typed[$key] ?? self::label($key);
        }

        foreach ($this->lineTicks($sheet) as $line) {
            if (! in_array($line, $given, true)) {
                $names[] = self::label($line);
            }
        }

        if ($names !== []) {
            throw ValidationException::withMessages([
                'number_ticks' => __('Tick every figure in the draft before you continue. Not ticked yet: :figures', ['figures' => implode('; ', $names)]),
            ]);
        }
    }

    /**
     * The figures of the cover note and the numbers a person typed, in the order they are ticked.
     * The lines are ticked in their table.
     *
     * @return list<array{id: string, label: string, value: string, context: ?string}>
     */
    public function tickList(Rendered $document, FactSheet $sheet): array
    {
        $rows = [];

        foreach ($document->factKeys() as $key) {
            $rows[] = ['id' => $key, 'label' => self::label($key), 'value' => $sheet->get($key)->display($document->locale), 'context' => null];
        }

        foreach ($document->typedNumbers() as $number) {
            $rows[] = ['id' => $number->id, 'label' => FinalCheck::typedLabel(), 'value' => $number->text, 'context' => $number->context];
        }

        return $rows;
    }

    /** What a figure is, in the reader's language, for the review screen and for messages. */
    public static function label(string $key): string
    {
        if (preg_match('/^line_(\d+)(?:_(work|work_translated|unit|qty|rate|amount))?$/', $key, $m) === 1) {
            return match ($m[2] ?? '') {
                'work' => __('Line :n: work', ['n' => $m[1]]),
                'work_translated' => __('Line :n: work, translated', ['n' => $m[1]]),
                'unit' => __('Line :n: unit', ['n' => $m[1]]),
                'qty' => __('Line :n: quantity', ['n' => $m[1]]),
                'rate' => __('Line :n: rate', ['n' => $m[1]]),
                'amount' => __('Line :n: amount', ['n' => $m[1]]),
                default => __('Line :n', ['n' => $m[1]]),
            };
        }

        if (preg_match('/^stage_(\d+)_(title|percent|amount|condition)$/', $key, $m) === 1) {
            return match ($m[2]) {
                'title' => __('Stage :n: name', ['n' => $m[1]]),
                'percent' => __('Stage :n: share of the total', ['n' => $m[1]]),
                'amount' => __('Stage :n: amount', ['n' => $m[1]]),
                default => __('Stage :n: condition', ['n' => $m[1]]),
            };
        }

        return match ($key) {
            'quotation_number' => __('Quotation number'),
            'revision' => __('Revision'),
            'client_name' => __('Client name'),
            'line_count' => __('Number of lines'),
            'subtotal' => __('Value of the work'),
            'gst_pct' => __('GST percentage typed for this quotation'),
            'gst_amount' => __('GST amount'),
            'total' => __('Quotation total'),
            'total_words' => __('Total in words'),
            'valid_until' => __('Valid until'),
            'stage_count' => __('Number of payment stages'),
            'stages_total' => __('Total of the stages'),
            'stages_balance' => __('Balance not covered by a stage'),
            default => $key,
        };
    }

    /** @return array<string, list<string>> The code's own sentence for each free slot, in every language. */
    private function codeTexts(): array
    {
        $type = new DocumentType(self::TYPE, [
            Slot::free('opening', 'Open the cover note.', self::OPENING),
            Slot::free('closing', 'Close the cover note.', self::CLOSING),
        ]);
        $texts = array_fill_keys(self::FREE_SLOTS, []);

        foreach (FactFormat::LOCALES as $locale) {
            foreach ($this->templates->load($type, $locale) as $id => $text) {
                $texts[$id][] = $text;
            }
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>|null  $slots
     * @return array<string, array{text: string, source: string}> Only the free slots this document has, with a usable source.
     */
    private function freeTexts(?array $slots): array
    {
        $texts = [];

        foreach (self::FREE_SLOTS as $id) {
            $stored = $slots[$id] ?? null;

            // The code's own sentence is never stored as text: it would freeze the language it was written in.
            if (is_array($stored) && is_string($stored['text'] ?? null) && trim($stored['text']) !== '' && ($stored['source'] ?? null) !== SlotText::TEMPLATE) {
                $texts[$id] = SlotText::fromArray($stored)->toArray();
            }
        }

        return $texts;
    }
}
