<?php

namespace App\Services\Reports;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * BOQ import from a PDF: read it, show what was read, and write only on confirmation.
 *
 * Reading a PDF is inexact, so nothing is written on upload. The grid is kept server-side under
 * a random token for a short while, tied to the person and the project, and shown back as a
 * preview. Only an explicit confirm writes, through the same importGrid the Excel import uses,
 * so the checks and the BOQ history are the same. Anyone else's token, or a stale one, is refused.
 *
 * Confirming is a statement by a person, not a button: the language the PDF is printed in, that the
 * rows were compared with its pages, a tick against every line the reader repaired or left out, and
 * a reason when the total written in the file is not what the lines add up to. Without all of them
 * nothing is written. What was answered is kept in the audit log, and the reason in the BOQ history.
 */
final class BoqPdfImport
{
    public const TTL_MINUTES = 30;

    /** Session key holding the token of the preview the person was sent to. */
    public const SESSION = 'boq_pdf_import';

    /**
     * Flashed when a preview ends because it was confirmed or discarded: this one, or a sheet's column check
     * (BoqSheetImportController). The BOQ in Excel page the person comes back to then tells the browser to
     * forget its own copy of the PDF, which has no more use.
     */
    public const ENDED = 'boq_pdf_import_ended';

    private const CACHE = 'boq-pdf-import:';

    /** What the PDF is printed in. The person says so; the reader does not guess. */
    public const LANGUAGES = ['en', 'mr', 'hi'];

    /** Printed in Marathi or Hindi, at least this share of the letters read must be Devanagari, out of 100. */
    public const DEVANAGARI_SHARE = 30;

    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    public function __construct(private BoqPdfReader $reader, private BoqExcelService $excel) {}

    /**
     * Read the file and keep its grid for a preview.
     *
     * @return array{token: string}|array{error: string, scanned: bool}
     */
    public function stage(Project $project, User $user, string $path, string $fileName): array
    {
        $read = $this->reader->read($path);

        if (! $read->ok()) {
            return ['error' => (string) $read->error, 'scanned' => $read->scanned];
        }

        $token = Str::random(40);

        Cache::put(self::CACHE.$token, [
            'user_id' => $user->id,
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'file' => Str::limit(basename($fileName), 120, ''),
            'pages' => $read->pages,
            'grid' => $read->grid,
            'at' => now()->toIso8601String(),
        ], now()->addMinutes(self::TTL_MINUTES));

        return ['token' => $token];
    }

    /**
     * The staged upload behind a token, when it is this person's, for this project, and not expired.
     *
     * @return array{user_id: int, organization_id: int, project_id: int, file: string, pages: int, grid: array<int, array<int, ?string>>, at: string}|null
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

        return $entry;
    }

    /**
     * What the import would do: every row, the problems, the notes, and the total against the file's own.
     *
     * An estimate states a grand total, and often a total of the work before the "Add %" lines as well.
     * The grand total is held against every line; the earlier total against the lines before the add-ons,
     * as EstimateSheet does. The file matches only when every total it states agrees.
     *
     * Each note carries the key it is ticked by on the preview, see noteKey().
     *
     * @return array{file: string, pages: int, lines: int, rows: list<array<string, ?string>>, errors: list<array{row:int,field:?string,message:string}>, notes: list<array{row:int,message:string,key:string}>, total: string, file_total: ?string, matches: ?bool, estimate: bool}
     */
    public function preview(Project $project, array $entry): array
    {
        [$rows, $errors, $notes, $facts] = $this->excel->checkGrid($project, $entry['grid']);
        $total = BigDecimal::zero();

        foreach ($rows as &$row) {
            $amount = Dec::of($row['qty'])->multipliedBy(Dec::of($row['rate']));
            $row['amount'] = Dec::money($amount);
            $total = $total->plus($amount);
        }

        unset($row);

        $checks = [];

        if ($facts['grand_total'] !== null) {
            $checks[] = [$total, Dec::of($facts['grand_total'])];
        }

        if ($facts['file_total'] !== null) {
            $checks[] = [Dec::of($facts['lines_total']), Dec::of($facts['file_total'])];
        }

        $matches = null;

        foreach ($checks as [$lines, $stated]) {
            $matches = ($matches ?? true) && $lines->minus($stated)->abs()->isLessThanOrEqualTo(1);
        }

        $shown = $checks[0] ?? null;

        return [
            'file' => $entry['file'],
            'pages' => $entry['pages'],
            'lines' => max(0, count($entry['grid']) - 1),
            'rows' => $rows,
            'errors' => $errors,
            'notes' => array_map(fn (array $note) => [...$note, 'key' => self::noteKey($note)], $notes),
            'total' => Dec::money($shown === null ? $total : $shown[0]),
            'file_total' => $shown === null ? null : Dec::money($shown[1]),
            'matches' => $matches,
            'estimate' => $facts['estimate'],
        ];
    }

    /** @return array<string, string> The languages a PDF may be printed in, with their names for the screen. */
    public static function languages(): array
    {
        return ['en' => __('English'), 'mr' => __('Marathi'), 'hi' => __('Hindi')];
    }

