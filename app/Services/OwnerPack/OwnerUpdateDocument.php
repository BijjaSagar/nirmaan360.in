<?php

namespace App\Services\OwnerPack;

use App\Services\Wording\DocumentType;
use App\Services\Wording\FactFormat;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FinalCheck;
use App\Services\Wording\Rendered;
use App\Services\Wording\RenderedSlot;
use App\Services\Wording\Renderer;
use App\Services\Wording\Slot;
use App\Services\Wording\SlotText;
use App\Services\Wording\TemplateLoader;
use Illuminate\Validation\ValidationException;

/**
 * A weekly update as a document of the wording service.
 *
 * Every sentence that states a date, a count, a quantity, a percentage or an amount is a fixed
 * sentence the code fills from the fact sheet. Only the opening and the closing are free sentences
 * a person may rewrite, and "next week" is the contractor's own text, printed under a line that
 * says so. The work rows and the photos are printed as a table and a gallery, not as sentences, so
 * each row ("work_2") and each photo ("photo_3") has one tick of its own.
 */
final class OwnerUpdateDocument
{
    public const TYPE = 'owner_update';

    /** The free sentences a person may rewrite, by slot id. The AI may write these two and no others (PackDrafts). */
    public const FREE_SLOTS = ['opening', 'closing'];

    /** The contractor's own words about next week. Kept in next_week_text, never in the slots column, and never written by a model. */
    public const PERSON_SLOT = 'next_week';

    /** Every slot of the payment position starts with this, so it can be left out as one block. */
    public const MONEY_PREFIX = 'pay_';

    private const OPENING = 'Here is the update on the work at your site.';

    private const CLOSING = 'Please tell us if you would like anything explained.';

    private const PERIOD = 'This update covers the week from {{from}} to {{to}}.';

    private const REPORTS = 'Approved daily reports of that week: {{reports}}.';

    private const NO_REPORTS = 'No daily report of that week has been approved yet, so no work is listed here.';

    private const WORK = 'Lines of work reported in them: {{lines}}. The table gives the quantity of each.';

    private const WORK_MORE = 'Lines of work that are not listed here: {{more}}.';

    private const WORKERS = 'The daily worker counts in these reports add up to {{workers}}.';

    private const PROGRESS = 'Work measured and approved so far: {{percent}} of the value of the bill of quantities, as on {{date}}.';

    private const PROGRESS_BASIS = 'This share is worked out from the quantities measured and approved, at the rates of the bill of quantities. It is not judged from photos.';

    private const PHOTOS = 'Photos with this update: {{photos}}. Each is shown with the date of the daily report it belongs to.';

    private const PAY_QUOTATION = 'Payment position on quotation {{number}}, revision {{revision}}, as on {{date}}. The total of that quotation is {{total}}.';

    private const PAY_STAGE = '{{title}}: {{amount}}, reached on {{date}}.';

    private const PAY_REACHED = 'The stages reached so far add up to {{reached}}.';

    private const PAY_NONE_REACHED = 'No payment stage has been reached yet.';

    private const PAY_RECEIVED = 'Received so far, as entered by the contractor: {{received}}.';

    private const PAY_DUE = 'Due on the stages reached, after what was received: {{due}}.';

    private const PAY_SETTLED = 'Nothing is due on the stages reached so far.';

    private const PAY_AHEAD = 'What was received is more than the stages reached so far by {{ahead}}.';

    private const NEXT_WEEK_INTRO = 'Planned for next week, as written by the contractor:';

    /** Never printed: the slot is part of the document only when the contractor wrote something. */
    private const NEXT_WEEK_EMPTY = 'Nothing has been written about next week.';

    public function __construct(private Renderer $renderer, private FinalCheck $check, private TemplateLoader $templates) {}

