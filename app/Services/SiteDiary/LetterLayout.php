<?php

namespace App\Services\SiteDiary;

use App\Models\SiteLetter;
use App\Services\Wording\DocumentType;
use App\Services\Wording\Fact;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Services\Wording\Rendered;
use App\Services\Wording\RenderedSlot;
use App\Services\Wording\Slot;
use InvalidArgumentException;

/**
 * How each kind of letter is laid out: which sentences it has and in what order.
 *
 * A sentence that states a date, a number of days, an amount, a reference or a name is a fixed
 * sentence. Its words are written here, and the figure is a {{token}} the Wording service fills
 * from a fact. The opening, the linking sentence and the close state no figure; they are the free
 * sentences, each with code text of its own, so a letter is complete with no AI at all.
 *
 * The sentences are English here. Their Hindi and Marathi are in lang/modules/site_diary, like
 * every other text of the module. A letter has one block for each hindrance or bill it cites, so
 * the layout is built for one fact sheet at a time.
 */
final class LetterLayout
{
    /** Fixed sentences. Token => what it stands for is decided where the sentence is used. */
    public const FIXED = [
        'date' => 'Date: {{letter_date}}',
        'to' => 'To: {{addressed_to}}',
        'subject.eot_request' => 'Subject: Request for extension of time for the work {{project_name}}',
        'subject.delay_notice' => 'Subject: Notice of delay to the work {{project_name}}',
        'subject.payment_followup' => 'Subject: Payment outstanding on our bills for the work {{project_name}}',
        'reference.both' => 'Reference: Agreement {{agreement_no}} dated {{agreement_date}}',
        'reference.number' => 'Reference: Agreement {{agreement_no}}',
        'reference.date' => 'Reference: Agreement dated {{agreement_date}}',
        'completion' => 'The stipulated date of completion of the work is {{stipulated_completion}}.',
        'extension' => 'The time for completion has since been extended up to {{extension_granted}}.',
        'what' => '{{what}}',
        'period.ended' => 'From {{start}} to {{end}}: {{days}} calendar days, counting the first and the last day.',
        'period.ongoing' => 'From {{start}} and still going on: {{days}} calendar days up to {{as_on}}, counting the first day and that day.',
        'reports.single' => 'Recorded in our daily site reports of {{first}}.',
        'reports.several' => 'Recorded in our daily site reports on {{report_days}} dates, from {{first}} to {{last}}.',
        'total_days' => 'Counting every calendar day once, also where hindrances overlap, these come to {{total_days}} days.',
        'request' => 'We request that the time for completion be extended up to {{proposed_completion}}.',
        'clause' => 'Reference in the agreement: {{clause}}',
        'bill' => 'Bill {{number}} dated {{date}}: amount receivable {{receivable}}, received so far {{received}}.',
        'bill.tds' => 'Tax deducted at source on this bill, as shown on the receipts: {{tds}}.',
        'bill.due' => 'The amount outstanding on bill {{number}} is {{outstanding}}.',
        'bill.age' => 'Days from the bill date to the date of this letter: {{days}}.',
        'total_due' => 'The total outstanding on these bills is {{total_outstanding}}.',
        'signature' => 'For {{company_name}}',
    ];

    /** Free sentences: letter type => slot => the code text used when nobody wrote the slot. */
    public const FREE = [
        SiteLetter::EOT_REQUEST => [
            'opening' => 'Dear Sir or Madam, we write to request more time to complete the work named above, for the reasons set out below.',
            'linking' => 'The following hindrances to the work are recorded in our daily site reports.',
            'closing' => 'We request you to consider the above and to grant the extension of time. Kindly acknowledge receipt of this letter.',
        ],
        SiteLetter::DELAY_NOTICE => [
            'opening' => 'Dear Sir or Madam, we write to put on record that the work named above is being held up, as set out below.',
            'linking' => 'The following hindrances are still going on and are recorded in our daily site reports.',
            'closing' => 'We request you to take note of the above and to help remove the hindrance at the earliest. Kindly acknowledge receipt of this letter.',
        ],
        SiteLetter::PAYMENT_FOLLOWUP => [
            'opening' => 'Dear Sir or Madam, we write about the payment still due on our bills for the work named above.',
            'linking' => 'The position of each bill, as per our records, is given below.',
            'closing' => 'We request you to release the outstanding amount at the earliest. Kindly acknowledge receipt of this letter.',
        ],
    ];

