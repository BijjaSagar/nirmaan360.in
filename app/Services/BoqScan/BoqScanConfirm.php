<?php

namespace App\Services\BoqScan;

use App\Models\AuditLog;
use App\Models\BoqRevision;
use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentReading\ReadRefused;
use App\Services\Reports\BoqExcelService;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes a checked scan into the project's BOQ, through the Excel import and nothing else.
 *
 * The grid it builds has the template's headings exactly (section, code, description, unit, qty,
 * rate): there is no amount column, so the estimate repairs of the Excel import never run on a
 * reading (EstimateSheet::applies is false), and nothing is derived from an amount. Quantity and
 * rate are written as typed or as read; an add-on is one lump-sum line at its confirmed amount.
 *
 * Every check is worked out again from the stored rows first. Anything blocking, any tick not given
 * or any line not confirmed by a person stops it, and nothing is written.
 */
class BoqScanConfirm
{
    public const SOURCE = 'ai_scan_import';

    public function __construct(private ScanReconciler $reconciler, private BoqExcelService $excel) {}

    /**
     * @param  ?string  $valueReason  Why the BOQ's value after the import differs from the printed total, when it does.
     * @return array<string, mixed> What was written: lines created, updated and unchanged, and the history batch.
     *
     * @throws ReadRefused wrong_state; flags_open (422) with the counts that stand.
     * @throws ValidationException value_reason missing or too short; or the import refused rows (errors by row id).
     */
    public function confirm(DocumentRead $read, User $by, ?string $valueReason = null): array
    {
        return DB::transaction(function () use ($read, $by, $valueReason) {
            $locked = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->lockForUpdate()->findOrFail($read->id);

            // Asked again after it was written: the same answer, and nothing is written twice.
            if ($locked->status === DocumentRead::CONFIRMED) {
                return (array) (($locked->review ?? [])['confirmed'] ?? []);
            }

            if ($locked->status !== DocumentRead::READY || $locked->project_id === null) {
                throw ReadRefused::wrongState($locked);
            }

            $rows = BoqScanRow::withoutGlobalScopes()->where('organization_id', $locked->organization_id)->where('document_read_id', $locked->id)
                ->orderBy('sort')->orderBy('id')->lockForUpdate()->get();
            $state = $this->reconciler->run($locked, $rows);
            $summary = $state->summary;

            if (! $summary['can_confirm']) {
                throw new ReadRefused(BoqScanReview::FLAGS_OPEN, __('Every mark must be dealt with and every line confirmed before the BOQ is written.'), [
                    'blocking' => $summary['blocking'], 'unticked' => $summary['unticked'], 'unconfirmed' => $summary['unconfirmed'], 'pages_unresolved' => $summary['pages_unresolved'],
                ], 422);
            }

            $reason = null;

            if ($summary['needs_value_reason']) {
                $reason = trim((string) preg_replace('/\s+/u', ' ', (string) $valueReason));

                if (mb_strlen($reason) < (int) config('modules.boq_scan.reason_min', 10) || mb_strlen($reason) > (int) config('modules.boq_scan.reason_max', 500)) {
                    throw ValidationException::withMessages(['value_reason' => [__('The BOQ value after the import differs from the printed total. Write why in :min to :max characters.', [
                        'min' => (int) config('modules.boq_scan.reason_min', 10), 'max' => (int) config('modules.boq_scan.reason_max', 500),
                    ])]]);
                }
            }

            $project = Project::withoutGlobalScopes()->where('organization_id', $locked->organization_id)->findOrFail($locked->project_id);
            [$grid, $byGridRow] = $this->grid($state);

            $history = $reason === null
                ? __('Read from a scan and confirmed')
                : __('Read from a scan and confirmed. The BOQ value differs from the printed total. Reason given: :reason', ['reason' => $reason]);

            $lastRevision = (int) BoqRevision::withoutGlobalScopes()->where('project_id', $project->id)->max('id');
            $result = $this->excel->importGrid($project, $grid, [], $history, self::SOURCE);

            if (! $result->ok()) {
                $errors = [];

                foreach ($result->errors as $error) {
                    $key = isset($byGridRow[$error['row']]) ? 'rows.'.$byGridRow[$error['row']] : 'rows';
                    $errors[$key][] = $error['message'];
                }

                throw ValidationException::withMessages($errors);
            }

            $batch = BoqRevision::withoutGlobalScopes()->where('organization_id', $project->organization_id)->where('project_id', $project->id)
                ->where('source', self::SOURCE)->where('id', '>', $lastRevision)->orderByDesc('id')->value('batch_uuid');

            $lines = $state->live->filter(fn (BoqScanRow $row) => $row->isImported());
            $review = (array) ($locked->review ?? []);
            $counts = [
                'lines' => $lines->count(),
                'created' => $result->created,
                'updated' => $result->updated,
                'unchanged' => $result->unchanged,
                'confirmed_by_row' => $lines->where('confirmed_mode', 'row')->count(),
                'confirmed_by_page' => $lines->where('confirmed_mode', 'page')->count(),
                'cells_typed' => $state->live->sum(fn (BoqScanRow $row) => count($row->typed ?? [])),
                'rows_added_by_hand' => $state->live->filter(fn (BoqScanRow $row) => ! $row->fromReader())->count(),
                'rows_left_out' => $rows->where('kind', BoqScanRow::EXCLUDED)->count(),
                'overrides' => $lines->filter(fn (BoqScanRow $row) => $this->reconciler->overridden($row))->count(),
                'acknowledgements' => $this->acknowledgements($state, $review),
                'value_reason' => $reason !== null,
                'batch_uuid' => $batch,
            ];

            $review['confirmed'] = $counts;
            $review['value_reason'] = $reason;
            $before = ['status' => $locked->status];
            $locked->forceFill(['status' => DocumentRead::CONFIRMED, 'review' => $review])->save();

            // Counts only: never a printed value, a description or the reason's words.
            AuditLog::record('ai.read_confirmed', $locked, $before, ['status' => DocumentRead::CONFIRMED, 'kind' => $locked->kind, 'project_id' => $project->id, ...$counts], $by, (int) $locked->organization_id);

            return $counts;
        });
    }

