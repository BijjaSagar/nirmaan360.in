<?php

namespace App\Services\BoqScan;

use App\Models\AuditLog;
use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentReading\PrintedNumber;
use App\Services\DocumentReading\ReadDesk;
use App\Services\DocumentReading\ReadRefused;
use App\Support\ClientUuidRepeat;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a person does on the review of a scanned BOQ before it is written: correct a cell, choose
 * what kind a row is, join a row to the one above, leave a row out, add a row by hand, tick a check,
 * override a line that does not multiply out with a reason, confirm rows one by one or the clean
 * rows of a page, say a page has no BOQ lines, or discard the read.
 *
 * Every step locks the read, so two people (or a page still being read) cannot write over each
 * other. Every check is worked out again from the stored rows after each step (ScanReconciler);
 * nothing the browser says about flags is trusted.
 */
class BoqScanReview
{
    /** Reads whose rows can be worked on. A read still running can be checked page by page. */
    public const WORKABLE = [DocumentRead::RUNNING, DocumentRead::PAUSED, DocumentRead::READY];

    public const NOT_VIEWED = 'page_not_viewed';

    public const FLAGS_OPEN = 'flags_open';

    public function __construct(
        private ScanReconciler $reconciler,
        private BoqScanIngest $ingest,
        private ReadDesk $desk,
    ) {}

