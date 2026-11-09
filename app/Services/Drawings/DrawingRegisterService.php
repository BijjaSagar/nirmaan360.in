<?php

namespace App\Services\Drawings;

use App\Models\AuditLog;
use App\Models\Drawing;
use App\Models\DrawingFolder;
use App\Models\DrawingIssuePin;
use App\Models\DrawingSheet;
use App\Models\DrawingTransmittalItem;
use App\Models\DrawingVersion;
use App\Models\Project;
use App\Models\TakeoffMeasurement;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class DrawingRegisterService
{
    private const FOLDER_DEPTH = 3;

    // Extensions are checked against the name and the content. HTML and SVG are left out on purpose.
    private const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'dwg', 'dxf', 'txt', 'csv'];

    /** @return array<string, list<mixed>> */
    public function fileRules(): array
    {
        return ['file' => [
            'required', 'file',
            'extensions:'.implode(',', self::EXTENSIONS),
            // Some spreadsheets and documents are only recognised as the zip they are built on.
            'mimes:'.implode(',', [...self::EXTENSIONS, 'zip']),
            'max:'.config('modules.drawings.max_upload_kb'),
        ]];
    }

    /** @return array<string, list<mixed>> */
    public function drawingRules(Project $project): array
    {
        return [
            'kind' => ['required', Rule::in(['drawing', 'document'])],
            'number' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:200'],
            'discipline' => ['nullable', Rule::in(config('modules.drawings.disciplines'))],
            'drawing_folder_id' => ['bail', 'nullable', 'integer', Rule::exists('drawing_folders', 'id')->where('project_id', $project->id)],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function versionRules(): array
    {
        return [
            'revision' => ['required', 'string', 'max:16'],
            'status' => ['required', Rule::in(config('modules.drawings.version_statuses'))],
            'note' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function folderRules(Project $project): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => ['bail', 'nullable', 'integer', Rule::exists('drawing_folders', 'id')->where('project_id', $project->id)],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function sheetPageRules(): array
    {
        return [
            'pages' => ['required', 'array', 'min:1', 'max:500'],
            'pages.*.page' => ['required', 'integer', 'min:1', 'distinct'],
            'pages.*.width' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'pages.*.height' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ];
    }

    public function createFolder(Project $project, array $data): DrawingFolder
    {
        return DB::transaction(function () use ($project, $data) {
            Project::lockForUpdate()->findOrFail($project->id);
            $parentId = $data['parent_id'] ?? null;

            if ($parentId && $this->depthOf((int) $parentId) >= self::FOLDER_DEPTH) {
                throw ValidationException::withMessages(['parent_id' => __('Folders can only go :depth levels deep.', ['depth' => self::FOLDER_DEPTH])]);
            }

            return DrawingFolder::firstOrCreate([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'parent_id' => $parentId,
                'name' => trim($data['name']),
            ]);
        });
    }

    public function renameFolder(DrawingFolder $folder, string $name): DrawingFolder
    {
        $name = trim($name);

        $clash = DrawingFolder::where('project_id', $folder->project_id)->where('parent_id', $folder->parent_id)
            ->where('name', $name)->whereKeyNot($folder->id)->exists();

        if ($clash) {
            throw ValidationException::withMessages(['name' => __('Another folder here already has this name.')]);
        }

        $folder->update(['name' => $name]);

        return $folder;
    }

    public function deleteFolder(DrawingFolder $folder): void
    {
        if ($folder->children()->exists() || $folder->drawings()->exists()) {
            throw ValidationException::withMessages(['folder' => __('This folder is not empty and cannot be removed.')]);
        }

        $folder->delete();
    }

    /** Add a drawing or document with its first version. A repeated client_uuid returns the original. */
    public function create(Project $project, array $data, UploadedFile $file, User $user): Drawing
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findDrawing($project->organization_id, $uuid))) {
            return $existing;
        }

        $path = $file->store('org-'.$project->organization_id.'/drawings/project-'.$project->id);

        try {
            return DB::transaction(function () use ($project, $data, $file, $user, $path, $uuid) {
                Project::lockForUpdate()->findOrFail($project->id);

                if ($uuid && ($existing = $this->findDrawing($project->organization_id, $uuid))) {
                    Storage::delete($path);

                    return $existing;
                }

                $this->assertNumberFree($project->id, trim($data['number']));

                $drawing = Drawing::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'drawing_folder_id' => $data['drawing_folder_id'] ?? null,
                    'kind' => $data['kind'],
                    'number' => trim($data['number']),
                    'title' => $data['title'],
                    'discipline' => $data['discipline'] ?? null,
                    'created_by' => $user->id,
                    'client_uuid' => $uuid,
                ]);

                $this->addVersionRow($drawing, $data, $file, $path, $user, null);

                return $drawing;
            });
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }
    }

    /** Upload a new revision, which becomes the current one. A repeated client_uuid returns the original. */
    public function addVersion(Drawing $drawing, array $data, UploadedFile $file, User $user): DrawingVersion
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findVersion($drawing->organization_id, $uuid))) {
            return $existing;
        }

        $path = $file->store('org-'.$drawing->organization_id.'/drawings/project-'.$drawing->project_id);

        try {
            return DB::transaction(function () use ($drawing, $data, $file, $user, $path, $uuid) {
                $drawing = Drawing::lockForUpdate()->findOrFail($drawing->id);

                if ($uuid && ($existing = $this->findVersion($drawing->organization_id, $uuid))) {
                    Storage::delete($path);

                    return $existing;
                }

                $revision = trim($data['revision']);

                if ($drawing->versions()->where('revision', $revision)->exists()) {
                    throw ValidationException::withMessages(['revision' => __('This drawing already has a revision :revision.', ['revision' => $revision])]);
                }

                $drawing->currentVersion?->update(['is_current' => false]);

                return $this->addVersionRow($drawing, $data, $file, $path, $user, $uuid);
            });
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }
    }

    public function update(Drawing $drawing, array $data): Drawing
    {
        return DB::transaction(function () use ($drawing, $data) {
            Project::lockForUpdate()->findOrFail($drawing->project_id);
            $number = trim($data['number']);
            $this->assertNumberFree($drawing->project_id, $number, $drawing->id);

            $drawing->update([
                'number' => $number,
                'title' => $data['title'],
                'kind' => $data['kind'],
                'discipline' => $data['discipline'] ?? null,
                'drawing_folder_id' => $data['drawing_folder_id'] ?? null,
            ]);

            return $drawing;
        });
    }

    /** A drawing that was issued, measured or pinned is part of the record and stays in the register. */
    public function delete(Drawing $drawing): void
    {
        $versionIds = DrawingVersion::where('drawing_id', $drawing->id)->pluck('id');
        $sheetIds = DrawingSheet::whereIn('drawing_version_id', $versionIds)->pluck('id');

        $inUse = DrawingTransmittalItem::whereIn('drawing_version_id', $versionIds)->exists()
            || TakeoffMeasurement::whereIn('drawing_sheet_id', $sheetIds)->exists()
            || DrawingIssuePin::whereIn('drawing_sheet_id', $sheetIds)->exists();

        if ($inUse) {
            throw ValidationException::withMessages(['drawing' => __('This drawing has transmittals, takeoff or pinned issues and cannot be removed.')]);
        }

        $drawing->delete();
    }

    /**
     * Record the pages the browser read from the file. Safe to call twice: the first call wins.
     * Issues pinned on the previous revision move to the same page when the page size is unchanged.
     *
     * @param  list<array{page:int,width:mixed,height:mixed}>  $pages
     */
    public function registerSheets(DrawingVersion $version, array $pages): int
    {
        return DB::transaction(function () use ($version, $pages) {
            $version = DrawingVersion::lockForUpdate()->findOrFail($version->id);

            if ($version->sheets()->exists()) {
                return 0;
            }

            // One audit row covers the whole file, not one per page.
            $rows = DrawingSheet::withoutEvents(fn () => array_map(fn (array $page) => DrawingSheet::create([
                'organization_id' => $version->organization_id,
                'project_id' => $version->project_id,
                'drawing_version_id' => $version->id,
                'page_no' => (int) $page['page'],
                'width' => Dec::qty($page['width']),
                'height' => Dec::qty($page['height']),
            ]), $pages));

            AuditLog::record('sheets_registered', $version, null, ['pages' => count($rows)]);
            $this->carryPinsForward($version, $rows);

            return count($rows);
        });
    }

    public function updateSheet(DrawingSheet $sheet, array $data): DrawingSheet
    {
        $sheet->update($data);

        return $sheet;
    }

    /** @param list<DrawingSheet> $sheets */
    private function carryPinsForward(DrawingVersion $version, array $sheets): void
    {
        $previous = DrawingVersion::where('drawing_id', $version->drawing_id)
            ->where('version_no', '<', $version->version_no)->orderByDesc('version_no')->first();

        if (! $previous) {
            return;
        }

        $oldSheets = DrawingSheet::where('drawing_version_id', $previous->id)->get()->keyBy('page_no');

        foreach ($sheets as $sheet) {
            $old = $oldSheets[$sheet->page_no] ?? null;

            if (! $old || ! $this->sameSize($old, $sheet)) {
                continue;
            }

            foreach (DrawingIssuePin::where('drawing_sheet_id', $old->id)->get() as $pin) {
                $pin->update(['drawing_sheet_id' => $sheet->id]);
            }
        }
    }

    private function sameSize(DrawingSheet $a, DrawingSheet $b): bool
    {
        return Dec::of($a->width)->minus(Dec::of($b->width))->abs()->isLessThanOrEqualTo(1)
            && Dec::of($a->height)->minus(Dec::of($b->height))->abs()->isLessThanOrEqualTo(1);
    }

    private function addVersionRow(Drawing $drawing, array $data, UploadedFile $file, string $path, User $user, ?string $uuid): DrawingVersion
    {
        return DrawingVersion::create([
            'organization_id' => $drawing->organization_id,
            'project_id' => $drawing->project_id,
            'drawing_id' => $drawing->id,
            'version_no' => (int) DrawingVersion::where('drawing_id', $drawing->id)->max('version_no') + 1,
            'revision' => trim($data['revision']),
            'status' => $data['status'],
            'path' => $path,
            'original_name' => $this->safeName($file->getClientOriginalName()),
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'note' => $data['note'] ?? null,
            'is_current' => true,
            'uploaded_by' => $user->id,
            'client_uuid' => $uuid,
        ]);
    }

    /** Keeps a download's filename header valid, whatever the uploader's file was called. */
    private function safeName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '-', $name) ?? 'file';

        return mb_substr($name, -200) ?: 'file';
    }

    private function assertNumberFree(int $projectId, string $number, ?int $except = null): void
    {
        $taken = Drawing::where('project_id', $projectId)->where('number', $number)
            ->when($except, fn ($query) => $query->whereKeyNot($except))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['number' => __('This project already has an entry numbered :number.', ['number' => $number])]);
        }
    }

    private function depthOf(int $folderId): int
    {
        $depth = 0;
        $current = DrawingFolder::find($folderId);

        while ($current && $depth <= self::FOLDER_DEPTH) {
            $depth++;
            $current = $current->parent_id ? DrawingFolder::find($current->parent_id) : null;
        }

        return $depth;
    }

    private function findDrawing(int $organizationId, string $uuid): ?Drawing
    {
        return Drawing::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    private function findVersion(int $organizationId, string $uuid): ?DrawingVersion
    {
        return DrawingVersion::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