    /** What each free sentence is for, as a model would be told. English, never shown to a customer. */
    public const PURPOSES = [
        'opening' => 'Greet the reader and say in plain words what the letter is about. State no date, number, amount or clause.',
        'linking' => 'One sentence that leads from the opening to the list of records that follows. State no date, number, amount or clause.',
        'closing' => 'Ask politely for what the letter is about and for an acknowledgement. State no date, number, amount or clause, and do not say that any claim is valid.',
    ];

    /** Names for the free sentences on the screen where a person may rewrite them. */
    public const FREE_NAMES = [
        'opening' => 'Opening sentences',
        'linking' => 'Sentence before the list',
        'closing' => 'Closing sentences',
    ];

    /** What each fact is, shown beside its figure. ":index" is the number of the hindrance or bill in the letter. */
    public const LABELS = [
        'letter_date' => 'Date of the letter',
        'addressed_to' => 'Addressed to, as typed by you',
        'project_name' => 'Name of the work',
        'company_name' => 'Name of your company',
        'agreement_no' => 'Agreement number, as entered under Contract terms',
        'agreement_date' => 'Agreement date, as entered under Contract terms',
        'stipulated_completion' => 'Stipulated completion date, as entered under Contract terms',
        'extension_granted' => 'Latest extension granted, as entered under Contract terms',
        'total_days' => 'Days of all the hindrances, a day they share counted once',
        'proposed_completion' => 'Proposed completion date, worked out by the app',
        'proposed_completion.entered' => 'Proposed completion date, entered by you',
        'total_outstanding' => 'Total outstanding on these bills',
        'clause' => 'Clause or page :index in the agreement, as typed under Contract terms',
        'h.category' => 'Hindrance :index: what held the work up',
        'h.what' => 'Hindrance :index: description from the register',
        'h.start' => 'Hindrance :index: first day',
        'h.end' => 'Hindrance :index: last day',
        'h.days' => 'Hindrance :index: calendar days, both ends counted',
        'h.report_days' => 'Hindrance :index: dates that have a cited daily report',
        'h.first_report' => 'Hindrance :index: date of the first cited daily report',
        'h.last_report' => 'Hindrance :index: date of the last cited daily report',
        'bill.number' => 'Bill :index: number',
        'bill.date' => 'Bill :index: date',
        'bill.receivable' => 'Bill :index: amount receivable',
        'bill.received' => 'Bill :index: received so far',
        'bill.tds' => 'Bill :index: TDS shown on the receipts',
        'bill.outstanding' => 'Bill :index: outstanding',
        'bill.days' => 'Bill :index: days from the bill date to the date of the letter',
    ];

    /** Where a fact was read from, by the type of its source. */
    public const SOURCES = [
        'hindrance' => 'Hindrance register',
        'hindrance_source' => 'Daily reports cited in the register',
        'contract_term' => 'Contract terms, as entered and confirmed',
        'ra_bill' => 'Client bills and the receipts recorded on them',
        'project' => 'Project details',
        'organization' => 'Company details',
        FactSource::COMPUTED => 'Worked out by the app',
        FactSource::ENTERED => 'Typed by you on this letter',
    ];

    /** Carried by every letter, on the screen. */
    public const CAUTION = 'Draft prepared from your site records. Check it against your agreement before sending.';

    /** The parts of a key that names one hindrance or one bill: h3_start, bill_2_outstanding, clause_1. */
    private const NUMBERED = '/^(?<of>h|bill|clause)_?(?<index>\d+)(?:_(?<what>[a-z_]+))?$/';

