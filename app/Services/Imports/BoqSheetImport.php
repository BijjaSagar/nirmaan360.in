<?php

namespace App\Services\Imports;

use App\Models\AuditLog;
use App\Models\BoqImportLayout;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\BoqExcelService;
use App\Services\Reports\BoqImportResult;
use App\Support\Dec;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Any contractor's BOQ, estimate or tender sheet, imported by its own headings.
 *
 * On upload the file is read (xlsx, xls, ods or csv, decided by its content), the sheet that looks most
 * like a priced BOQ is picked, and its heading row and columns are found by BoqHeadings, or by a layout
 * the company saved before. Nothing is written then. The person sees the heading row, what each column
 * was taken to hold, the first lines as they will come in, the rows left out and why, the adjustments,
 * the rate-only lines, the amounts that are not quantity times rate, and the totals against the sheet's
 * own; they can change any column, the heading row or the sheet. Confirming writes the lines through
 * the same checks and the same BOQ history as every other import, and remembers the layout.
 *
 * The staged file is kept server-side under a random token for a short while, tied to the person and
 * the project, as the PDF import does.
 *
 * Nothing is guessed on the person's behalf: a unit typed for an earlier file in the same layout is only
 * suggested, a column of figures that is not read is named, two columns that could each be the rate (or
 * the quantity, or the amount) wait for the person to choose, and an item code this import makes up never
 * replaces a different item already in the project.
 */
final class BoqSheetImport
{
    public const TTL_MINUTES = 30;

    /** Session key holding the token of the file being checked. */
    public const SESSION = 'boq_sheet_import';

    /** Lines shown on the preview. */
    public const SHOWN_LINES = 20;

    /** Rows left out that are listed on the preview. The rest are counted. */
    public const SHOWN_OTHERS = 300;

    private const CACHE = 'boq-sheet-import:';

    public function __construct(private BoqExcelService $excel, private BoqImportLayouts $layouts) {}

    /**
     * Read the file and keep it for the column check.
     *
     * @return array{token: string}|array{error: string}
     */
    public function stage(Project $project, User $user, string $path, string $fileName): array
    {
        $read = SheetFile::read($path, BoqExcelService::MAX_ROWS);

        if ($read['error'] !== null) {
            return ['error' => $read['error']];
        }

        $sheets = $read['sheets'];
        $choice = $this->firstChoice($project->organization_id, $sheets);

        if (! $choice['layout']->found() && max(array_map(fn (array $sheet) => count($sheet['grid']), $sheets)) < 2) {
            return ['error' => __('No table was found in this file. A BOQ needs a heading row with at least a description and a quantity or rate column.')];
        }

        $token = Str::random(40);

        Cache::put(self::CACHE.$token, [
            'user_id' => $user->id,
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'file' => Str::limit(basename($fileName), 120, ''),
            'format' => $read['format'],
            'sheets' => self::pack($sheets),
            'sheet' => $choice['sheet'],
            'layout' => $choice['layout']->toArray(),
            'unit' => null,
            'suggested_unit' => $choice['unit'],
            'skip' => [],
            'saved_id' => $choice['saved_id'],
            'picked' => $choice['picked'],
            'version' => 1,
            'at' => now()->toIso8601String(),
        ], now()->addMinutes(self::TTL_MINUTES));

        return ['token' => $token];
    }

    /**
     * The staged file behind a token, when it is this person's, for this project, and not expired.
     *
     * @return array<string, mixed>|null
     */
    public function staged(Project $project, User $user, ?string $token): ?array
    {
        if ($token === null || preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return null;
        }

        $entry = Cache::get(self::CACHE.$token);

        if (! is_array($entry)
            || ($entry['user_id'] ?? null) !== $user->id
            || ($entry['project_id'] ?? null) !== $project->id
            || ($entry['organization_id'] ?? null) !== $project->organization_id) {
            return null;
        }

        $entry['sheets'] = self::unpack($entry['sheets'] ?? null);

        return $entry['sheets'] === null ? null : $entry;
    }

