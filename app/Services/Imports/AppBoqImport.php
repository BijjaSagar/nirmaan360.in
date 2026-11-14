<?php

namespace App\Services\Imports;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use App\Services\Reports\BoqImportResult;
use App\Services\Reports\BoqPdfReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The field app's side of the BOQ column check: the same BoqSheetImport the website uses (stage, choose, preview,
 * confirm, discard), reached by the token the app keeps instead of the web session. Nothing here reads a file
 * differently or writes a line differently from the website.
 *
 * Two things differ, both because a phone is not a browser tab:
 * - Every file goes through the check, one laid out like our template too. The website imports a template file
 *   straight away; on the phone nothing is written before the person has seen the lines and confirmed.
 * - A confirm can be sent twice (a lost reply, a double tap). It runs under a lock on the token, and what it
 *   imported is kept for as long as the preview would have been, so a repeat answers the same result and
 *   imports nothing.
 *
 * PDFs are not staged here. A PDF's own check (BoqPdfImport) asks for a statement the column check does not: the
 * language it is printed in, that its rows were compared with its pages, a tick on every note, and a reason when its
 * total disagrees. Until the app asks for those, a PDF is read only to say why it is refused: a scan goes to the
 * scan reader, a PDF with text to the website.
 */
final class AppBoqImport
{
    /** What a sheet may be called, as on the website's upload (BoqExcelController). Its content decides how it is read. */
    public const SHEET_EXTENSIONS = ['xlsx', 'xlsm', 'xls', 'ods', 'csv', 'txt', 'tsv'];

    /** The website's limit for a sheet, in kilobytes (BoqExcelController::import). */
    public const SHEET_MAX_KB = 5120;

    /** The website's limit for a PDF, in kilobytes. */
    public const PDF_MAX_KB = BoqPdfReader::MAX_BYTES / 1024;

    /** Why an upload was refused, for the app to branch on. */
    public const UNREADABLE = 'unreadable';

    public const PDF_SCANNED = 'pdf_scanned';

    public const PDF_ON_WEBSITE = 'pdf_on_website';

    private const CACHE = 'boq-import-app:';

    public function __construct(private BoqSheetImport $sheets, private BoqPdfReader $pdfs) {}

    /** A PDF by its name or by its first bytes, so one saved without ".pdf" is still told apart from a sheet. */
    public static function isPdf(UploadedFile $file): bool
    {
        if (strtolower((string) $file->getClientOriginalExtension()) === 'pdf') {
            return true;
        }

        $handle = @fopen((string) $file->getRealPath(), 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 5);
        fclose($handle);

        return $head === '%PDF-';
    }

    /**
     * Read the file and keep it for the check. Nothing is written.
     *
     * @return array{token: string}|array{error: string, code: string, scan_reader?: bool}
     */
    public function stage(Project $project, User $user, UploadedFile $file): array
    {
        if (self::isPdf($file)) {
            return $this->refusePdf($file);
        }

        $staged = $this->sheets->stage($project, $user, (string) $file->getRealPath(), $file->getClientOriginalName());

        if (isset($staged['error'])) {
            return ['error' => $staged['error'], 'code' => self::UNREADABLE];
        }

        $this->touch($staged['token']);

        return ['token' => $staged['token']];
    }

    /**
     * The check as it stands, for the presenter: the service's preview, the token, when it expires, and which of the
     * lines shown have an item code already in the project. Null when the token is not this person's for this
     * project, or has expired.
     *
     * @return array<string, mixed>|null
     */
    public function preview(Project $project, User $user, ?string $token): ?array
    {
        $entry = $this->sheets->staged($project, $user, $token);

        return $entry === null ? null : $this->shown($project, (string) $token, $entry);
    }

    /**
     * Change how the file is read, as the website's form does, and answer the check again.
     *
     * @param  array<string, mixed>  $input  The validated fields of BoqSheetImport::choose().
     * @return array<string, mixed>|null
     */
    public function choose(Project $project, User $user, ?string $token, array $input): ?array
    {
        $entry = $this->sheets->choose($project, $user, $token, $input);

        if ($entry === null) {
            return null;
        }

        $this->touch((string) $token);

        return $this->shown($project, (string) $token, $entry);
    }

