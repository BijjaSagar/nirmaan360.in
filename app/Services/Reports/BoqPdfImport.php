<?php

namespace App\Services\Reports;

use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * BOQ import from a PDF: read it, show what was read, and write only on confirmation.
 *
 * Reading a PDF is inexact, so nothing is written on upload. The grid is kept server-side under
 * a random token for a short while, tied to the person and the project, and shown back as a
 * preview. Only an explicit confirm writes, through the same importGrid the Excel import uses,
 * so the checks and the BOQ history are the same. Anyone else's token, or a stale one, is refused.
 */
final class BoqPdfImport
{
    public const TTL_MINUTES = 30;

    /** Session key holding the token of the preview the person was sent to. */
    public const SESSION = 'boq_pdf_import';

    private const CACHE = 'boq-pdf-import:';

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
     * @return array{file: string, pages: int, lines: int, rows: list<array<string, ?string>>, errors: list<array{row:int,field:?string,message:string}>, notes: list<array{row:int,message:string}>, total: string, file_total: ?string, matches: ?bool, estimate: bool}
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
            'notes' => $notes,
            'total' => Dec::money($shown === null ? $total : $shown[0]),
            'file_total' => $shown === null ? null : Dec::money($shown[1]),
            'matches' => $matches,
            'estimate' => $facts['estimate'],
        ];
    }

    /** Write the staged grid. Null when the token is not this person's for this project, or has expired. */
    public function confirm(Project $project, User $user, ?string $token): ?BoqImportResult
    {
        $entry = $this->staged($project, $user, $token);

        if ($entry === null) {
            return null;
        }

        $result = $this->excel->importGrid($project, $entry['grid'], [], __('Imported from a PDF file'), 'pdf_import');

        if ($result->ok()) {
            $this->forget($token);
        }

        return $result;
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