    /**
     * Change how the file is read: the sheet, the heading row (picked or typed), what a column holds, the unit
     * for lines with none, a row to leave out or put back. A new sheet or heading row is detected afresh, and
     * the rows left out start again; column choices apply to the heading as it stands.
     *
     * @param  array{sheet?: mixed, row?: mixed, row_typed?: mixed, span?: mixed, fields?: mixed, unit?: mixed, skip?: mixed, keep?: mixed}  $input
     * @return array<string, mixed>|null The entry as changed, or null when the token is not this person's.
     */
    public function choose(Project $project, User $user, ?string $token, array $input): ?array
    {
        $entry = $this->staged($project, $user, $token);

        if ($entry === null) {
            return null;
        }

        $detector = new BoqHeadings;
        $sheetIndex = filter_var($input['sheet'] ?? null, FILTER_VALIDATE_INT);
        $sheetIndex = is_int($sheetIndex) && isset($entry['sheets'][$sheetIndex]) ? $sheetIndex : $entry['sheet'];
        $sheet = $entry['sheets'][$sheetIndex];
        $layout = SheetLayout::fromArray($entry['layout']);
        $row = filter_var($input['row_typed'] ?? null, FILTER_VALIDATE_INT);
        $row = is_int($row) ? $row : filter_var($input['row'] ?? null, FILTER_VALIDATE_INT);
        $span = filter_var($input['span'] ?? null, FILTER_VALIDATE_INT);
        $span = is_int($span) ? max(1, min(BoqHeadings::MAX_SPAN, $span)) : $layout->span;

        $skip = $entry['skip'] ?? [];

        if ($sheetIndex !== $entry['sheet']) {
            $layout = $detector->detect($sheet['grid'], $sheet['merges']);
            $entry['picked'] = 'chosen';
            $entry['saved_id'] = null;
            $skip = [];
        } elseif (is_int($row) && isset($sheet['grid'][$row]) && ($row !== $layout->row || $span !== $layout->span)) {
            $layout = $detector->at($sheet['grid'], $row, $span, $sheet['merges']);
            $entry['saved_id'] = null;
            $skip = [];
        } elseif (is_array($input['fields'] ?? null)) {
            $fields = [];

            foreach ($input['fields'] as $column => $field) {
                $column = filter_var($column, FILTER_VALIDATE_INT);

                if (is_int($column) && $column >= 1 && $column <= SheetFile::MAX_COLUMNS) {
                    $fields[$column] = is_string($field) && in_array($field, SheetLayout::FIELDS, true) ? $field : null;
                }
            }

            $layout = $layout->withFields($fields);
        }

        foreach (['skip' => true, 'keep' => false] as $key => $leaveOut) {
            $number = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT);

            if (is_int($number) && isset($sheet['grid'][$number]) && $number > $layout->lastRow()) {
                $skip = $leaveOut ? [...array_diff($skip, [$number]), $number] : array_values(array_diff($skip, [$number]));
            }
        }

        $entry['skip'] = array_values(array_slice($skip, 0, 2000));

        if (array_key_exists('unit', $input)) {
            $unit = is_string($input['unit']) ? trim($input['unit']) : '';
            $entry['unit'] = $unit === '' ? null : Str::limit($unit, 16, '');
        }

        $entry['sheet'] = $sheetIndex;
        $entry['layout'] = $layout->toArray();
        $entry['version']++;

        Cache::put(self::CACHE.$token, [...$entry, 'sheets' => self::pack($entry['sheets'])], now()->addMinutes(self::TTL_MINUTES));