    /**
     * Import the file as last shown, once.
     *
     * - imported: written now. "result" holds what was done.
     * - repeated: this token was imported before, by this person for this project. "result" is what that import did; nothing more is written.
     * - busy: a confirm of this token is running now. Nothing is written by this one.
     * - changed: the version sent is not the one shown last. "message" says so, "preview" is the check as it is now.
     * - refused: a row or the sheet has a problem. "result" holds them, "preview" is the check as it is now. Nothing is written.
     * - gone: the token is not this person's for this project, or has expired.
     *
     * @return array{outcome: string, result?: array<string, mixed>|BoqImportResult, message?: string, preview?: array<string, mixed>|null}
     */
    public function confirm(Project $project, User $user, ?string $token, int $version): array
    {
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]{40}$/', $token) !== 1) {
            return ['outcome' => 'gone'];
        }

        // Released at the end; it lapses by itself well after any import has finished, should a worker die holding it.
        $lock = Cache::lock(self::CACHE.'confirming:'.$token, 600);

        if (! $lock->get()) {
            return ['outcome' => 'busy'];
        }

        try {
            $done = $this->done($project, $user, $token);

            if ($done !== null) {
                return ['outcome' => 'repeated', 'result' => $done];
            }

            try {
                $result = $this->sheets->confirm($project, $user, $token, $version);
            } catch (ValidationException $changed) {
                return ['outcome' => 'changed', 'message' => (string) collect($changed->errors())->flatten()->first(), 'preview' => $this->preview($project, $user, $token)];
            }

            if ($result === null) {
                return ['outcome' => 'gone'];
            }

            if (! $result->ok()) {
                return ['outcome' => 'refused', 'result' => $result, 'preview' => $this->preview($project, $user, $token)];
            }

            $summary = ['created' => $result->created, 'updated' => $result->updated, 'unchanged' => $result->unchanged, 'rows' => $result->rows(), 'notes' => $result->notes];

            Cache::put(self::CACHE.'done:'.$token, [
                'user_id' => $user->id,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                ...$summary,
            ], now()->addMinutes(BoqSheetImport::TTL_MINUTES));
            Cache::forget(self::CACHE.'expires:'.$token);

            return ['outcome' => 'imported', 'result' => $summary];
        } finally {
            $lock->release();
        }
    }

    /** Set the file aside. False when the token is not this person's for this project, or has expired. */
    public function discard(Project $project, User $user, ?string $token): bool
    {
        if ($this->sheets->staged($project, $user, $token) === null) {
            return false;
        }

        $this->sheets->discard($project, $user, $token);
        Cache::forget(self::CACHE.'expires:'.$token);

        return true;
    }

    /**
     * A PDF read only to say why it is not taken here. The reader's own words, as the website gives them.
     *
     * @return array{error: string, code: string, scan_reader?: bool}
     */
    private function refusePdf(UploadedFile $file): array
    {
        $read = $this->pdfs->read((string) $file->getRealPath());

        if ($read->scanned) {
            return ['error' => (string) $read->error, 'code' => self::PDF_SCANNED, 'scan_reader' => BoqPdfReader::hasScanReader()];
        }

        if (! $read->ok()) {
            return ['error' => (string) $read->error, 'code' => self::UNREADABLE];
        }

        return ['error' => __('A BOQ in a PDF is imported on the website for now. Upload this PDF on the project\'s BOQ import page there, or upload the Excel or CSV file here.'), 'code' => self::PDF_ON_WEBSITE];
    }

    /** @return array<string, mixed> */
    private function shown(Project $project, string $token, array $entry): array
    {
        $preview = $this->sheets->preview($project, $entry);
        $codes = array_values(array_unique(array_map(fn (array $line) => (string) $line['code'], $preview['lines'])));

        // The lines shown that a confirm would write over an item already there, by code, as BoqExcelService::write matches them.
        $existing = $codes === [] ? [] : BoqItem::where('project_id', $project->id)->whereIn('code', $codes)->pluck('code')->map(fn ($code) => (string) $code)->all();

        return [...$preview, 'token' => $token, 'expires_at' => $this->expiresAt($token, $entry), 'existing_codes' => array_values(array_unique($existing))];
    }

    /** The preview is kept TTL_MINUTES from its upload or its last change. The service keeps no time, so it is noted here. */
    private function touch(string $token): void
    {
        $until = now()->addMinutes(BoqSheetImport::TTL_MINUTES);

        Cache::put(self::CACHE.'expires:'.$token, $until->toIso8601String(), $until);
    }

    private function expiresAt(string $token, array $entry): string
    {
        $noted = Cache::get(self::CACHE.'expires:'.$token);

        return is_string($noted) ? $noted : Carbon::parse($entry['at'])->addMinutes(BoqSheetImport::TTL_MINUTES)->toIso8601String();
    }

    /** @return array<string, mixed>|null What an earlier confirm of this token imported, when it was this person's for this project. */
    private function done(Project $project, User $user, string $token): ?array
    {
        $done = Cache::get(self::CACHE.'done:'.$token);

        if (! is_array($done)
            || ($done['user_id'] ?? null) !== $user->id
            || ($done['project_id'] ?? null) !== $project->id
            || ($done['organization_id'] ?? null) !== $project->organization_id) {
            return null;
        }

        unset($done['user_id'], $done['project_id'], $done['organization_id']);

        return $done;
    }
}