    /**
     * The update for this sheet. Which sentences it has follows from the facts.
     *
     * @param  bool  $withNextWeek  Whether the contractor wrote something about next week.
     * @param  bool  $withMoney  False leaves the payment position out, whatever the sheet holds.
     */
    public function type(string $language, FactSheet $sheet, bool $withNextWeek = false, bool $withMoney = true): DocumentType
    {
        FactFormat::locale($language);

        $slots = [
            Slot::free('opening', 'Greet the client and say that this is the update on the work at their site. State no figure, date or promise.', self::OPENING),
            Slot::fixed('period', self::PERIOD, ['from' => 'week_start', 'to' => 'week_end']),
            $sheet->get('report_count')->value === '0'
                ? Slot::fixed('reports', self::NO_REPORTS)
                : Slot::fixed('reports', self::REPORTS, ['reports' => 'report_count']),
        ];

        if ($sheet->has('work_count')) {
            $slots[] = Slot::fixed('work', self::WORK, ['lines' => 'work_count']);
        }

        if ($sheet->has('work_more')) {
            $slots[] = Slot::fixed('work_more', self::WORK_MORE, ['more' => 'work_more']);
        }

        if ($sheet->has('worker_days')) {
            $slots[] = Slot::fixed('workers', self::WORKERS, ['workers' => 'worker_days']);
        }

        if ($sheet->has('measured_pct')) {
            $slots[] = Slot::fixed('progress', self::PROGRESS, ['percent' => 'measured_pct', 'date' => 'as_on']);
            $slots[] = Slot::fixed('progress_basis', self::PROGRESS_BASIS);
        }

        if ($sheet->has('photo_count')) {
            $slots[] = Slot::fixed('photos', self::PHOTOS, ['photos' => 'photo_count']);
        }

        if ($withMoney && $sheet->has('pay_number')) {
            array_push($slots, ...$this->moneySlots($sheet));
        }

        if ($withNextWeek) {
            $slots[] = Slot::fixed('next_week_intro', self::NEXT_WEEK_INTRO);
            $slots[] = Slot::free(self::PERSON_SLOT, 'The contractor\'s own words about next week. A person types this; it is never written for them.', self::NEXT_WEEK_EMPTY);
        }

        $slots[] = Slot::free('closing', 'Invite the client to ask about anything in the update. State no figure, date or promise.', self::CLOSING);

        return new DocumentType(self::TYPE, $slots);
    }

    /**
     * @param  array<string, mixed>|null  $slots  The update's slots column: the opening and the closing as stored.
     * @param  ?string  $nextWeek  The update's next_week_text.
     */
    public function render(string $language, FactSheet $sheet, ?array $slots, ?string $nextWeek, bool $withMoney = true): Rendered
    {
        $nextWeek = self::cleaned($nextWeek);
        $free = $this->freeTexts($slots);

        if ($nextWeek !== '') {
            $free[self::PERSON_SLOT] = SlotText::edited($nextWeek)->toArray();
        }

        return $this->renderer->render($this->type($language, $sheet, $nextWeek !== '', $withMoney), $sheet, $language, $free);
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
        $standing = $this->render($language, $sheet, $stored, null)->freeTexts();
        $code = $this->codeTexts();

        foreach (self::FREE_SLOTS as $id) {
            if (! array_key_exists($id, $typed)) {
                continue;
            }

            $text = self::cleaned($typed[$id]);

            if ($text === '' || in_array($text, $code[$id], true)) {
                // Empty, or the code's own sentence in any language (the form still shows the old one when the language is changed).
                unset($kept[$id]);
            } elseif ($text !== ($standing[$id]['text'] ?? null)) {
                $kept[$id] = SlotText::edited($text)->toArray();
            }
        }

        return $kept;
    }