        return $entry;
    }

    /**
     * Everything the column check shows. Nothing is written.
     *
     * @return array<string, mixed>
     */
    public function preview(Project $project, array $entry): array
    {
        $sheet = $entry['sheets'][$entry['sheet']];
        $layout = SheetLayout::fromArray($entry['layout']);
        $detector = new BoqHeadings;
        $reading = $this->reading($project, $entry);
        $saved = $entry['saved_id'] === null ? null : BoqImportLayout::withoutGlobalScopes()->where('organization_id', $project->organization_id)->find($entry['saved_id']);
        $lines = array_filter($reading['kinds'], fn (array $kind) => in_array($kind['kind'], BoqSheetRows::LINE_KINDS, true));
        $others = array_filter($reading['kinds'], fn (array $kind) => ! in_array($kind['kind'], BoqSheetRows::LINE_KINDS, true));
        $lineRows = array_values(array_filter(array_keys($lines), fn (int $row) => isset($reading['lines'][$row])));

        return [
            'file' => $entry['file'],
            'format' => $entry['format'],
            'version' => $entry['version'],
            'sheets' => array_map(fn (array $sheet, int $index) => [
                'index' => $index,
                'name' => $sheet['name'],
                'rows' => count($sheet['grid']),
                'priced' => $detector->pricedRows($sheet['grid'], $index === $entry['sheet'] ? $layout : $detector->detect($sheet['grid'], $sheet['merges'])),
                'hidden' => $sheet['hidden'],
            ], $entry['sheets'], array_keys($entry['sheets'])),
            'sheet' => $entry['sheet'],
            'picked' => $entry['picked'],
            'found' => $layout->found(),
            'template' => $layout->found() && $layout->isTemplate(),
            'heading_row' => $layout->row,
            'span' => $layout->span,
            'candidates' => $detector->candidateRows($sheet['grid']),
            'columns' => $this->columns($sheet['grid'], $layout),
            'unit' => $entry['unit'],
            'suggested_unit' => $entry['suggested_unit'] ?? null,
            'heading_unit' => $layout->headingUnit(),
            'needs_unit' => $reading['needs_unit'],
            'saved' => $saved === null ? null : ['id' => $saved->id, 'at' => $saved->last_used_at ?? $saved->created_at, 'uses' => $saved->uses, 'file' => $saved->file_name],
            'lines' => array_slice(array_map(fn (int $row) => ['row' => $row, ...$reading['lines'][$row]], $lineRows), 0, self::SHOWN_LINES),
            'line_count' => count($lines),
            'rate_only' => array_values(array_map(fn (int $row) => ['row' => $row, ...$reading['lines'][$row]], array_keys(array_filter($lines, fn (array $kind) => $kind['kind'] === 'rate_only')))),
            'others' => array_slice(array_map(fn (int $row, array $kind) => ['row' => $row, ...$kind], array_keys($others), $others), 0, self::SHOWN_OTHERS),
            'other_count' => count($others),
            'left_out' => count(array_filter($others, fn (array $kind) => in_array($kind['kind'], BoqSheetRows::LEFT_OUT_KINDS, true))),
            'skip' => $entry['skip'] ?? [],
            'renumbered' => $reading['renumbered'],
            'updates' => $reading['updates'],
            'counts' => array_count_values(array_column($reading['kinds'], 'kind')),
            'adjustments' => $reading['adjustments'],
            'totals' => $reading['totals'],
            'mismatches' => $reading['mismatches'],
            'lines_total' => $reading['lines_total'],
            'adjustments_total' => $reading['adjustments_total'],
            'with_adjustments' => $reading['with_adjustments'],
            'file_subtotal' => $reading['file_subtotal'],
            'file_total' => $reading['file_total'],
            'subtotal_matches' => $reading['file_subtotal'] === null ? null : $this->near($reading['lines_total'], $reading['file_subtotal']),
            'total_matches' => $reading['file_total'] === null ? null : $this->near($reading['with_adjustments'], $reading['file_total']) || $this->near($reading['lines_total'], $reading['file_total']),
            'rows' => count($reading['rows']),
            'errors' => $reading['errors'],
            'notes' => $reading['notes'],
        ];
    }

    /**
     * Import the staged file as it was last shown. Null when the token is not this person's for this project, or has expired.
     *
     * @throws ValidationException When the columns were changed since the page was shown. Nothing is written.
     */
    public function confirm(Project $project, User $user, ?string $token, mixed $version): ?BoqImportResult
    {
        $entry = $this->staged($project, $user, $token);

        if ($entry === null) {
            return null;
        }

        if ((string) $version !== (string) $entry['version']) {
            throw ValidationException::withMessages(['version' => __('How the file is read was changed in another window. Check the lines again and confirm.')]);
        }

        $layout = SheetLayout::fromArray($entry['layout']);
        $sheet = $entry['sheets'][$entry['sheet']];

        $result = DB::transaction(function () use ($project, $user, $entry, $layout, $sheet) {
            $summary = null;
            $result = $this->excel->write($project, function (Project $locked) use ($entry, &$summary) {
                $reading = $this->reading($locked, $entry);
                $summary = $reading;

                return [$reading['rows'], $reading['errors'], $reading['notes']];
            }, __('Imported from :file after checking its columns', ['file' => $entry['file']]), 'excel_import');

            if (! $result->ok()) {
                return $result;
            }

            $saved = $layout->isTemplate() ? null : $this->layouts->save($project->organization_id, $user, $layout, $sheet['name'], $entry['unit'], $entry['file']);

            AuditLog::record('boq.sheet_import_confirmed', $project, null, [
                'file' => $entry['file'],
                'format' => $entry['format'],
                'sheet' => $sheet['name'],
                'heading_row' => $layout->row,
                'rows' => $result->rows(),
                'rate_only' => count($summary['rate_only'] ?? []),
                'adjustments' => count($summary['adjustments'] ?? []),
                'mismatches' => count($summary['mismatches'] ?? []),
                'renumbered' => count($summary['renumbered'] ?? []),
                'left_out' => count($entry['skip'] ?? []),
                'layout_id' => $saved?->id,
            ]);

            return $result;
        });

        if ($result->ok()) {
            $this->forget($token);
        }

        return $result;
    }

    /** Drop a staged file, when it is this person's. */
    public function discard(Project $project, User $user, ?string $token): void
    {
        if ($this->staged($project, $user, $token) !== null) {
            $this->forget($token);
        }
    }

    /**
     * Any BOQ sheet read straight into rows in the template's columns, for an import with no column check:
     * the tender BOQ. The sheet and its columns are chosen as the preview would choose them, a layout the
     * company saved first. Rows are keyed by their row number on the sheet. With no page to ask on, anything
     * the column check would ask the person about refuses the file: a row that cannot be read, a unit that
     * only an earlier file gave, figures in a column that is not read.
     *
     * @return array{rows: array<int, array{section: ?string, code: string, description: string, unit: string, qty: string, rate: string}>, error: string|list<string>|null}
     */
    public function rowsFor(int $organizationId, string $path, int $maxRows): array
    {
        $read = SheetFile::read($path, $maxRows);

        if ($read['error'] !== null) {
            return ['rows' => [], 'error' => $read['error']];
        }

        $choice = $this->firstChoice($organizationId, $read['sheets']);
        $sheet = $read['sheets'][$choice['sheet']];
        $layout = $choice['layout'];

        if (! $layout->found()) {
            return ['rows' => [], 'error' => __('No heading row with a description and a quantity or rate column was found. Use the template\'s columns: :columns', ['columns' => 'section, code, description, unit, qty, rate'])];
        }

        if ($sheet['truncated']) {
            return ['rows' => [], 'error' => __('The sheet has more than :max rows. Split it into smaller files.', ['max' => $maxRows])];
        }

        if ($sheet['wide']) {
            return ['rows' => [], 'error' => __('This sheet has values past column :column, which are not read. Move them into the first columns and upload the file again.', ['column' => Coordinate::stringFromColumnIndex(SheetFile::MAX_COLUMNS)])];
        }

        foreach ($this->unreadFigures($sheet['grid'], $layout) as $unread) {
            // A second amount, as one with taxes or one in lakhs, changes no line: the quantity and rate are imported.
            if ($unread['field'] === 'amount') {
                continue;
            }

            if ($unread['field'] !== null) {
                return ['rows' => [], 'error' => __('Column :column is also headed ":heading" and holds figures, like column :taken. Only one column can be read for it: remove or rename the others and upload the file again.', $unread)];
            }

            if (! isset($layout->columns()['rate'])) {
                return ['rows' => [], 'error' => __('No rate column was recognised, but column :column (:heading) holds figures. Head it Rate, or remove it, and upload the file again.', $unread)];
            }
        }

        if ($layout->isTemplate()) {
            if (! isset($layout->columns()['unit'])) {
                return ['rows' => [], 'error' => __('This sheet has no unit column. Add one, with the unit of each line.')];
            }

            [$grid, $columns, $header] = [$sheet['grid'], $layout->columns(), $layout->lastRow()];
        } else {
            // A unit typed for an earlier file in this layout is not used here: nobody would see it applied.
            $reading = (new BoqSheetRows)->classify($sheet['grid'], $layout, null, ['missing' => $sheet['missing'], 'merges' => $sheet['merges']]);

            if ($reading['errors'] !== []) {
                return ['rows' => [], 'error' => array_map(fn (array $error) => __('Row :row', ['row' => $error['row']]).': '.$error['message'], array_slice($reading['errors'], 0, 10))];
            }

            if (! isset($layout->columns()['unit']) && $layout->headingUnit() === null && in_array('', array_column($reading['lines'], 'unit'), true)) {
                return ['rows' => [], 'error' => __('This sheet has no unit column. Add one, with the unit of each line.')];
            }

            [$grid, $columns, $header] = [$reading['grid'], $reading['columns'], $reading['header_row']];
        }

        $rows = [];

        foreach ($grid as $number => $cells) {
            if ($number <= $header) {
                continue;
            }

            $row = [];

            foreach (['section', 'code', 'description', 'unit', 'qty', 'rate'] as $field) {
                $value = isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null;
                $row[$field] = in_array($field, ['qty', 'rate'], true) ? (CellValues::number($value) ?? CellValues::text($value)) : CellValues::text($value);
            }

            if (implode('', $row) !== '') {
                $rows[$number] = $row;
            }
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * The sheet read with the layout as it stands: what each row is, the BOQ rows, and the problems.
     *
     * @return array<string, mixed>
     */
    private function reading(Project $project, array $entry): array
    {
        $sheet = $entry['sheets'][$entry['sheet']];
        $layout = SheetLayout::fromArray($entry['layout']);
        $empty = [
            'kinds' => [], 'lines' => [], 'adjustments' => [], 'totals' => [], 'mismatches' => [], 'notes' => [], 'rows' => [], 'rate_only' => [],
            'lines_total' => '0.00', 'adjustments_total' => '0.00', 'with_adjustments' => '0.00', 'file_subtotal' => null, 'file_total' => null,
            'needs_unit' => false, 'renumbered' => [], 'updates' => [],
        ];

        if (! $layout->found()) {
            return [...$empty, 'errors' => [['row' => 0, 'field' => null, 'message' => __('Choose the row that holds the headings, then the column with the description and the column with the quantity or the rate.')]]];
        }

        if ($sheet['truncated']) {
            return [...$empty, 'errors' => [['row' => 0, 'field' => null, 'message' => __('The sheet has more than :max rows. Split it into smaller files.', ['max' => BoqExcelService::MAX_ROWS])]]];
        }

        // Every sheet on the column check is read by its own headings, one laid out like the template too:
        // its section, part and total rows are what the person came here for.
        $read = (new BoqSheetRows)->classify($sheet['grid'], $layout, $entry['unit'], ['missing' => $sheet['missing'], 'merges' => $sheet['merges'], 'skip' => $entry['skip'] ?? []]);
        [$renumbered, $updates] = $this->matchExisting($project, $read);
        [$rows, $errors] = $this->excel->checkRows($project, $read['grid'], $read['header_row'], $read['columns']);
        // A row that cannot be read is the problem to show; with every row refused, "the file has no rows" is not.
        $errors = $read['errors'] === [] ? $errors : [...$read['errors'], ...array_values(array_filter($errors, fn (array $error) => $error['row'] > 0 && ! in_array($error['row'], array_column($read['errors'], 'row'), true)))];

        $needsUnit = ! isset($layout->columns()['unit']) && $layout->headingUnit() === null && in_array('', array_column($read['lines'], 'unit'), true);

        if ($needsUnit) {
            $errors = array_values(array_filter($errors, fn (array $error) => $error['field'] !== 'unit'));
            array_unshift($errors, ['row' => 0, 'field' => 'unit', 'message' => __('This sheet has no unit column. Type the unit for its lines above.')]);
        }

        $notes = $read['notes'];

        foreach ($this->unreadFigures($sheet['grid'], $layout) as $unread) {
            if ($unread['field'] === 'amount') {
                // Only checked against the quantity times the rate: said, never a reason to stop.
                $notes[] = ['row' => 0, 'message' => __('Column :column is also headed ":heading" and holds figures. Column :taken is read as the amount; to read :column instead, choose it under Read as.', $unread)];
            } elseif ($unread['field'] !== null) {
                $errors[] = ['row' => 0, 'field' => $unread['field'], 'message' => __('Column :column is also headed ":heading" and holds figures, like column :taken. Only one of them can be read: choose it, and set the others to Leave out. For a rate split into parts, such as supply and installation, read the total amount column as the amount and leave the rate columns out.', $unread)];
            }
        }
        $unread = array_filter($this->unreadFigures($sheet['grid'], $layout), fn (array $column) => $column['field'] === null);

        if ($unread !== []) {
            $named = array_map(fn (array $column) => $column['heading'] === '' ? $column['column'] : $column['column'].' ('.Str::limit($column['heading'], 30).')', array_slice($unread, 0, 6));
            $notes[] = ['row' => 0, 'message' => __('Columns :columns hold figures but are not read. If one of them is the quantity, rate or amount, choose it under Read as.', ['columns' => implode(', ', $named).(count($unread) > 6 ? ', …' : '')])];
        }

        if ($sheet['wide'] ?? false) {
            $notes[] = ['row' => 0, 'message' => __('This sheet has values past column :column. Only columns A to :column are read.', ['column' => Coordinate::stringFromColumnIndex(SheetFile::MAX_COLUMNS)])];
        }

        return [
            ...$read,
            'rows' => $rows,
            'errors' => $errors,
            'notes' => $notes,
            'needs_unit' => $needsUnit,
            'renumbered' => $renumbered,
            'updates' => $updates,
            'rate_only' => array_keys(array_filter($read['lines'], fn (array $line) => $line['kind'] === 'rate_only')),
        ];
    }

    /**
     * Lines whose item code is already in the project for a different item. A code the file gives updates that
     * item, as the template always has, and is listed. A code made up here, from a serial number or the order
     * of the rows, never does: the line gets the next free code instead (1 becomes 1-2), or the code it got the
     * last time the same item came in, and is listed.
     *
     * @return array{0: list<array{row: int, from: string, to: string, existing: string}>, 1: list<array{row: int, code: string, now: string, after: string}>}
     */
    private function matchExisting(Project $project, array &$read): array
    {
        // Every scope is lifted to read the project's lines inside the import's lock, so deleted lines are left out here.
        $existing = BoqItem::withoutGlobalScopes()->where('organization_id', $project->organization_id)->where('project_id', $project->id)
            ->whereNull('deleted_at')->orderBy('id')->get(['id', 'code', 'description'])->unique('code')->keyBy('code');

        if ($existing->isEmpty()) {
            return [[], []];
        }

        $same = fn (BoqItem $item, array $line) => self::plain((string) $item->description) === self::plain((string) $line['description']);
        $used = array_flip(array_map('strval', array_column($read['lines'], 'code')));
        $codeColumn = $read['columns']['code'];
        $renumbered = [];
        $updates = [];

        foreach ($read['lines'] as $row => $line) {
            $item = $existing->get($line['code']);

            if ($item === null || $same($item, $line)) {
                continue;
            }

            if (! $line['generated']) {
                $updates[] = ['row' => $row, 'code' => (string) $line['code'], 'now' => (string) $item->description, 'after' => (string) $line['description']];

                continue;
            }

            for ($suffix = 2; ; $suffix++) {
                $code = mb_substr((string) $line['code'], 0, 28).'-'.$suffix;
                $other = $existing->get($code);

                if (! isset($used[$code]) && ($other === null || $same($other, $line))) {
                    break;
                }
            }

            $used[$code] = true;
            $renumbered[] = ['row' => $row, 'from' => (string) $line['code'], 'to' => $code, 'existing' => (string) $item->description];
            $read['lines'][$row]['code'] = $code;
            $read['grid'][$row][$codeColumn] = $code;
        }

        return [$renumbered, $updates];
    }

    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
    }

    /**
     * Columns not read that hold figures under the heading, each with its letter and heading. "field" is the
     * quantity, rate or amount when the heading names one that another column is already read as: two
     * columns that could each be it, as a rate split into supply and installation under one merged heading,
     * or a second table beside the first. Two quantity or rate columns stop the import until the person
     * chooses; a second amount only changes the check of quantity times rate, so it is named and nothing
     * more. A column the person or a saved layout left out has no "field".
     *
     * @return list<array{column: string, heading: string, field: ?string, taken: string}>
     */
    private function unreadFigures(array $grid, SheetLayout $layout): array
    {
        if (! $layout->found()) {
            return [];
        }

        $columns = $layout->columns();
        $counts = [];

        foreach ($grid as $number => $cells) {
            if ($number <= $layout->lastRow()) {
                continue;
            }

            foreach ($cells as $column => $value) {
                if (($layout->fields[$column] ?? null) !== null || CellValues::text($value) === '') {
                    continue;
                }

                $counts[$column]['filled'] = ($counts[$column]['filled'] ?? 0) + 1;
                $counts[$column]['numbers'] = ($counts[$column]['numbers'] ?? 0) + (CellValues::isNumber($value) ? 1 : 0);
            }
        }

        ksort($counts);
        $unread = [];

        foreach ($counts as $column => $count) {
            if ($count['numbers'] < 0.6 * $count['filled']) {
                continue;
            }

            $heading = $layout->headings[$column] ?? '';
            $scores = BoqHeadings::scores($heading);
            arsort($scores);
            $field = array_key_first($scores);
            // A column the person left out, on this page or in a layout the company saved, is decided: named, not asked about again.
            $field = in_array($field, ['qty', 'rate', 'amount'], true) && isset($columns[$field]) && ! in_array($layout->how[$column] ?? null, ['chosen', 'saved'], true) ? $field : null;
            $unread[] = [
                'column' => Coordinate::stringFromColumnIndex($column),
                'heading' => $heading,
                'field' => $field,
                'taken' => $field === null ? '' : Coordinate::stringFromColumnIndex($columns[$field]),
            ];
        }

        return $unread;
    }

    /** @return list<array{column: int, letter: string, heading: string, field: ?string, how: ?string, samples: list<string>}> */
    private function columns(array $grid, SheetLayout $layout): array
    {
        $used = $layout->headings;

        foreach ($grid as $number => $cells) {
            if ($number > $layout->lastRow()) {
                $used += array_fill_keys(array_keys($cells), '');
            }
        }

        ksort($used);
        $columns = [];

        foreach (array_keys($used) as $column) {
            $samples = [];

            foreach ($grid as $number => $cells) {
                if ($number > $layout->lastRow() && ($text = CellValues::text($cells[$column] ?? null)) !== '') {
                    $samples[] = Str::limit($text, 40);

                    if (count($samples) >= 3) {
                        break;
                    }
                }
            }

            $columns[] = [
                'column' => $column,
                'letter' => Coordinate::stringFromColumnIndex($column),
                'heading' => $layout->headings[$column] ?? '',
                'field' => $layout->fields[$column] ?? null,
                'how' => $layout->how[$column] ?? null,
                'unit' => $layout->units[$column] ?? null,
                'samples' => $samples,
            ];
        }

        return $columns;
    }

    /**
     * The sheet to start with: the one a saved layout matches, else the one with the most priced rows.
     *
     * @return array{sheet: int, layout: SheetLayout, unit: ?string, saved_id: ?int, picked: string} "unit" is the unit the saved layout was last used with, a suggestion only.
     */
    private function firstChoice(int $organizationId, array $sheets): array
    {
        $saved = $this->layouts->find($organizationId, $sheets);
        $detector = new BoqHeadings;

        if ($saved !== null) {
            $sheet = $sheets[$saved['sheet']];
            $layout = $detector->at($sheet['grid'], $saved['row'], $saved['span'], $sheet['merges']);
            $fields = [];

            foreach (array_keys($layout->headings + (array) $saved['layout']->fields) as $column) {
                $fields[(int) $column] = $saved['layout']->fields[$column] ?? null;
            }

            // The unit typed for the earlier file is only suggested: this file's lines may be in another unit.
            return ['sheet' => $saved['sheet'], 'layout' => $layout->withFields($fields, 'saved'), 'unit' => $saved['layout']->unit, 'saved_id' => $saved['layout']->id, 'picked' => 'saved'];
        }

        $best = null;

        foreach ($sheets as $index => $sheet) {
            $layout = $detector->detect($sheet['grid'], $sheet['merges']);
            // Priced rows first; a measurement sheet with quantities only comes after any sheet with rates.
            $priced = $detector->pricedRows($sheet['grid'], $layout) * 10000 + $detector->pricedRows($sheet['grid'], $layout, 'qty') * 2
                + ($layout->found() ? 1 : 0) - ($sheet['hidden'] ? 5000 : 0);

            if ($best === null || $priced > $best['priced']) {
                $best = ['sheet' => $index, 'layout' => $layout, 'priced' => $priced];
            }
        }

        return ['sheet' => $best['sheet'], 'layout' => $best['layout'], 'unit' => null, 'saved_id' => null, 'picked' => count($sheets) > 1 ? 'priced' : 'only'];
    }

    private function near(string $first, string $second): bool
    {
        $allowed = Dec::of($second)->abs()->multipliedBy('0.0005');

        return Dec::of($first)->minus(Dec::of($second))->abs()->isLessThanOrEqualTo($allowed->isLessThan(1) ? 1 : $allowed);
    }

    /** The sheets as kept in the cache: compressed, since a full sheet is megabytes as plain arrays, and as text for any cache store. */
    private static function pack(array $sheets): string
    {
        return base64_encode((string) gzcompress(serialize($sheets), 6));
    }

    /** @return list<array<string, mixed>>|null */
    private static function unpack(mixed $packed): ?array
    {
        $raw = is_string($packed) ? base64_decode($packed, true) : false;
        $data = $raw === false ? false : @gzuncompress($raw);
        $sheets = $data === false ? false : unserialize($data, ['allowed_classes' => false]);

        return is_array($sheets) && $sheets !== [] ? $sheets : null;
    }

    private function forget(?string $token): void
    {
        if ($token !== null && $token !== '') {
            Cache::forget(self::CACHE.$token);
        }
    }
}
