<?php

namespace App\Services\TenderReading;

use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\CrmTenderFact;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Support\Collection;

/**
 * The tender sheet as a person reads it: the facts in groups, each with its page and the line it was
 * read from, contradictions side by side, what a later document changes, the checks code can make on
 * dates and the deposit, the uses a confirmed fact can be put to, and the pages that were not read.
 * Code never chooses between two values and never fills one in.
 */
class TenderSheet
{
    public function __construct(private TenderFactApply $apply) {}

    /** Dates that come one after the other in a tender, in this order. */
    private const DATE_ORDER = ['published_on', 'pre_bid_at', 'due_at', 'opens_at'];

    /** Dates that are a warning once they are past. */
    private const MUST_BE_AHEAD = ['pre_bid_at', 'due_at', 'opens_at', 'financial_opens_at'];

    /** @return array<string, mixed> */
    public function build(CrmTender $tender, User $viewer): array
    {
        $facts = CrmTenderFact::withoutGlobalScopes()
            ->where('organization_id', $tender->organization_id)
            ->where('tender_id', $tender->id)
            ->with('confirmer:id,name')
            ->orderBy('page')->orderBy('id')
            ->get();

        $documents = CrmTenderDocument::withoutGlobalScopes()
            ->where('organization_id', $tender->organization_id)->where('tender_id', $tender->id)
            ->get(['id', 'title', 'original_name', 'mime', 'deleted_at'])->keyBy('id');

        $standing = $facts->filter->isStanding();
        $canReview = $viewer->can('crm.manage');
        $context = [
            'documents' => $documents,
            'confirmed' => $facts->where('status', CrmTenderFact::CONFIRMED)->values(),
            'tender' => $tender,
            'viewer' => $viewer,
            'can_review' => $canReview,
            'can_pay' => $viewer->can('crm.emd'),
        ];

        return [
            'groups' => $this->groups($standing, $context),
            'counts' => [
                'proposed' => $facts->where('status', CrmTenderFact::PROPOSED)->count(),
                'confirmed' => $facts->where('status', CrmTenderFact::CONFIRMED)->count(),
                'rejected' => $facts->where('status', CrmTenderFact::REJECTED)->count(),
                'superseded' => $facts->where('status', CrmTenderFact::SUPERSEDED)->count(),
                'conflicts' => $standing->whereNotNull('conflict_group')->pluck('conflict_group')->uniqueStrict()->count(),
            ],
            'changes' => $this->changes($facts, $documents),
            'pictures' => $standing->contains(fn (CrmTenderFact $fact) => $fact->source === CrmTenderFact::FROM_IMAGE),
            'rejected' => $facts->where('status', CrmTenderFact::REJECTED)->values()->map(fn (CrmTenderFact $fact) => $this->view($fact, [...$context, 'can_review' => false]))->all(),
            'superseded' => $facts->where('status', CrmTenderFact::SUPERSEDED)->values()->map(fn (CrmTenderFact $fact) => $this->view($fact, [...$context, 'can_review' => false]))->all(),
            'warnings' => $this->dateWarnings($standing),
            'emd' => $this->emd($facts),
            'reads' => $this->reads($tender, $documents),
        ];
    }