    /** What a note on the preview is ticked by: its line and its words, so a tick cannot be carried to another note. */
    public static function noteKey(array $note): string
    {
        return sha1(($note['row'] ?? 0).'|'.($note['message'] ?? ''));
    }

    /**
     * Write the staged grid. Null when the token is not this person's for this project, or has expired.
     *
     * @param  array{printed_language?: mixed, pages_checked?: mixed, ack_notes?: mixed, total_reason?: mixed}  $answers  What the person answered on the preview.
     *
     * @throws ValidationException When an answer is missing. Nothing is written.
     */
    public function confirm(Project $project, User $user, ?string $token, array $answers = []): ?BoqImportResult
    {
        $entry = $this->staged($project, $user, $token);

        if ($entry === null) {
            return null;
        }

        $preview = $this->preview($project, $entry);

        // A file with problems has no confirm button and asks for no answers. It is refused with its problems, as before.
        if ($preview['errors'] !== []) {
            return new BoqImportResult($preview['errors']);
        }

        $answers = $this->answers($preview, $answers);
        $reason = $answers['total_reason'] === null
            ? __('Imported from a PDF file')
            : __('Imported from a PDF file. The total written in the file does not match the lines. Reason given: :reason', ['reason' => $answers['total_reason']]);

        $result = DB::transaction(function () use ($project, $user, $entry, $preview, $answers, $reason) {
            // Someone who may not see rates cannot set them either: the PDF's rates are not written for them.
            $result = $this->excel->importGrid($project, $entry['grid'], [], $reason, 'pdf_import', Money::visible($user));

            if ($result->ok()) {
                AuditLog::record('boq.pdf_import_confirmed', $project, null, [
                    'file' => $entry['file'],
                    'pages' => $entry['pages'],
                    'rows' => $result->rows(),
                    'printed_language' => $answers['printed_language'],
                    'pages_checked' => true,
                    'notes_ticked' => count($preview['notes']),
                    'total_matches' => $preview['matches'],
                    'total_reason' => $answers['total_reason'],
                ]);
            }

            return $result;
        });

        if ($result->ok()) {
            $this->forget($token);
        }

        return $result;
    }

    /**
     * The answers given on the preview, checked against what the preview shows.
     *
     * @return array{printed_language: string, total_reason: ?string}
     *
     * @throws ValidationException
     */
    private function answers(array $preview, array $answers): array
    {
        $problems = [];
        $language = is_string($answers['printed_language'] ?? null) ? $answers['printed_language'] : '';

        if (! in_array($language, self::LANGUAGES, true)) {
            $problems['printed_language'] = __('Choose the language the PDF is printed in.');
        } elseif ($language !== 'en' && ! $this->readsAsDevanagari(array_column($preview['rows'], 'description'))) {
            $problems['printed_language'] = BoqPdfReader::hasScanReader()
                ? __('This PDF\'s text does not read as Marathi or Hindi. Read it as a scan.')
                : __('This PDF\'s text does not read as Marathi or Hindi. Upload the Excel file of the estimate instead.');
        }

        if (! in_array($answers['pages_checked'] ?? null, [true, 1, '1', 'on', 'yes', 'true'], true)) {
            $problems['pages_checked'] = __('Tick that you compared these rows with the pages of the PDF.');
        }

        $ticked = array_filter(is_array($answers['ack_notes'] ?? null) ? $answers['ack_notes'] : [], 'is_string');
        $unticked = count(array_diff(array_unique(array_column($preview['notes'], 'key')), $ticked));

        if ($unticked > 0) {
            $problems['ack_notes'] = __('Every line under "Please check these" needs its own tick. Not ticked yet: :count.', ['count' => $unticked]);
        }

        $reason = null;

        if ($preview['matches'] === false) {
            $reason = is_string($answers['total_reason'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $answers['total_reason'])) : '';

            if (mb_strlen($reason) < self::REASON_MIN) {
                $problems['total_reason'] = __('The total written in the file does not match the lines. To import it as it is, write why in at least :min characters.', ['min' => self::REASON_MIN]);
            } elseif (mb_strlen($reason) > self::REASON_MAX) {
                $problems['total_reason'] = __('The reason can be at most :max characters.', ['max' => self::REASON_MAX]);
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return ['printed_language' => $language, 'total_reason' => $reason];
    }

    /**
     * Whether text read from a PDF said to be in Marathi or Hindi is in that script. An old font
     * that is not in the list of PdfTextLayer still gives itself away here: its text comes out as Latin letters.
     *
     * @param  list<?string>  $texts
     */
    private function readsAsDevanagari(array $texts): bool
    {
        $text = implode(' ', array_filter($texts, 'is_string'));
        $letters = (int) preg_match_all('/\p{L}/u', $text);
        $devanagari = (int) preg_match_all('/(?=\p{L})\p{Devanagari}/u', $text);

        return $letters > 0 && $devanagari * 100 >= $letters * self::DEVANAGARI_SHARE;
    }

    /** Drop a staged upload, when it is this person's. */
    public function discard(Project $project, User $user, ?string $token): void
    {
        if ($this->staged($project, $user, $token) !== null) {
            $this->forget($token);
        }
    }

    private function forget(?string $token): void
    {
        if ($token !== null && $token !== '') {
            Cache::forget(self::CACHE.$token);
        }
    }
}
