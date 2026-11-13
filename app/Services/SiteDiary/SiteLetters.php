<?php

namespace App\Services\SiteDiary;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Hindrance;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\SiteLetter;
use App\Models\SiteLetterHindrance;
use App\Models\User;
use App\Services\Wording\Fact;
use App\Services\Wording\FactFormat;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FinalCheck;
use App\Services\Wording\GuardViolation;
use App\Services\Wording\Rendered;
use App\Services\Wording\Renderer;
use App\Services\Wording\SlotText;
use App\Services\Wording\TypedNumber;
use App\Support\ClientUuidRepeat;
use App\Support\Idempotent;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Letters drafted from the hindrance register and from unpaid client bills.
 *
 * Preparing a draft reads the facts from the records and puts the letter together from code
 * sentences: no model is called here, and no figure is taken from anything a person or a model
 * wrote. A person may rewrite the opening, the linking sentence and the close; a number typed
 * there is listed as typed by them.
 *
 * Finalising reads the facts again. If a record changed since the draft was prepared the letter
 * is refused, and so it is while any figure in it, or any number typed by hand, is not ticked.
 * A finalised letter can no longer be changed, and the register entries it cites are locked.
 * Nothing is sent by the app.
 */
class SiteLetters
{
    public function __construct(
        private LetterFacts $facts,
        private LetterLayout $layout,
        private Renderer $renderer,
        private FinalCheck $check,
    ) {}

    /**
     * @param  array<string, mixed>  $input  The request, read only to pick the rules.
     * @param  SiteLetter|null  $letter  The draft being changed, or null when one is being prepared.
     */
    public function rules(Project $project, array $input, ?SiteLetter $letter = null): array
    {
        $type = $letter?->type ?? (in_array($input['type'] ?? null, SiteLetter::TYPES, true) ? $input['type'] : null);
        $ofProject = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $project->organization_id)->where('project_id', $project->id);
        $leftOut = ['exclude'];

        $rules = [
            // Sent by the form, so pressing the button twice prepares one letter.
            'client_uuid' => $letter === null ? ['required', 'uuid'] : $leftOut,
            'type' => $letter === null ? ['required', 'string', Rule::in(SiteLetter::TYPES)] : $leftOut,
            'language' => $letter === null ? ['required', 'string', Rule::in(FactFormat::LOCALES)] : $leftOut,
            'addressed_to' => ['nullable', 'string', 'max:200'],
            'hindrance_ids' => $leftOut,
            'bill_ids' => $leftOut,
            'proposed_completion' => $type === SiteLetter::EOT_REQUEST ? ['nullable', 'date_format:Y-m-d'] : $leftOut,
            'slots' => $letter === null ? $leftOut : ['nullable', 'array'],
        ];

        if ($type === SiteLetter::PAYMENT_FOLLOWUP) {
            $rules['bill_ids'] = ['required', 'array', 'min:1', 'max:25'];
            $rules['bill_ids.*'] = ['bail', 'integer', 'distinct', $ofProject('ra_bills')->where('type', 'client')];
        } elseif ($type !== null) {
            $rules['hindrance_ids'] = ['required', 'array', 'min:1', 'max:25'];
            $rules['hindrance_ids.*'] = ['bail', 'integer', 'distinct', $ofProject('hindrances')->whereNull('deleted_at')];
        }

        if ($letter !== null) {
            $rules['slots.*'] = ['nullable', 'string', 'max:2000'];
        }