    /**
     * @param  Collection<int, CrmTenderFact>  $standing
     * @param  array<string, mixed>  $context  What every fact's view needs: the documents, the confirmed facts, the tender and the viewer.
     * @return list<array<string, mixed>>
     */
    private function groups(Collection $standing, array $context): array
    {
        $order = array_flip(TenderFields::keys());
        $groups = [];

        foreach (TenderFields::groups() as $key => $heading) {
            $inGroup = $standing->filter(fn (CrmTenderFact $fact) => TenderFields::group($fact->field) === $key);

            if ($inGroup->isEmpty()) {
                continue;
            }

            $topics = $inGroup
                ->groupBy(fn (CrmTenderFact $fact) => TenderFactBuilder::topic($fact->field, $fact->label))
                ->sortBy(fn (Collection $facts) => [$order[$facts->first()->field] ?? 999, mb_strtolower((string) $facts->first()->label)])
                ->map(fn (Collection $facts) => [
                    'field' => $facts->first()->field,
                    'name' => __(TenderFields::label($facts->first()->field)),
                    'conflict' => $facts->whereNotNull('conflict_group')->isNotEmpty(),
                    // A different value from another document than the confirmed one: a change, not a contradiction in one document.
                    'change' => $facts->contains(fn (CrmTenderFact $fact) => $fact->status === CrmTenderFact::PROPOSED && TenderFactDiff::replaces($fact, $context['confirmed'])->isNotEmpty()),
                    'facts' => $facts->values()->map(fn (CrmTenderFact $fact) => $this->view($fact, $context))->all(),
                ])
                ->values()->all();

            $groups[] = ['key' => $key, 'heading' => __($heading), 'topics' => $topics];
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function view(CrmTenderFact $fact, array $context): array
    {
        $documents = $context['documents'];
        $canReview = (bool) $context['can_review'];
        $document = $documents->get($fact->document_id);
        $drawable = $document !== null && $document->deleted_at === null;
        $picture = $document !== null && ! TenderCard::looksLikePdf($document) && TenderCard::looksLikePicture($document);
        $line = $fact->quote_line ?? $fact->quote;
        $value = mb_strtolower(TenderQuote::spelling($fact->value_raw));

        return [
            'id' => $fact->id,
            'field' => $fact->field,
            'printed_label' => $fact->label,
            'value' => $fact->value_raw,
            'shown' => TenderValue::display($fact->value_kind, $fact->value_norm),
            'kind' => $fact->value_kind,
            'page' => (int) $fact->page,
            'source' => TenderReadWords::source($fact),
            'source_ok' => $fact->source === CrmTenderFact::FROM_TEXT && $fact->quote_found === true,
            'line' => $line,
            'value_in_line' => $value !== '' && str_contains(mb_strtolower(TenderQuote::spelling((string) $line)), $value),
            'status' => $fact->status,
            'edited' => (bool) $fact->edited,
            'conflict' => $fact->conflict_group !== null,
            'confirmed_by' => $fact->confirmer?->name,
            'confirmed_at' => $fact->confirmed_at,
            'document' => $document?->title,
            // A document removed from the tender can no longer be drawn; its facts stay readable. A picture document is shown as it is.
            'pdf_url' => $drawable && ! $picture ? route('crm.documents.download', $document->id) : null,
            'picture_url' => $drawable && $picture ? route('crm.documents.download', $document->id) : null,
            'can_edit' => $canReview && $fact->status === CrmTenderFact::PROPOSED,
            'can_confirm' => $canReview && $fact->status === CrmTenderFact::PROPOSED,
            'can_reject' => $canReview && $fact->isStanding() && $fact->applied_to === null,
            'replaces' => $fact->status === CrmTenderFact::PROPOSED
                ? TenderFactDiff::replaces($fact, $context['confirmed'])->map(fn (CrmTenderFact $old) => ['page' => (int) $old->page, 'document' => $documents->get($old->document_id)?->title])->all()
                : [],
            'applied' => $fact->applied_to !== null,
            'uses' => $canReview && $fact->status === CrmTenderFact::CONFIRMED ? $this->uses($fact, $context) : [],
        ];
    }

    /**
     * The uses a confirmed fact can be put to, each with what it will do, whether it was done, and
     * why it cannot be done now. A planned payment is offered only to someone who records EMD and fees.
     *
     * @param  array<string, mixed>  $context
     * @return list<array{target: string, preview: string, done: bool, blocked: ?string}>
     */
    private function uses(CrmTenderFact $fact, array $context): array
    {
        $done = $fact->uses();
        $uses = [];

        foreach (TenderFactApply::targets($fact) as $target) {
            if ($target === TenderFactApply::PAYMENT && ! $context['can_pay']) {
                continue;
            }

            $applied = array_key_exists($target, $done);

            $uses[] = [
                'target' => $target,
                'preview' => TenderFactApply::preview($fact, $target),
                'done' => $applied,
                'blocked' => $applied ? null : $this->apply->blocker($fact, $target, $context['viewer'], $context['tender'])['message'] ?? null,
            ];
        }

        return $uses;
    }

    /**
     * What another document changes, field by field: the confirmed value and the new one, each with
     * its document and page.
     *
     * @param  Collection<int, CrmTenderFact>  $facts
     * @param  Collection<int, CrmTenderDocument>  $documents
     * @return list<array<string, mixed>>
     */
    private function changes(Collection $facts, Collection $documents): array
    {
        $side = fn (CrmTenderFact $fact) => [
            'id' => $fact->id,
            'value' => $fact->value_raw,
            'page' => (int) $fact->page,
            'document' => $documents->get($fact->document_id)?->title,
            'applied' => $fact->applied_to !== null,
        ];

        return array_map(fn (array $change) => [
            'name' => __(TenderFields::label($change['field'])),
            'old' => $change['old']->map($side)->all(),
            'new' => $change['new']->map($side)->all(),
        ], TenderFactDiff::changes($facts));
    }

    /**
     * Dates out of order, and dates already past. Only a date the sheet states one way is looked at:
     * where the tender says two things, the contradiction is the warning.
     *
     * @param  Collection<int, CrmTenderFact>  $standing
     * @return list<string>
     */
    private function dateWarnings(Collection $standing): array
    {
        $single = [];

        foreach ([...self::DATE_ORDER, ...self::MUST_BE_AHEAD] as $field) {
            $values = $standing->where('field', $field)->pluck('value_norm')->uniqueStrict()->values();

            if ($values->count() === 1 && $values[0] !== null) {
                $single[$field] = (string) $values[0];
            }
        }

        $warnings = [];
        $earlier = null;

        foreach (self::DATE_ORDER as $field) {
            if (! isset($single[$field])) {
                continue;
            }

            if ($earlier !== null && $this->before($single[$field], $single[$earlier])) {
                $warnings[] = __(':later comes before :earlier on this sheet. Check both against the pages.', [
                    'later' => __(TenderFields::label($field)),
                    'earlier' => __(TenderFields::label($earlier)),
                ]);
            }

            $earlier = $field;
        }

        $today = now('Asia/Kolkata')->toDateString();

        foreach (self::MUST_BE_AHEAD as $field) {
            if (isset($single[$field]) && substr($single[$field], 0, 10) < $today) {
                $warnings[] = __(':field on this sheet is already past.', ['field' => __(TenderFields::label($field))]);
            }
        }

        return array_values(array_unique($warnings));
    }

    /** Whether one date, or date and time, comes before another. Days are compared when one of them has no time. */
    private function before(string $one, string $two): bool
    {
        return strlen($one) > 10 && strlen($two) > 10 ? $one < $two : substr($one, 0, 10) < substr($two, 0, 10);
    }

    /**
     * The deposit worked out from the confirmed percentage and the confirmed estimated value, beside
     * each printed deposit amount. Shown only once both inputs are confirmed, each one way.
     *
     * @param  Collection<int, CrmTenderFact>  $facts
     * @return array<string, mixed>|null
     */
    private function emd(Collection $facts): ?array
    {
        $confirmed = fn (string $field) => $facts->where('status', CrmTenderFact::CONFIRMED)->where('field', $field)->pluck('value_norm')->filter()->uniqueStrict()->values();
        $percent = $confirmed('emd_percent');
        $estimated = $confirmed('estimated_value');

        if ($percent->count() !== 1 || $estimated->count() !== 1) {
            return null;
        }

        $worked = Dec::percentOf($estimated[0], $percent[0]);

        $printed = $facts->filter->isStanding()->where('field', 'emd_amount')->filter(fn (CrmTenderFact $fact) => $fact->value_norm !== null)
            ->map(fn (CrmTenderFact $fact) => [
                'amount' => inr($fact->value_norm),
                'page' => (int) $fact->page,
                'status' => $fact->status,
                'differs' => ! Dec::of($fact->value_norm)->isEqualTo(Dec::of($worked)),
            ])->values()->all();

        return [
            'percent' => TenderValue::display(TenderFields::PERCENT, (string) $percent[0]),
            'estimated' => inr($estimated[0]),
            'worked' => inr($worked),
            'printed' => $printed,
        ];
    }

    /**
     * Every read of the tender's documents, newest first, with the pages that were not read as text
     * and why, and the pages whose text looked like instructions.
     *
     * @param  Collection<int, CrmTenderDocument>  $documents
     * @return list<array<string, mixed>>
     */
    private function reads(CrmTender $tender, Collection $documents): array
    {
        if ($documents->isEmpty()) {
            return [];
        }

        $reads = DocumentRead::withoutGlobalScopes()
            ->where('organization_id', $tender->organization_id)
            ->where('kind', TenderReadKind::KEY)
            ->where('subject_type', (new CrmTenderDocument)->getMorphClass())
            ->whereIn('subject_id', $documents->keys())
            ->orderByDesc('id')->limit(20)->get();

        $rows = DocumentReadPage::withoutGlobalScopes()
            ->where('organization_id', $tender->organization_id)
            ->whereIn('document_read_id', $reads->modelKeys())
            ->get(['id', 'document_read_id', 'page_no', 'page_to', 'pass', 'status', 'fail_reason', 'injection_flags'])
            ->groupBy('document_read_id');

        return $reads->map(function (DocumentRead $read) use ($documents, $rows) {
            $pages = $rows->get($read->id, collect());

            return [
                'id' => $read->id,
                'document' => $documents->get($read->subject_id)?->title,
                'status' => $read->status,
                'started' => $read->started_at !== null,
                'created_at' => $read->created_at,
                'not_read' => $read->started_at === null ? [] : $this->notRead($read, $pages),
                'flagged' => $this->ranges($pages->filter(fn (DocumentReadPage $page) => ! empty($page->injection_flags))
                    ->flatMap(fn (DocumentReadPage $page) => range((int) $page->page_no, max((int) $page->page_no, (int) $page->page_to)))->all()),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, DocumentReadPage>  $pages
     * @return list<array{pages: string, why: string}>
     */
    private function notRead(DocumentRead $read, Collection $pages): array
    {
        $why = [];
        $flags = (array) ($read->options['page_flags'] ?? []);
        $covered = [];
        $failed = [];

        foreach ($pages as $page) {
            foreach (range((int) $page->page_no, max((int) $page->page_no, (int) $page->page_to)) as $number) {
                if (in_array($page->status, [DocumentReadPage::READ, DocumentReadPage::SUPERSEDED], true)) {
                    $covered[$number] = true;
                } elseif ($page->status === DocumentReadPage::FAILED) {
                    $failed[$number] = true;
                }
            }
        }

        // A page that can only be read as a picture is not read when no picture of it was: for want of a
        // trustworthy text on a text read, for want of an uploaded picture on a read of pictures.
        foreach (TenderReads::picturePages($read) as $number) {
            if (isset($covered[$number])) {
                continue;
            }

            $why[$number] = match (true) {
                isset($failed[$number]) => 'failed',
                TenderReads::source($read) !== TenderReads::SOURCE_TEXT => 'not_sent',
                default => (string) (($flags[$number] ?? $flags[(string) $number] ?? ['mismatch'])[0] ?? 'mismatch'),
            };
        }

        foreach ($pages as $page) {
            if (in_array($page->status, [DocumentReadPage::READ, DocumentReadPage::SUPERSEDED], true)) {
                continue;
            }

            foreach (range((int) $page->page_no, max((int) $page->page_no, (int) $page->page_to)) as $number) {
                if (! isset($covered[$number])) {
                    $why[$number] ??= $page->status === DocumentReadPage::FAILED ? 'failed' : 'not_sent';
                }
            }
        }

        foreach ((array) ($read->review['unreadable_pages'] ?? []) as $number) {
            $why[(int) $number] ??= 'unreadable';
        }

        ksort($why);
        $byReason = [];

        foreach ($why as $number => $reason) {
            $byReason[$reason][] = (int) $number;
        }

        $lines = [];

        foreach ($byReason as $reason => $numbers) {
            $lines[] = [
                'pages' => $this->ranges($numbers),
                'why' => $reason === 'not_sent' ? __('it was not sent to be read') : TenderReadWords::pageFlag($reason),
            ];
        }

        return $lines;
    }

    /** @param  list<int>  $numbers  "3, 5–7" */
    private function ranges(array $numbers): string
    {
        $numbers = array_values(array_unique(array_map(intval(...), $numbers)));
        sort($numbers);
        $parts = [];

        for ($i = 0; $i < count($numbers); $i++) {
            $start = $numbers[$i];

            while ($i + 1 < count($numbers) && $numbers[$i + 1] === $numbers[$i] + 1) {
                $i++;
            }

            $parts[] = $start === $numbers[$i] ? (string) $start : $start.'–'.$numbers[$i];
        }

        return implode(', ', $parts);
    }
}
