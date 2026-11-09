<?php

namespace App\Services\Notify;

use App\Jobs\Notify\BuildCompanyExport;
use App\Models\AuditLog;
use App\Models\CompanyDataExport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as Files;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/** Builds a zip of everything a company holds. It runs on the queue because it can take minutes. */
class CompanyExportService
{
    private const KEEP_DAYS = 7;

    /** A run older than this is taken to have died, so the owner can start again. */
    private const STALE_AFTER_HOURS = 2;

    /** The export's own bookkeeping, which would otherwise pack earlier zips into each new one. */
    private const NOT_EXPORTED = ['company_data_exports', 'company_deletion_requests'];

    public function __construct(private TenantData $data, private Notifier $notifier) {}

    public function request(Organization $organization, User $by): CompanyDataExport
    {
        if ($this->inProgress($organization->id)) {
            throw ValidationException::withMessages(['export' => __('An export is already being prepared.')]);
        }

        $export = CompanyDataExport::create(['organization_id' => $organization->id, 'requested_by' => $by->id, 'status' => 'queued']);
        AuditLog::record('company.export_requested', $export);
        BuildCompanyExport::dispatch($export->id);

        return $export;
    }

    public function inProgress(int $organizationId): bool
    {
        return CompanyDataExport::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('status', ['queued', 'running'])
            ->where('created_at', '>', now()->subHours(self::STALE_AFTER_HOURS))
            ->exists();
    }

    public function build(int $exportId): void
    {
        // The queue can hand a long job to a second worker. Only the one that claims it runs.
        $claimed = CompanyDataExport::withoutGlobalScopes()->whereKey($exportId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now()]);

        if (! $claimed) {
            return;
        }

        $export = CompanyDataExport::withoutGlobalScopes()->findOrFail($exportId);
        $workspace = storage_path('app/export-work/'.Str::uuid());