    /**
     * Opens a scan of a project's BOQ as a draft. Its pages are then uploaded, priced and started
     * through the shared read routes. A repeated client_uuid gives back the read it made before.
     *
     * @param  array{client_uuid: string, pages_total: int, language: string, source_name?: ?string}  $data
     * @return array{0: DocumentRead, 1: bool} The read, and whether it was made just now.
     */
    public function open(Project $project, User $by, array $data, string $channel): array
    {
        $read = $this->desk->open($by, BoqReadKind::KEY, [
            'project' => $project,
            'client_uuid' => $data['client_uuid'],
            'pages_total' => (int) $data['pages_total'],
            'channel' => $channel,
            'source_name' => $data['source_name'] ?? null,
            // A hint for the reader only: the main language the BOQ is printed in.
            'options' => ['language' => $data['language']],
        ]);

        if ((int) $read->project_id !== (int) $project->id) {
            throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        return [$read, $read->wasRecentlyCreated];
    }

    /**
     * Changes some cells of a row, or its kind. Every cell given counts as typed by this person, even
     * when it holds what was read. Any change takes back the row's confirmation and its ticks.
     *
     * @param  array<string, mixed>  $data  Any of kind, section, code, description, unit, qty, rate, amount, percent.
     */
    public function update(DocumentRead $read, BoqScanRow $row, array $data, User $by): BoqScanRow
    {
        return $this->onRow($read, $row, function (DocumentRead $locked, BoqScanRow $row) use ($data, $by) {
            $values = $this->values($data);
            $typed = $row->typed ?? [];
            $changes = [];

            foreach ($values as $field => $value) {
                $changes[$field] = $value;
                $typed[] = $field;
            }

            if (array_key_exists('kind', $data) && $data['kind'] !== null) {
                $changes['kind'] = (string) $data['kind'];
                $changes['kind_set_by'] = $by->id;
            }

            if ($changes === []) {
                return $row;
            }

            $row->forceFill([
                ...$changes,
                'typed' => array_values(array_unique($typed)),
                'confirmed_by' => null,
                'confirmed_at' => null,
                'confirmed_mode' => null,
                'acks' => null,
                'overrides' => array_intersect(array_keys($changes), ['qty', 'rate', 'amount', 'kind']) === [] ? $row->overrides : null,
            ])->save();

            $this->forgetTotalAnswer($locked, $row);
            $this->ingest->derive($locked);

            return $row->refresh();
        });
    }

    /**
     * Adds a row a person typed from the page, after another row of the same page or at its top. It
     * may answer an amount the second reading found on no row of the first.
     *
     * @param  array<string, mixed>  $data  page_no, after_row_id, kind, client_uuid, extra, and any cell.
     */
    public function add(DocumentRead $read, array $data, User $by): BoqScanRow
    {
        $uuid = (string) $data['client_uuid'];
        $earlier = fn () => $this->repeat($read, $uuid);

        if (($found = $earlier()) !== null) {
            return $found;
        }

        return Idempotent::create(fn () => DB::transaction(function () use ($read, $data, $by, $uuid) {
            $locked = $this->lockWorkable($read);
            $number = (int) $data['page_no'];
            $this->assertPage($locked, $number);

            $after = null;

            if (! empty($data['after_row_id'])) {
                $after = $this->rows($locked)->where('page_no', $number)->find((int) $data['after_row_id'])
                    ?? throw ValidationException::withMessages(['after_row_id' => [__('That row is not on this page.')]]);
            }

            $values = $this->values($data);
            $row = BoqScanRow::withoutGlobalScopes()->create([
                'organization_id' => $locked->organization_id,
                'project_id' => $locked->project_id,
                'document_read_id' => $locked->id,
                'page_no' => $number,
                'part' => $after?->part ?? 'full',
                'row_no' => 0,
                'sort' => $this->sortAfter($locked, $number, $after),
                'kind' => (string) ($data['kind'] ?? BoqScanRow::ITEM),
                'kind_read' => null,
                'kind_set_by' => $by->id,
                'item_no_raw' => '',
                'unit_raw' => '',
                'states' => [],
                'scripts' => [],
                'y_top' => $after?->y_bottom,
                'y_bottom' => $after?->y_bottom,
                ...$values,
                'initial' => [],
                'typed' => array_keys($values),
                'client_uuid' => $uuid,
            ]);

            if (filled($data['extra'] ?? null)) {
                $this->resolveExtra($locked, $number, (string) $data['extra'], 'added', $by);
            }

            $this->ingest->derive($locked);

            return $row->refresh();
        }), fn () => $earlier() ?? throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]));
    }

    /**
     * Appends a row's description to the row above it on the same page and leaves the row out. For
     * a description the reader split over two rows. A row that carries figures is not joined: its
     * figures would be lost; correct it instead.
     */
    public function join(DocumentRead $read, BoqScanRow $row, User $by): BoqScanRow
    {
        return $this->onRow($read, $row, function (DocumentRead $locked, BoqScanRow $row) use ($by) {
            if ($row->kind === BoqScanRow::EXCLUDED) {
                throw ReadRefused::wrongState($locked);
            }

            foreach (['qty', 'rate', 'amount'] as $field) {
                if (filled($row->getAttribute($field.'_raw')) || $row->getAttribute($field) !== null) {
                    throw ValidationException::withMessages(['row' => [__('This row has figures of its own, so it cannot be joined to the row above. Correct it instead.')]]);
                }
            }

            $above = $this->rows($locked)->where('page_no', $row->page_no)->where('kind', '!=', BoqScanRow::EXCLUDED)
                ->where(fn ($query) => $query->where('sort', '<', $row->sort)->orWhere(fn ($same) => $same->where('sort', $row->sort)->where('id', '<', $row->id)))
                ->orderByDesc('sort')->orderByDesc('id')->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['row' => [__('There is no row above this one on its page.')]]);

            $above->forceFill([
                'description' => trim(trim((string) $above->description).' '.trim((string) $row->description)),
                'typed' => array_values(array_unique([...($above->typed ?? []), 'description'])),
                'confirmed_by' => null,
                'confirmed_at' => null,
                'confirmed_mode' => null,
                'acks' => null,
            ])->save();

            $row->forceFill([
                'kind' => BoqScanRow::EXCLUDED,
                'kind_set_by' => $by->id,
                'overrides' => ['joined_into' => $above->id],
                'confirmed_by' => null,
                'confirmed_at' => null,
                'confirmed_mode' => null,
            ])->save();

            $this->ingest->derive($locked);

            return $above->refresh();
        });
    }

    /** Leaves a row out of the import. It is kept, marked as left out and by whom. */
    public function exclude(DocumentRead $read, BoqScanRow $row, User $by): BoqScanRow
    {
        return $this->onRow($read, $row, function (DocumentRead $locked, BoqScanRow $row) use ($by) {
            $row->forceFill([
                'kind' => BoqScanRow::EXCLUDED,
                'kind_set_by' => $by->id,
                'confirmed_by' => null,
                'confirmed_at' => null,
                'confirmed_mode' => null,
            ])->save();

            $this->forgetTotalAnswer($locked, $row);
            $this->ingest->derive($locked);

            return $row->refresh();
        });
    }

    /** The record that this person checked this line against the page. Refused while anything on it is open. */
    public function confirmRow(DocumentRead $read, BoqScanRow $row, User $by): BoqScanRow
    {
        return $this->onRow($read, $row, function (DocumentRead $locked, BoqScanRow $row) use ($by) {
            $state = $this->reconciler->run($locked);

            if (! $state->canConfirm($row)) {
                throw new ReadRefused(self::FLAGS_OPEN, __('This line cannot be confirmed yet. Deal with every mark on it first.'), [
                    'flags' => array_values(array_map(fn ($flag) => $flag['code'], array_filter($state->flagsOf($row), fn ($flag) => $flag['code'] !== ScanFlag::UNCONFIRMED_ROW && ScanState::open($flag)))),
                ], 422);
            }

            if ($row->confirmed_at === null) {
                $row->forceFill(['confirmed_by' => $by->id, 'confirmed_at' => now(), 'confirmed_mode' => 'row'])->save();
            }

            return $row;
        });
    }

    /**
     * Confirms the clean rows of one page together. Only while the page's picture is on the screen
     * ($viewed), and never the first line of the page, an add-on, a typed or a flagged row.
     *
     * @return int How many rows were confirmed.
     */
    public function confirmClean(DocumentRead $read, int $number, bool $viewed, User $by): int
    {
        if (! $viewed) {
            throw new ReadRefused(self::NOT_VIEWED, __('Open the page beside the rows before confirming them together.'), [], 422);
        }

        return DB::transaction(function () use ($read, $number, $by) {
            $locked = $this->lockWorkable($read);
            $this->assertPage($locked, $number);
            $state = $this->reconciler->run($locked);

            if (! $state->pageMayConfirmClean($number)) {
                throw new ReadRefused(self::FLAGS_OPEN, __('The rows of this page must be confirmed one by one.'), [], 422);
            }

            $ids = $state->cleanRowsOf($number)->modelKeys();

            return $ids === [] ? 0 : $this->rows($locked)->whereKey($ids)->whereNull('confirmed_at')
                ->update(['confirmed_by' => $by->id, 'confirmed_at' => now(), 'confirmed_mode' => 'page', 'updated_at' => now()]);
        });
    }

    /**
     * "The page itself does not multiply out; use my quantity and rate." Only after this person typed
     * both, with a reason, and only for those two values: changing either takes it back.
     */
    public function override(DocumentRead $read, BoqScanRow $row, string $flag, string $reason, User $by): BoqScanRow
    {
        return $this->onRow($read, $row, function (DocumentRead $locked, BoqScanRow $row) use ($flag, $reason, $by) {
            if ($flag !== ScanFlag::AMOUNT_MISMATCH || $row->kind !== BoqScanRow::ITEM) {
                throw ValidationException::withMessages(['flag' => [__('This check cannot be overridden.')]]);
            }

            if (! $row->typedField('qty') || ! $row->typedField('rate') || $row->qty === null || $row->rate === null) {
                throw ValidationException::withMessages(['flag' => [__('Type the quantity and the rate as printed first.')]]);
            }

            $row->forceFill([
                'overrides' => [...($row->overrides ?? []), ScanFlag::AMOUNT_MISMATCH => [
                    'by' => $by->id, 'at' => now()->toIso8601String(), 'reason' => $this->reason($reason),
                    'qty' => (string) $row->qty, 'rate' => (string) $row->rate,
                ]],
                'confirmed_by' => null,
                'confirmed_at' => null,
                'confirmed_mode' => null,
            ])->save();

            return $row;
        });
    }

    /**
     * A person's tick on a check, for the figures it stands on now: on a row, a page or the whole
     * read. A total that does not add up needs a reason.
     *
     * @param  array{flag: string, row_id?: ?int, page_no?: ?int, reason?: ?string, extra?: ?string}  $data
     */
    public function acknowledge(DocumentRead $read, array $data, User $by): void
    {
        DB::transaction(function () use ($read, $data, $by) {
            $locked = $this->lockWorkable($read);
            $code = (string) $data['flag'];
            $state = $this->reconciler->run($locked);
            $answer = ['by' => $by->id, 'at' => now()->toIso8601String()];

            if (in_array($code, ScanFlag::NEEDS_REASON, true)) {
                $answer['reason'] = $this->reason((string) ($data['reason'] ?? ''));
            }

            if ($code === ScanFlag::TOTAL_MISMATCH || $code === ScanFlag::NO_GRAND_TOTAL) {
                $this->answerRead($locked, $state, $code, $data, $answer);

                return;
            }

            if (! empty($data['row_id'])) {
                $this->answerRow($locked, $state, $code, (int) $data['row_id'], $answer);

                return;
            }

            if (empty($data['page_no'])) {
                throw ValidationException::withMessages(['flag' => [__('This check is not standing.')]]);
            }

            $number = (int) $data['page_no'];
            $this->assertPage($locked, $number);

            if ($code === ScanFlag::SECOND_READING_EXTRA_ROW) {
                $this->resolveExtra($locked, $number, (string) ($data['extra'] ?? ''), 'not_a_line', $by);

                return;
            }

            $flag = $this->standing($code === ScanFlag::COLUMNS_SUSPECT
                ? ($state->suspect[$number] ?? false ? [ScanFlag::make(ScanFlag::COLUMNS_SUSPECT, ScanFlag::BLOCK)] : [])
                : $state->pageFlags($number), $code, ScanFlag::PAGE_ACKS);

            $this->changeReview($locked, function (array $review) use ($number, $code, $answer, $flag) {
                $review['pages'][(string) $number]['acks'][$code] = [...$answer, 'hash' => ScanFlag::hash($flag)];

                return $review;
            });
        });
    }

    /**
     * "This page has no BOQ lines." Its rows are left out, and nothing on the page stops the import
     * any more. Rows can be brought back by choosing their kind again.
     */
    public function noLines(DocumentRead $read, int $number, User $by): void
    {
        DB::transaction(function () use ($read, $number, $by) {
            $locked = $this->lockWorkable($read);
            $this->assertPage($locked, $number);

            $this->rows($locked)->where('page_no', $number)->where('kind', '!=', BoqScanRow::EXCLUDED)
                ->update(['kind' => BoqScanRow::EXCLUDED, 'kind_set_by' => $by->id, 'confirmed_by' => null, 'confirmed_at' => null, 'confirmed_mode' => null, 'updated_at' => now()]);

            $this->changeReview($locked, function (array $review) use ($number, $by) {
                $review['pages'][(string) $number]['no_lines'] = ['by' => $by->id, 'at' => now()->toIso8601String()];

                return $review;
            });

            $this->ingest->derive($locked);
        });
    }

    /**
     * Throws the read away without writing the BOQ. A read that is still running is cancelled
     * instead, which gives back its credits on hold.
     */
    public function discard(DocumentRead $read, User $by): DocumentRead
    {
        if (in_array($read->status, DocumentRead::OPEN, true)) {
            return $this->desk->cancel($read, $by);
        }

        return DB::transaction(function () use ($read, $by) {
            $locked = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->lockForUpdate()->findOrFail($read->id);

            if ($locked->status === DocumentRead::DISCARDED) {
                return $locked;
            }

            if (! in_array($locked->status, [DocumentRead::READY, DocumentRead::FAILED], true)) {
                throw ReadRefused::wrongState($locked);
            }

            $before = ['status' => $locked->status];
            $locked->forceFill(['status' => DocumentRead::DISCARDED])->save();

            AuditLog::record('ai.read_discarded', $locked, $before, ['status' => DocumentRead::DISCARDED, 'kind' => $locked->kind], $by, (int) $locked->organization_id);

            return $locked;
        });
    }

    /**
     * @param  callable(DocumentRead, BoqScanRow): BoqScanRow  $step
     */
    private function onRow(DocumentRead $read, BoqScanRow $row, callable $step): BoqScanRow
    {
        return DB::transaction(function () use ($read, $row, $step) {
            $locked = $this->lockWorkable($read);
            $fresh = $this->rows($locked)->lockForUpdate()->findOrFail($row->id);

            return $step($locked, $fresh);
        });
    }

    /**
     * Locks the read; refuses when its rows cannot be worked on any more.
     *
     * Every step that comes here is a person working through the review, so the read counts as changed
     * even when the step writes only rows: a finished read is closed once nobody has worked on it for the
     * abandon days, counted from its own last change (ReadTending). A step that fails is rolled back with it.
     */
    private function lockWorkable(DocumentRead $read): DocumentRead
    {
        $locked = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->lockForUpdate()->findOrFail($read->id);

        if (! in_array($locked->status, self::WORKABLE, true) || $locked->files_purged_at !== null) {
            throw ReadRefused::wrongState($locked);
        }

        $locked->touch();

        return $locked;
    }

    private function assertPage(DocumentRead $read, int $number): void
    {
        if ($number < 1 || ((int) $read->pages_total > 0 && $number > (int) $read->pages_total)) {
            throw ValidationException::withMessages(['page_no' => [__('This document has no page :number.', ['number' => $number])]]);
        }
    }

    /**
     * Cells a person typed, as values the BOQ can hold. A figure may be typed as it is printed
     * ("4,500.00", Devanagari digits); an empty one clears the cell.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, ?string>
     */
    private function values(array $data): array
    {
        $values = [];
        $errors = [];

        foreach (['section', 'code', 'description', 'unit'] as $field) {
            if (array_key_exists($field, $data)) {
                $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', (string) $data[$field]));
                $values[$field] = $text === '' ? null : $text;
            }
        }

        foreach (['qty', 'rate', 'amount', 'percent'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $text = trim((string) $data[$field]);

            if ($text === '') {
                $values[$field] = null;

                continue;
            }

            $parsed = PrintedNumber::parse($text, $field);

            if (! $parsed->ok) {
                $errors[$field] = [__(':field must be a number with at most :decimals decimals.', ['field' => ScanFlag::field($field), 'decimals' => PrintedNumber::DECIMALS[$field]])];

                continue;
            }

            $values[$field] = $parsed->value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $values;
    }

    private function reason(string $reason): string
    {
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $min = (int) config('modules.boq_scan.reason_min', 10);
        $max = (int) config('modules.boq_scan.reason_max', 500);

        if (mb_strlen($reason) < $min || mb_strlen($reason) > $max) {
            throw ValidationException::withMessages(['reason' => [__('Write the reason in :min to :max characters.', ['min' => $min, 'max' => $max])]]);
        }

        return $reason;
    }

    /** A sort position after a row, or at the top of the page, between it and the next row. */
    private function sortAfter(DocumentRead $read, int $number, ?BoqScanRow $after): int
    {
        $rows = $this->rows($read)->where('page_no', $number)->orderBy('sort')->orderBy('id')->get(['id', 'sort']);
        $start = $after === null ? $number * 100000 : (int) $after->sort;
        $next = $rows->first(fn ($row) => (int) $row->sort > $start);
        $end = $next === null ? $start + 200 : (int) $next->sort;

        if ($end - $start >= 2) {
            return $start + intdiv($end - $start, 2);
        }

        // No room left between the two: the page's rows are spread out again, in the same order.
        $position = $number * 100000;

        foreach ($rows as $row) {
            $position += 100;
            $this->rows($read)->whereKey($row->id)->update(['sort' => $position]);

            if ($after !== null && $row->id === $after->id) {
                $after->sort = $position;
            }
        }

        return $this->sortAfter($read, $number, $after);
    }

    /** The flag of this code standing in the list, or a refusal: a check can be answered only while it stands. */
    private function standing(array $flags, string $code, array $answerable): array
    {
        if (in_array($code, $answerable, true)) {
            foreach ($flags as $flag) {
                if ($flag['code'] === $code) {
                    return $flag;
                }
            }
        }

        throw ValidationException::withMessages(['flag' => [__('This check is not standing.')]]);
    }

    private function answerRow(DocumentRead $locked, ScanState $state, string $code, int $rowId, array $answer): void
    {
        $row = $this->rows($locked)->lockForUpdate()->find($rowId)
            ?? throw ValidationException::withMessages(['row_id' => [__('That row is not part of this document.')]]);

        $flag = $code === ScanFlag::POSSIBLE_DUPLICATE_ROW ? $this->duplicateFlag($state, $row) : $this->standing($state->flagsOf($row), $code, ScanFlag::ROW_ACKS);
        $acks = $row->acks ?? [];
        $acks[$code] = [...$answer, 'hash' => ScanFlag::hash($flag)];
        $row->forceFill(['acks' => $acks])->save();

        // "Both are real lines" answers the other row of the pair too.
        if ($code === ScanFlag::POSSIBLE_DUPLICATE_ROW) {
            foreach ((array) ($flag['params']['with'] ?? []) as $otherId) {
                $other = $this->rows($locked)->lockForUpdate()->find((int) $otherId);

                if ($other !== null && ($otherFlag = $state->rowFlag($other, ScanFlag::POSSIBLE_DUPLICATE_ROW)) !== null) {
                    $other->forceFill(['acks' => [...($other->acks ?? []), $code => [...$answer, 'hash' => ScanFlag::hash($otherFlag)]]])->save();
                }
            }
        }
    }

    private function duplicateFlag(ScanState $state, BoqScanRow $row): array
    {
        return $state->rowFlag($row, ScanFlag::POSSIBLE_DUPLICATE_ROW)
            ?? throw ValidationException::withMessages(['flag' => [__('This check is not standing.')]]);
    }

    /** @param  array<string, mixed>  $data */
    private function answerRead(DocumentRead $locked, ScanState $state, string $code, array $data, array $answer): void
    {
        $flag = null;

        foreach ($state->read as $standing) {
            if ($standing['code'] === $code && ($code !== ScanFlag::TOTAL_MISMATCH || (int) ($standing['params']['row_id'] ?? 0) === (int) ($data['row_id'] ?? 0))) {
                $flag = $standing;
            }
        }

        if ($flag === null) {
            throw ValidationException::withMessages(['flag' => [__('This check is not standing.')]]);
        }

        $this->changeReview($locked, function (array $review) use ($code, $flag, $answer) {
            if ($code === ScanFlag::TOTAL_MISMATCH) {
                $review['acks'][$code][(string) $flag['params']['row_id']] = [...$answer, 'hash' => ScanFlag::hash($flag)];
            } else {
                $review['acks'][$code] = [...$answer, 'hash' => ScanFlag::hash($flag)];
            }

            return $review;
        });
    }

    /** An amount the second reading found on no row: a person added the row by hand, or said it is not a line. */
    private function resolveExtra(DocumentRead $locked, int $number, string $id, string $how, User $by): void
    {
        $found = false;

        $this->changeReview($locked, function (array $review) use ($number, $id, $how, $by, &$found) {
            foreach ((array) ($review['pages'][(string) $number]['extra_amount_rows'] ?? []) as $index => $extra) {
                if (($extra['id'] ?? null) === $id && ! isset($extra['resolved'])) {
                    $review['pages'][(string) $number]['extra_amount_rows'][$index]['resolved'] = ['how' => $how, 'by' => $by->id, 'at' => now()->toIso8601String()];
                    $found = true;
                }
            }

            return $review;
        });

        if (! $found) {
            throw ValidationException::withMessages(['extra' => [__('This check is not standing.')]]);
        }
    }

    /** A changed or left-out row is no longer the total a reason was given for. */
    private function forgetTotalAnswer(DocumentRead $locked, BoqScanRow $row): void
    {
        if (isset(($locked->review ?? [])['acks'][ScanFlag::TOTAL_MISMATCH][(string) $row->id])) {
            $this->changeReview($locked, function (array $review) use ($row) {
                unset($review['acks'][ScanFlag::TOTAL_MISMATCH][(string) $row->id]);

                return $review;
            });
        }
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function changeReview(DocumentRead $locked, callable $change): void
    {
        $locked->forceFill(['review' => $change((array) ($locked->review ?? []))])->save();
    }

    private function repeat(DocumentRead $read, string $uuid): ?BoqScanRow
    {
        $row = ClientUuidRepeat::reachable(BoqScanRow::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('client_uuid', $uuid)->first());

        if ($row !== null && (int) $row->document_read_id !== (int) $read->id) {
            throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
        }

        return $row;
    }

    private function rows(DocumentRead $read)
    {
        return BoqScanRow::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id);
    }
}