    /**
     * The layout of one letter. Which sentences it has follows from the facts on the sheet alone,
     * so the same sheet always gives the same letter.
     */
    public function type(string $type, FactSheet $sheet): DocumentType
    {
        if (! isset(self::FREE[$type])) {
            throw new InvalidArgumentException("'{$type}' is not a kind of letter.");
        }

        $free = fn (string $id) => Slot::free($id, self::PURPOSES[$id], self::FREE[$type][$id]);
        $extension = $type === SiteLetter::EOT_REQUEST;
        $clauses = [];

        for ($n = 1; $sheet->has("clause_{$n}"); $n++) {
            $clauses[] = Slot::fixed("clause_{$n}", self::FIXED['clause'], ['clause' => "clause_{$n}"]);
        }

        $slots = [
            Slot::fixed('date_line', self::FIXED['date'], ['letter_date']),
            ...$this->when($sheet->has('addressed_to'), fn () => [Slot::fixed('to_line', self::FIXED['to'], ['addressed_to'])]),
            Slot::fixed('subject', self::FIXED["subject.{$type}"], ['project_name']),
            ...$this->reference($sheet),
            $free('opening'),
            ...$this->when($extension, fn () => [Slot::fixed('completion', self::FIXED['completion'], ['stipulated_completion'])]),
            ...$this->when($extension && $sheet->has('extension_granted'), fn () => [Slot::fixed('extension', self::FIXED['extension'], ['extension_granted'])]),
            $free('linking'),
            ...($type === SiteLetter::PAYMENT_FOLLOWUP
                ? [...$this->bills($sheet), Slot::fixed('total_due', self::FIXED['total_due'], ['total_outstanding'])]
                : $this->hindrances($sheet)),
            ...$this->when($extension, fn () => [
                Slot::fixed('total', self::FIXED['total_days'], ['total_days']),
                Slot::fixed('request', self::FIXED['request'], ['proposed_completion']),
            ]),
            ...$clauses,
            $free('closing'),
            Slot::fixed('signature', self::FIXED['signature'], ['company_name']),
        ];

        return new DocumentType($type, $slots);
    }

    /**
     * The letter as plain text: the lines of one hindrance or one bill stay together, and an empty
     * line stands between the parts. This is what is kept as the letter's body and what is copied.
     */
    public function text(Rendered $document): string
    {
        return implode("\n\n", array_map(
            fn (array $block) => implode("\n", array_map(fn (RenderedSlot $slot) => $slot->text, $block)),
            $this->blocks($document->slots),
        ));
    }

    /**
     * Slots, or anything else keyed by 'id', gathered into the parts of the letter.
     *
     * @template T of object|array
     *
     * @param  list<T>  $slots
     * @return list<list<T>>
     */
    public function blocks(array $slots): array
    {
        $blocks = [];
        $last = null;

        foreach ($slots as $slot) {
            $block = self::blockOf(is_array($slot) ? $slot['id'] : $slot->id);

            if ($block !== $last) {
                $blocks[] = [];
                $last = $block;
            }

            $blocks[array_key_last($blocks)][] = $slot;
        }

        return $blocks;
    }

    /** The part of the letter a slot belongs to. */
    public static function blockOf(string $slotId): string
    {
        if (in_array($slotId, ['date_line', 'to_line'], true)) {
            return 'head';
        }

        if (in_array($slotId, ['subject', 'reference'], true)) {
            return 'subject';
        }

        if (preg_match('/^(?<of>h|bill)(?<index>\d+)(_|$)/', $slotId, $parts) === 1) {
            return $parts['of'].$parts['index'];
        }

        return str_starts_with($slotId, 'clause_') ? 'clauses' : $slotId;
    }

    /** The label of a fact as it is kept on the sheet: English, with the number of its hindrance or bill. */
    public static function englishLabel(string $key, bool $entered = false): string
    {
        [$label, $index] = self::labelOf($key, $entered);

        return str_replace(':index', (string) $index, $label);
    }

    /** The same label in the reader's language. */
    public function label(Fact $fact): string
    {
        [$label, $index] = self::labelOf($fact->key, $fact->source->type === FactSource::ENTERED);

        return __($label, ['index' => (string) $index]);
    }

    /** Where a fact was read from, in the reader's language. */
    public function source(Fact $fact): string
    {
        return __(self::SOURCES[$fact->source->type] ?? self::SOURCES[FactSource::COMPUTED]);
    }

    /** The code text of a free sentence in the letter's own language. */
    public function freeText(string $type, string $slotId, string $language): string
    {
        return __(self::FREE[$type][$slotId], [], $language);
    }

    /** @return list<string> Every sentence and label of this class that is translated: for the module's wording test. */
    public static function wording(): array
    {
        $fixed = array_values(array_filter(self::FIXED, fn (string $template) => preg_match('/\p{L}/u', (string) preg_replace(Slot::TOKEN_PATTERN, '', $template)) === 1));
        $free = array_merge(...array_map('array_values', array_values(self::FREE)));

        return [...$fixed, ...$free, ...array_values(self::FREE_NAMES), ...array_values(self::LABELS), ...array_values(self::SOURCES), self::CAUTION];
    }