    /**
     * The rows of the work table, read from the sheet.
     *
     * @return list<array{n: int, name: string, qty: ?string, unit: ?string}> Quantity as printed.
     */
    public function workLines(FactSheet $sheet, string $locale): array
    {
        $lines = [];

        for ($n = 1; $sheet->has("work_{$n}_name"); $n++) {
            $lines[] = [
                'n' => $n,
                'name' => $sheet->get("work_{$n}_name")->value,
                'qty' => $sheet->find("work_{$n}_qty")?->display($locale),
                'unit' => $sheet->find("work_{$n}_unit")?->value,
            ];
        }

        return $lines;
    }

    /**
     * The photos of the update, read from the sheet. The id of each photo is the record its date was read from.
     *
     * @return list<array{n: int, id: int, date: string, caption: ?string}> Date as printed.
     */
    public function photos(FactSheet $sheet, string $locale): array
    {
        $photos = [];

        for ($n = 1; $sheet->has("photo_{$n}_date"); $n++) {
            $date = $sheet->get("photo_{$n}_date");

            $photos[] = [
                'n' => $n,
                'id' => (int) $date->source->id,
                'date' => $date->display($locale),
                'caption' => $sheet->find("photo_{$n}_caption")?->value,
            ];
        }

        return $photos;
    }

    /** @return list<string> One tick per row of the work table and one per photo: "work_1", "photo_1" and so on. */
    public function rowTicks(FactSheet $sheet): array
    {
        $ticks = [];

        for ($n = 1; $sheet->has("work_{$n}_name"); $n++) {
            $ticks[] = "work_{$n}";
        }

        for ($n = 1; $sheet->has("photo_{$n}_date"); $n++) {
            $ticks[] = "photo_{$n}";
        }

        return $ticks;
    }

    /** @return list<string> Everything a person ticks before approving: each figure of the text, each typed number, each work row and each photo. */
    public function ticksNeeded(Rendered $document, FactSheet $sheet): array
    {
        return [...$document->ticksNeeded(), ...$this->rowTicks($sheet)];
    }

    /**
     * @param  array<int, mixed>  $ticks  The request's number_ticks.
     *
     * @throws ValidationException Naming what is not ticked yet.
     */
    public function assertTicked(Rendered $document, FactSheet $sheet, array $ticks): void
    {
        $given = array_filter($ticks, 'is_string');
        $typed = [];
        $names = [];

        foreach ($document->typedNumbers() as $number) {
            $typed[$number->id] = $number->text.' ('.FinalCheck::typedLabel().')';
        }

        foreach ($this->check->missingTicks($document, $ticks) as $key) {
            $names[] = $typed[$key] ?? self::label($key);
        }

        foreach ($this->rowTicks($sheet) as $row) {
            if (! in_array($row, $given, true)) {
                $names[] = self::label($row);
            }
        }

        if ($names !== []) {
            throw ValidationException::withMessages([
                'number_ticks' => __('Tick every figure in the draft before you continue. Not ticked yet: :figures', ['figures' => implode('; ', $names)]),
            ]);
        }
    }

    /**
     * The figures of the text and the numbers a person typed, in the order they are ticked.
     * The work rows and the photos are ticked where they are shown.
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

    /**
     * The text as it stands, slot by slot, for the body_rendered column: what a person approved.
     *
     * @return list<array{id: string, text: string}>
     */
    public function body(Rendered $document): array
    {
        return array_map(fn (RenderedSlot $slot) => ['id' => $slot->id, 'text' => $slot->text], $document->slots);
    }

    /** Whether a slot belongs to the payment position. */
    public static function isMoneySlot(string $id): bool
    {
        return str_starts_with($id, self::MONEY_PREFIX);
    }