        return $rules;
    }

    /** Whether this person may read a letter of this kind: a payment follow-up is nothing but money. */
    public function mayRead(string $type, ?User $user): bool
    {
        return $type !== SiteLetter::PAYMENT_FOLLOWUP || Money::visible($user);
    }

    /** @return list<string> The kinds of letter this person may prepare on this project. */
    public function typesFor(Project $project, User $user): array
    {
        $bills = $this->facts->billsAreKept($project->organization) && Money::visible($user);

        return array_values(array_filter(SiteLetter::TYPES, fn (string $type) => $type !== SiteLetter::PAYMENT_FOLLOWUP || $bills));
    }

    /**
     * Prepare a draft. A repeated client_uuid returns the original letter.
     *
     * @param  array<string, mixed>  $data  Validated with rules().
     *
     * @throws ValidationException
     */
    public function create(Project $project, array $data, User $by): SiteLetter
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? ClientUuidRepeat::reachable(SiteLetter::withoutGlobalScopes()->where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first()) : null;

        if ($existing = $find()) {
            return $this->sameProject($existing, $project);
        }

        return $this->sameProject(Idempotent::create(fn () => DB::transaction(function () use ($project, $data, $by, $uuid) {
            $this->lockProject($project->id);

            $chosen = $this->chosen($data['type'], $data);
            [, $document, $columns] = $this->prepare($project, $data['type'], $data['language'], $chosen, []);

            $letter = SiteLetter::create([
                ...$columns,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'type' => $data['type'],
                'language' => $data['language'],
                'status' => SiteLetter::DRAFT,
                'client_uuid' => $uuid,
            ]);

            $this->cite($letter, $chosen['hindrance_ids']);
            AuditLog::record('site_letter.prepared', $letter, null, $this->forAudit($letter, $chosen, $document), $by);

            return $letter;
        }), $find), $project);
    }

    /**
     * Save a draft and prepare it again from the records as they are now. The form sends the whole
     * letter: who it is addressed to, what it cites and the three sentences a person may rewrite.
     * A sentence left as the code wrote it stays code text; one that was changed is the person's own.
     *
     * @param  array<string, mixed>  $data  Validated with rules().
     *
     * @throws ValidationException
     */
    public function update(SiteLetter $letter, array $data, User $by): SiteLetter
    {
        return $this->oneAtATime($letter, function (SiteLetter $letter) use ($data, $by) {
            if ($letter->isFinalised()) {
                throw new ImmutableRecordException(self::finalisedMessage());
            }

            return $this->prepareAgain($letter, $this->chosen($letter->type, $data), $this->rewritten($letter, $data['slots'] ?? null), $by);
        });
    }

    /**
     * Put sentences written elsewhere into a draft: the way in for the code that asks an AI model for the
     * opening, the linking sentence and the close. Each text must already have passed the Wording service's
     * intake. It is checked again every time the letter is put together, and a sentence that states a figure
     * is left out for the app's own. The facts are read from the records again, as on every save.
     *
     * @param  array<string, SlotText>  $texts  By slot: opening, linking, closing. A slot that is left out stays as it is.
     *
     * @throws ValidationException
     */
    public function useSentences(SiteLetter $letter, array $texts, User $by): SiteLetter
    {
        return $this->oneAtATime($letter, function (SiteLetter $letter) use ($texts, $by) {
            if ($letter->isFinalised()) {
                throw new ImmutableRecordException(self::finalisedMessage());
            }

            $free = (array) $letter->slots;

            foreach (array_keys(LetterLayout::FREE[$letter->type]) as $id) {
                if (($texts[$id] ?? null) instanceof SlotText) {
                    $free[$id] = $texts[$id]->toArray();
                }
            }

            return $this->prepareAgain($letter, $this->chosenOf($letter), $free, $by);
        });
    }

    /**
     * Finalise a draft. The facts are read from the records again; the letter must still be the one
     * that was prepared, and $ticks must name every figure it shows and every number typed by hand.
     * Pressing twice finalises once.
     *
     * @param  array<int, mixed>  $ticks  The request's number_ticks.
     * @param  ?string  $seen  SiteLetter::seal() of the draft as the page showed it. Null skips that check, for a caller that read the letter itself.
     *
     * @throws ValidationException
     */
    public function finalise(SiteLetter $letter, array $ticks, User $by, ?string $seen = null): SiteLetter
    {
        return $this->oneAtATime($letter, function (SiteLetter $letter) use ($ticks, $by, $seen) {
            if ($letter->isFinalised()) {
                return $letter;
            }

            // Ticks name figures, not their values. They count only for the draft they were given on.
            if ($seen !== null && ! hash_equals($letter->seal(), $seen)) {
                throw ValidationException::withMessages(['seen' => __('This draft was prepared again after you opened the page. Read it once more and tick its figures again.')]);
            }

            $chosen = $this->chosenOf($letter);

            // A receipt recorded while the letter is being finalised waits for it, or is seen by it.
            if ($chosen['bill_ids'] !== []) {
                RaBill::withoutGlobalScopes()->where('organization_id', $letter->organization_id)->whereIn('id', $chosen['bill_ids'])->lockForUpdate()->get();
            }

            $sheet = $this->facts->build($this->projectOf($letter), $letter->type, $chosen, today());
            $this->check->assertUnchanged($letter->fact_fingerprint, $sheet);

            $document = $this->render($letter->type, $sheet, $letter->language, (array) $letter->slots);
            $body = $this->layout->text($document);

            // The same figures in other words is not the letter the person read either.
            if ($body !== $letter->body_rendered) {
                throw ValidationException::withMessages(['facts' => __('The wording of this draft has changed since it was prepared. Prepare the draft again and check it.')]);
            }

            $this->assertTicked($sheet, $document, $ticks);

            $letter->update([
                'status' => SiteLetter::FINALISED,
                'finalised_by' => $by->id,
                'finalised_at' => now(),
                // The sentences as the letter has them, so nothing that was left out stays on the row.
                'slots' => $document->freeTexts(),
                'typed_numbers' => $this->typedNumbers($document),
                'number_ticks' => $document->ticksNeeded(),
            ]);

            $this->check->recordTypedNumbers($letter, $document, $by);
            $locked = $this->lockEntries($letter, $chosen['hindrance_ids']);

            AuditLog::record('site_letter.finalised', $letter, null, [
                ...$this->forAudit($letter, $chosen, $document),
                'figures_ticked' => count($document->factKeys()),
                'entries_locked' => $locked,
            ], $by);

            return $letter;
        });
    }

    /**
     * Everything the letter's page shows.
     *
     * For a draft: the letter put together from the facts kept with it, every figure marked with
     * what it is; what must be ticked; and whether the records still say the same. For a finalised
     * letter: the text as it was finalised and what was ticked. Nothing of a finalised letter is
     * worked out again.
     *
     * @return array<string, mixed>
     */
    public function review(SiteLetter $letter): array
    {
        $sheet = FactSheet::fromArray($letter->facts ?? []);
        $row = fn (Fact $fact) => [
            'key' => $fact->key,
            'label' => $this->layout->label($fact),
            'value' => $fact->display($letter->language),
            'source' => $this->layout->source($fact),
        ];

        if ($letter->isFinalised()) {
            $ticked = (array) $letter->number_ticks;

            return [
                'blocks' => null,
                'figures' => array_map($row, array_values(array_filter($sheet->all(), fn (Fact $fact) => in_array($fact->key, $ticked, true)))),
                'typed' => (array) $letter->typed_numbers,
                'changed' => false,
                'problem' => null,
                'replaced' => [],
                'free' => [],
            ];
        }

        $document = $this->render($letter->type, $sheet, $letter->language, (array) $letter->slots);
        $labels = [];

        foreach ($sheet as $fact) {
            $labels[$fact->key] = $this->layout->label($fact);
        }

        $changed = $this->layout->text($document) !== $letter->body_rendered;
        $problem = null;

        try {
            $changed = $changed || ! $this->facts->build($this->projectOf($letter), $letter->type, $this->chosenOf($letter), today())->matches($letter->fact_fingerprint);
        } catch (ValidationException $refusal) {
            $changed = true;
            $problem = collect($refusal->errors())->flatten()->first();
        }

        $free = [];

        foreach (array_keys(LetterLayout::FREE[$letter->type]) as $id) {
            $slot = $document->slot($id);
            $free[$id] = ['name' => LetterLayout::FREE_NAMES[$id], 'text' => (string) $slot?->text, 'source' => $slot?->source];
        }

        return [
            // Each part of the letter as a list of sentences, each sentence cut into plain text, facts and typed numbers.
            'blocks' => $this->layout->blocks(array_map(fn (array $slot) => [
                ...$slot,
                'parts' => array_map(fn (array $part) => [...$part, 'label' => $part['fact'] === null ? null : ($labels[$part['fact']] ?? null)], $slot['parts']),
            ], $document->parts())),
            'figures' => array_map(fn (string $key) => $row($sheet->get($key)), $document->factKeys()),
            'typed' => $this->typedNumbers($document),
            'changed' => $changed,
            'problem' => $problem,
            'replaced' => $document->replaced(),
            'free' => $free,
        ];
    }

    /**
     * What a letter was prepared from, as the form sends it: the ids it cites, who it is addressed
     * to and a completion date the person typed.
     *
     * @return array{hindrance_ids: list<int>, bill_ids: list<int>, addressed_to: ?string, proposed_completion: ?string}
     */
    public function chosenOf(SiteLetter $letter): array
    {
        return [
            'hindrance_ids' => SiteLetterHindrance::query()
                ->where('organization_id', $letter->organization_id)
                ->where('site_letter_id', $letter->id)
                ->orderBy('hindrance_id')
                ->pluck('hindrance_id')->map(fn ($id) => (int) $id)->all(),
            'bill_ids' => $letter->billIds(),
            'addressed_to' => $letter->addressed_to,
            'proposed_completion' => $letter->inputs['proposed_completion'] ?? null,
        ];
    }

    public static function finalisedMessage(): string
    {
        return __('This letter is finalised and cannot be changed. Prepare a new letter instead.');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{hindrance_ids: list<int>, bill_ids: list<int>, addressed_to: ?string, proposed_completion: ?string}
     *
     * @throws ValidationException
     */
    private function chosen(string $type, array $data): array
    {
        $ids = function (string $key) use ($data) {
            $ids = array_values(array_unique(array_map('intval', (array) ($data[$key] ?? []))));
            sort($ids);

            return $ids;
        };

        $to = trim((string) ($data['addressed_to'] ?? ''));

        if (! mb_check_encoding($to, 'UTF-8')) {
            throw ValidationException::withMessages(['addressed_to' => __('This text could not be read. Type it again.')]);
        }

        return [
            'hindrance_ids' => $type === SiteLetter::PAYMENT_FOLLOWUP ? [] : $ids('hindrance_ids'),
            'bill_ids' => $type === SiteLetter::PAYMENT_FOLLOWUP ? $ids('bill_ids') : [],
            'addressed_to' => $to !== '' ? $to : null,
            'proposed_completion' => $type === SiteLetter::EOT_REQUEST && filled($data['proposed_completion'] ?? null) ? (string) $data['proposed_completion'] : null,
        ];
    }

    /**
     * Reads the facts and puts the letter together.
     *
     * @param  array<string, mixed>  $chosen
     * @param  array<string, SlotText|array<string, mixed>>  $free  The sentences that are not code text, by slot.
     * @return array{0: FactSheet, 1: Rendered, 2: array<string, mixed>} The sheet, the letter and the columns that keep them.
     *
     * @throws ValidationException
     */
    private function prepare(Project $project, string $type, string $language, array $chosen, array $free): array
    {
        $sheet = $this->facts->build($project, $type, $chosen, today());
        $document = $this->render($type, $sheet, $language, $free);

        return [$sheet, $document, [
            'inputs' => ['bill_ids' => $chosen['bill_ids'], 'proposed_completion' => $chosen['proposed_completion']],
            'facts' => $sheet->toArray(),
            'fact_fingerprint' => $sheet->fingerprint(),
            'slots' => $document->freeTexts(),
            'body_rendered' => $this->layout->text($document),
            'typed_numbers' => $this->typedNumbers($document),
            'number_ticks' => [],
            'addressed_to' => $chosen['addressed_to'],
            'subject' => mb_substr((string) $document->slot('subject')?->text, 0, 255),
        ]];
    }

    /**
     * Reads the facts again, puts the draft together again and keeps the result. Ticks start afresh.
     *
     * @param  array<string, mixed>  $chosen
     * @param  array<string, SlotText|array<string, mixed>>  $free
     *
     * @throws ValidationException
     */
    private function prepareAgain(SiteLetter $letter, array $chosen, array $free, User $by): SiteLetter
    {
        $before = $letter->fact_fingerprint;
        [, $document, $columns] = $this->prepare($this->projectOf($letter), $letter->type, $letter->language, $chosen, $free);

        $letter->update($columns);
        $this->cite($letter, $chosen['hindrance_ids']);
        AuditLog::record('site_letter.prepared_again', $letter, ['fact_fingerprint' => $before], $this->forAudit($letter, $chosen, $document), $by);

        return $letter;
    }

    /**
     * @param  array<string, SlotText|array<string, mixed>>  $free
     *
     * @throws ValidationException
     */
    private function render(string $type, FactSheet $sheet, string $language, array $free): Rendered
    {
        try {
            return $this->renderer->render($this->layout->type($type, $sheet), $sheet, $language, $free);
        } catch (GuardViolation $violation) {
            // The code's own sentences are checked by the tests. What is left is text a person typed that cannot be read as text.
            throw ValidationException::withMessages(['slots.'.$violation->slot => __('This text could not be read. Type it again.')]);
        }
    }

    /**
     * The three sentences as they stand after the form was sent. Text equal to the code text is
     * code text again; text equal to what was kept keeps who wrote it; anything else was typed now.
     *
     * @param  mixed  $typed  The request's slots, or null when the form sent none and the sentences stay as they are.
     * @return array<string, SlotText|array<string, mixed>>
     */
    private function rewritten(SiteLetter $letter, mixed $typed): array
    {
        $kept = (array) $letter->slots;

        if (! is_array($typed)) {
            return $kept;
        }

        $tidy = fn (mixed $text) => trim(str_replace(["\r\n", "\r"], "\n", is_string($text) ? $text : ''));
        $free = [];

        foreach (array_keys(LetterLayout::FREE[$letter->type]) as $id) {
            $text = $tidy($typed[$id] ?? '');

            if ($text === '' || $text === $tidy($this->layout->freeText($letter->type, $id, $letter->language))) {
                continue;
            }

            $free[$id] = isset($kept[$id]) && $text === $tidy($kept[$id]['text'] ?? null)
                ? SlotText::fromArray((array) $kept[$id])
                : SlotText::edited($text);
        }

        return $free;
    }

    /**
     * Refuses while a figure of the letter, or a number typed by hand, is not ticked, and names
     * what is missing in the reader's own language.
     *
     * @param  array<int, mixed>  $ticks
     *
     * @throws ValidationException
     */
    private function assertTicked(FactSheet $sheet, Rendered $document, array $ticks): void
    {
        $missing = $this->check->missingTicks($document, $ticks);

        if ($missing !== []) {
            $typed = [];

            foreach ($document->typedNumbers() as $number) {
                $typed[$number->id] = $number->text.' ('.FinalCheck::typedLabel().')';
            }

            $names = array_map(fn (string $key) => $typed[$key] ?? $this->layout->label($sheet->get($key)), $missing);

            throw ValidationException::withMessages([
                'number_ticks' => __('Tick every figure in the draft before you continue. Not ticked yet: :figures', ['figures' => implode('; ', $names)]),
            ]);
        }

        // The same rule once more, as the Wording service states it.
        $this->check->assertTicked($document, $ticks);
    }

    /** Makes the letter's citations match the chosen entries. */
    private function cite(SiteLetter $letter, array $hindranceIds): void
    {
        $has = SiteLetterHindrance::query()->where('site_letter_id', $letter->id)->pluck('hindrance_id')->map(fn ($id) => (int) $id)->all();

        SiteLetterHindrance::query()->where('site_letter_id', $letter->id)->whereNotIn('hindrance_id', $hindranceIds)->delete();

        foreach (array_diff($hindranceIds, $has) as $id) {
            SiteLetterHindrance::create([
                'organization_id' => $letter->organization_id,
                'project_id' => $letter->project_id,
                'site_letter_id' => $letter->id,
                'hindrance_id' => $id,
            ]);
        }

        $letter->unsetRelation('hindrances')->unsetRelation('citations');
    }

    /**
     * Locks every cited entry that is not locked yet, so it stays as the letter read it.
     *
     * @param  list<int>  $hindranceIds
     * @return list<int> The entries locked by this letter.
     */
    private function lockEntries(SiteLetter $letter, array $hindranceIds): array
    {
        $locked = [];

        $entries = Hindrance::query()
            ->where('organization_id', $letter->organization_id)
            ->where('project_id', $letter->project_id)
            ->whereIn('id', $hindranceIds)
            ->whereNull('locked_at')
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $entry->update(['locked_at' => now()]);
            $locked[] = (int) $entry->id;
        }

        return $locked;
    }

    /** @return list<array{id: string, slot: string, text: string, context: string}> */
    private function typedNumbers(Rendered $document): array
    {
        return array_map(fn (TypedNumber $number) => $number->toArray(), $document->typedNumbers());
    }

    /**
     * What the audit log keeps of a letter: what it is and what it cites, never a figure or a sentence.
     *
     * @param  array<string, mixed>  $chosen
     * @return array<string, mixed>
     */
    private function forAudit(SiteLetter $letter, array $chosen, Rendered $document): array
    {
        $written = [];

        foreach ($document->freeTexts() as $id => $text) {
            $written[$id] = $text['source'];
        }

        return [
            'type' => $letter->type,
            'language' => $letter->language,
            'status' => $letter->status,
            'fact_fingerprint' => $letter->fact_fingerprint,
            'hindrance_ids' => $chosen['hindrance_ids'],
            'bill_ids' => $chosen['bill_ids'],
            'sentences' => $written,
            'numbers_typed' => count($document->typedNumbers()),
        ];
    }

    /**
     * Runs one change at a time on a project, on the letter as it is saved now. The same lock the
     * register takes, so an entry cannot change between reading the facts and locking it.
     *
     * @param  callable(SiteLetter): SiteLetter  $change
     */
    private function oneAtATime(SiteLetter $letter, callable $change): SiteLetter
    {
        return DB::transaction(function () use ($letter, $change) {
            $this->lockProject($letter->project_id);

            return $change($letter->refresh());
        });
    }

    private function lockProject(int $projectId): void
    {
        Project::withoutGlobalScopes()->whereKey($projectId)->lockForUpdate()->first();
    }

    private function projectOf(SiteLetter $letter): Project
    {
        return Project::withoutGlobalScopes()->where('organization_id', $letter->organization_id)->findOrFail($letter->project_id);
    }

    private function sameProject(SiteLetter $letter, Project $project): SiteLetter
    {
        if ((int) $letter->project_id !== (int) $project->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This request id was already used on another project.')]);
        }

        return $letter;
    }
}