        try {
            Files::ensureDirectoryExists($workspace);
            $zipPath = $this->writeZip(Organization::findOrFail($export->organization_id), $workspace);
            $name = 'org-'.$export->organization_id.'/data-exports/'.Str::uuid().'.zip';

            if (! Storage::putFileAs(dirname($name), new File($zipPath), basename($name))) {
                throw new RuntimeException('The finished export could not be stored.');
            }

            $export->update([
                'status' => 'ready',
                'path' => $name,
                'size_bytes' => filesize($zipPath),
                'finished_at' => now(),
                'expires_at' => now()->addDays(self::KEEP_DAYS),
            ]);

            $this->tellRequester($export);
        } catch (Throwable $exception) {
            Log::error('Company export failed', ['export_id' => $export->id, 'error' => $exception->getMessage()]);
            $export->update(['status' => 'failed', 'error' => Str::limit($exception->getMessage(), 240), 'finished_at' => now()]);
        } finally {
            Files::deleteDirectory($workspace);
        }
    }

    public function download(CompanyDataExport $export, User $by): StreamedResponse
    {
        abort_unless($export->isDownloadable() && Storage::exists($export->path), 404);

        AuditLog::record('company.export_downloaded', $export, null, ['user_id' => $by->id]);

        return Storage::download($export->path, 'company-data-'.$export->created_at->format('Y-m-d').'.zip');
    }

    /** Removes zips past their date. They hold every person's details, so they should not linger. */
    public function removeExpired(): int
    {
        CompanyDataExport::withoutGlobalScopes()->whereIn('status', ['queued', 'running'])
            ->where('created_at', '<=', now()->subHours(self::STALE_AFTER_HOURS))
            ->update(['status' => 'failed', 'error' => 'The export stopped before it finished.', 'finished_at' => now()]);

        $expired = CompanyDataExport::withoutGlobalScopes()->where('status', 'ready')->where('expires_at', '<=', now())->get();

        foreach ($expired as $export) {
            Storage::delete($export->path);
            $export->update(['status' => 'expired', 'path' => null]);
        }

        return $expired->count();
    }

    private function writeZip(Organization $organization, string $workspace): string
    {
        $path = $workspace.'/export.zip';
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export archive could not be created.');
        }

        $manifest = ['company' => $organization->name, 'generated_at' => now()->toIso8601String(), 'tables' => [], 'files_included' => 0, 'files_missing' => 0];
        $tables = array_diff_key($this->data->tables(), array_flip(self::NOT_EXPORTED));

        foreach ($tables as $table => $columns) {
            $manifest['tables'][$table] = $this->addTable($zip, $table, $columns, $organization->id, $workspace, $manifest);
        }

        $company = DB::table('organizations')->where('id', $organization->id)->first();
        $manifest['tables']['organizations'] = $this->addRows($zip, 'organizations', $this->data->exportableColumns(array_keys((array) $company)), [$company], $workspace);
        $manifest['tables']['notifications'] = $this->addNotifications($zip, $organization->id, $workspace);

        $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('README.txt', $this->readme($organization, $manifest));

        if ($zip->close() !== true) {
            throw new RuntimeException('The export archive could not be finished.');
        }

        return $path;
    }

    private function addTable(ZipArchive $zip, string $table, array $columns, int $organizationId, string $workspace, array &$manifest): int
    {
        $fileColumns = $this->data->fileColumns($columns);
        $rows = (function () use ($table, $columns, $organizationId, $fileColumns, $zip, $workspace, &$manifest) {
            foreach ($this->data->rows($table, $columns, $organizationId) as $row) {
                foreach ($fileColumns as $column) {
                    $this->addStoredFile($zip, $table, $row, $column, $workspace, $manifest);
                }

                yield $row;
            }
        })();

        return $this->addRows($zip, $table, $this->data->exportableColumns($columns), $rows, $workspace);
    }

    private function addNotifications(ZipArchive $zip, int $organizationId, string $workspace): int
    {
        $columns = ['id', 'type', 'notifiable_id', 'data', 'read_at', 'created_at'];

        return $this->addRows($zip, 'notifications', $columns, $this->data->notificationsQuery($organizationId)->orderBy('id')->cursor(), $workspace);
    }

    /** Written to disk, not memory: a company can have far more rows than PHP's memory limit allows. */
    private function addRows(ZipArchive $zip, string $table, array $columns, iterable $rows, string $workspace): int
    {
        $csvPath = $workspace.'/'.$table.'.csv';
        $handle = fopen($csvPath, 'w');
        fputcsv($handle, $columns, ',', '"', '');
        $count = 0;

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column) => $this->safeCell($row->{$column} ?? null), $columns), ',', '"', '');
            $count++;
        }

        fclose($handle);
        $zip->addFile($csvPath, 'data/'.$table.'.csv');

        return $count;
    }

    private function addStoredFile(ZipArchive $zip, string $table, object $row, string $column, string $workspace, array &$manifest): void
    {
        $path = $row->{$column} ?? null;

        if ($path === null || $path === '') {
            return;
        }

        if (! $this->data->isSafePath($path) || ! Storage::exists($path)) {
            $manifest['files_missing']++;

            return;
        }

        // Copied through a stream so a photo is never held in memory, whichever disk it is on.
        $source = Storage::readStream($path);

        if (! is_resource($source)) {
            $manifest['files_missing']++;

            return;
        }

        $name = ($row->id ?? Str::random(6)).'-'.basename($path);
        Files::ensureDirectoryExists($workspace.'/files/'.$table);
        $copy = $workspace.'/files/'.$table.'/'.$name;
        $target = fopen($copy, 'w');
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        $zip->addFile($copy, 'files/'.$table.'/'.$name);
        $manifest['files_included']++;
    }

    /** Stops a value typed by a site user from running as a formula when the owner opens the file in Excel. */
    private function safeCell(mixed $value): mixed
    {
        return is_string($value) && ! is_numeric($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    private function readme(Organization $organization, array $manifest): string
    {
        return implode("\n", [
            'Data export for '.$organization->name,
            'Generated '.$manifest['generated_at'],
            '',
            'data/    One CSV file for each kind of record. Each file opens in Excel.',
            'files/   Photos, bills and other uploads, in a folder for each kind of record.',
            '',
            'manifest.json lists how many rows and files are included.',
            'Passwords, sign-in tokens and integration keys are not included.',
            'This file contains personal details. Keep it safe.',
            '',
        ]);
    }

    private function tellRequester(CompanyDataExport $export): void
    {
        $user = User::find($export->requested_by);

        if (! $user) {
            return;
        }

        $locale = $user->locale ?: config('app.locale');

        $this->notifier->send($user, new NotificationMessage(
            event: 'company.export_ready',
            title: __('Your company data export is ready', [], $locale),
            body: __('Download it within :days days.', ['days' => self::KEEP_DAYS], $locale),
            url: route('notify.data.index'),
        ));
    }
}