    /** What a figure is, in the reader's language, for the review screen and for messages. */
    public static function label(string $key): string
    {
        if (preg_match('/^work_(\d+)(?:_(name|qty|unit))?$/', $key, $m) === 1) {
            return match ($m[2] ?? '') {
                'name' => __('Work :n: what was done', ['n' => $m[1]]),
                'qty' => __('Work :n: quantity', ['n' => $m[1]]),
                'unit' => __('Work :n: unit', ['n' => $m[1]]),
                default => __('Work row :n', ['n' => $m[1]]),
            };
        }

        if (preg_match('/^photo_(\d+)(?:_(date|caption))?$/', $key, $m) === 1) {
            return match ($m[2] ?? '') {
                'date' => __('Photo :n: date of its report', ['n' => $m[1]]),
                'caption' => __('Photo :n: caption', ['n' => $m[1]]),
                default => __('Photo :n', ['n' => $m[1]]),
            };
        }

        if (preg_match('/^pay_stage_(\d+)_(title|amount|date)$/', $key, $m) === 1) {
            return match ($m[2]) {
                'title' => __('Stage reached :n: name', ['n' => $m[1]]),
                'amount' => __('Stage reached :n: amount', ['n' => $m[1]]),
                default => __('Stage reached :n: date', ['n' => $m[1]]),
            };
        }

        return match ($key) {
            'week_start' => __('First day of the week'),
            'week_end' => __('Last day of the week'),
            'as_on' => __('Date the figures were read'),
            'report_count' => __('Approved daily reports of the week'),
            'work_count' => __('Lines of work listed'),
            'work_more' => __('Lines of work not listed'),
            'worker_days' => __('Daily worker counts added up'),
            'measured_pct' => __('Work measured and approved so far'),
            'photo_count' => __('Photos with the update'),
            'pay_number' => __('Quotation number'),
            'pay_revision' => __('Revision'),
            'pay_total' => __('Quotation total'),
            'pay_reached_count' => __('Payment stages reached'),
            'pay_reached' => __('Total of the stages reached'),
            'pay_received' => __('Received so far, as entered by the contractor'),
            'pay_due' => __('Due on the stages reached'),
            'pay_ahead' => __('Received beyond the stages reached'),
            default => $key,
        };
    }

    /** @return list<Slot> */
    private function moneySlots(FactSheet $sheet): array
    {
        $slots = [
            Slot::fixed('pay_quotation', self::PAY_QUOTATION, ['number' => 'pay_number', 'revision' => 'pay_revision', 'date' => 'as_on', 'total' => 'pay_total']),
        ];

        for ($n = 1; $sheet->has("pay_stage_{$n}_title"); $n++) {
            $slots[] = Slot::fixed("pay_stage_{$n}", self::PAY_STAGE, ['title' => "pay_stage_{$n}_title", 'amount' => "pay_stage_{$n}_amount", 'date' => "pay_stage_{$n}_date"]);
        }

        $slots[] = $sheet->has('pay_reached')
            ? Slot::fixed('pay_reached', self::PAY_REACHED, ['reached' => 'pay_reached'])
            : Slot::fixed('pay_reached', self::PAY_NONE_REACHED);

        // Nothing is said about what is due unless the contractor typed what was received.
        if ($sheet->has('pay_received')) {
            $slots[] = Slot::fixed('pay_received', self::PAY_RECEIVED, ['received' => 'pay_received']);
            $slots[] = match (true) {
                $sheet->has('pay_due') => Slot::fixed('pay_due', self::PAY_DUE, ['due' => 'pay_due']),
                $sheet->has('pay_ahead') => Slot::fixed('pay_due', self::PAY_AHEAD, ['ahead' => 'pay_ahead']),
                default => Slot::fixed('pay_due', self::PAY_SETTLED),
            };
        }

        return $slots;
    }

    /** @return array<string, list<string>> The code's own sentence for each free slot, in every language. */
    private function codeTexts(): array
    {
        $type = new DocumentType(self::TYPE, [
            Slot::free('opening', 'Open the update.', self::OPENING),
            Slot::free('closing', 'Close the update.', self::CLOSING),
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
     * @return array<string, array{text: string, source: string}> Only the opening and the closing, with a usable source.
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

    private static function cleaned(?string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
    }
}