    /**
     * The template-layout grid: a heading row the Excel import knows exactly, then one row per line in
     * the scan's order, with canonical decimal strings.
     *
     * @return array{0: array<int, array<int, string>>, 1: array<int, int>} The grid, and grid row => scan row id.
     */
    public function grid(ScanState $state): array
    {
        $grid = [1 => [1 => 'section', 2 => 'code', 3 => 'description', 4 => 'unit', 5 => 'qty', 6 => 'rate']];
        $byGridRow = [];
        $number = 1;

        foreach ($state->live as $row) {
            if (! $row->isImported()) {
                continue;
            }

            $number++;
            $byGridRow[$number] = $row->id;
            $grid[$number] = $row->kind === BoqScanRow::ADDON
                ? [1 => __('Add-ons'), 2 => (string) $row->code, 3 => (string) $row->description, 4 => 'LS', 5 => '1', 6 => Dec::money($row->amount)]
                : [1 => (string) $row->section, 2 => (string) $row->code, 3 => (string) $row->description, 4 => (string) $row->unit, 5 => Dec::qty($row->qty), 6 => Dec::money($row->rate)];
        }

        return [$grid, $byGridRow];
    }

    /** How many ticks and answers people gave on this read. */
    private function acknowledgements(ScanState $state, array $review): int
    {
        $count = $state->live->sum(fn (BoqScanRow $row) => count($row->acks ?? []));

        foreach ((array) ($review['pages'] ?? []) as $info) {
            $count += count((array) ($info['acks'] ?? []));
            $count += count(array_filter((array) ($info['extra_amount_rows'] ?? []), fn ($extra) => isset($extra['resolved'])));
        }

        foreach ((array) ($review['acks'] ?? []) as $code => $answer) {
            $count += $code === ScanFlag::TOTAL_MISMATCH ? count((array) $answer) : 1;
        }

        return $count;
    }
}