    /** @return array{0: string, 1: ?int} The label with ":index" still in it, and the number that goes there. */
    private static function labelOf(string $key, bool $entered): array
    {
        if (preg_match(self::NUMBERED, $key, $parts) === 1) {
            $name = $parts['of'] === 'clause' ? 'clause' : $parts['of'].'.'.($parts['what'] ?? '');

            if (isset(self::LABELS[$name])) {
                return [self::LABELS[$name], (int) $parts['index']];
            }
        }

        $name = $entered && isset(self::LABELS[$key.'.entered']) ? $key.'.entered' : $key;

        return [self::LABELS[$name] ?? throw new InvalidArgumentException("No label is written for the fact '{$key}'."), null];
    }

    /** @return list<Slot> */
    private function reference(FactSheet $sheet): array
    {
        return match (true) {
            $sheet->has('agreement_no') && $sheet->has('agreement_date') => [Slot::fixed('reference', self::FIXED['reference.both'], ['agreement_no', 'agreement_date'])],
            $sheet->has('agreement_no') => [Slot::fixed('reference', self::FIXED['reference.number'], ['agreement_no'])],
            $sheet->has('agreement_date') => [Slot::fixed('reference', self::FIXED['reference.date'], ['agreement_date'])],
            default => [],
        };
    }

    /** @return list<Slot> One block for each hindrance on the sheet: what it was, how long it lasted and where it is recorded. */
    private function hindrances(FactSheet $sheet): array
    {
        $slots = [];

        for ($n = 1; $sheet->has("h{$n}_start"); $n++) {
            // The name of the category is code text of its own, so it needs no tick. The key is on the sheet, so a change of category is noticed.
            $category = config('modules.site_diary.categories')[$sheet->get("h{$n}_category")->value] ?? null;

            if ($category !== null) {
                $slots[] = Slot::fixed("h{$n}_title", $category);
            }

            $slots[] = Slot::fixed("h{$n}_what", self::FIXED['what'], ['what' => "h{$n}_what"]);
            $slots[] = $sheet->has("h{$n}_end")
                ? Slot::fixed("h{$n}_period", self::FIXED['period.ended'], ['start' => "h{$n}_start", 'end' => "h{$n}_end", 'days' => "h{$n}_days"])
                : Slot::fixed("h{$n}_period", self::FIXED['period.ongoing'], ['start' => "h{$n}_start", 'days' => "h{$n}_days", 'as_on' => 'letter_date']);

            if ($sheet->has("h{$n}_last_report")) {
                $slots[] = Slot::fixed("h{$n}_reports", self::FIXED['reports.several'], ['report_days' => "h{$n}_report_days", 'first' => "h{$n}_first_report", 'last' => "h{$n}_last_report"]);
            } elseif ($sheet->has("h{$n}_first_report")) {
                $slots[] = Slot::fixed("h{$n}_reports", self::FIXED['reports.single'], ['first' => "h{$n}_first_report"]);
            }
        }

        return $slots;
    }

    /** @return list<Slot> One block for each bill on the sheet. */
    private function bills(FactSheet $sheet): array
    {
        $slots = [];

        for ($n = 1; $sheet->has("bill_{$n}_number"); $n++) {
            $slots[] = Slot::fixed("bill{$n}", self::FIXED['bill'], [
                'number' => "bill_{$n}_number", 'date' => "bill_{$n}_date", 'receivable' => "bill_{$n}_receivable", 'received' => "bill_{$n}_received",
            ]);

            if ($sheet->has("bill_{$n}_tds")) {
                $slots[] = Slot::fixed("bill{$n}_tds", self::FIXED['bill.tds'], ['tds' => "bill_{$n}_tds"]);
            }

            $slots[] = Slot::fixed("bill{$n}_due", self::FIXED['bill.due'], ['number' => "bill_{$n}_number", 'outstanding' => "bill_{$n}_outstanding"]);

            if ($sheet->has("bill_{$n}_days")) {
                $slots[] = Slot::fixed("bill{$n}_age", self::FIXED['bill.age'], ['days' => "bill_{$n}_days"]);
            }
        }

        return $slots;
    }

    /**
     * @param  callable(): list<Slot>  $slots
     * @return list<Slot>
     */
    private function when(bool $condition, callable $slots): array
    {
        return $condition ? $slots() : [];
    }
}
