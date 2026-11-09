<?php

namespace App\Services\Crm;

use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TenderDocumentService
{
    public const EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'zip', 'dwg', 'dxf'];

    public function __construct(private ActivityService $activities) {}

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480', 'extensions:'.implode(',', self::EXTENSIONS)],
            'title' => ['nullable', 'string', 'max:160'],
            'kind' => ['required', Rule::in(CrmTenderDocument::KINDS)],
        ];
    }

    /** Keep a file on the private disk and record it against the tender. */
    public function store(CrmTender $tender, UploadedFile $file, array $data, User $user): CrmTenderDocument
    {
        $path = $file->store('org-'.$tender->organization_id.'/crm-tenders/'.$tender->id);

        $document = CrmTenderDocument::create([
            'organization_id' => $tender->organization_id,
            'tender_id' => $tender->id,
            // A document added after the tender became a project goes straight to the project.
            'project_id' => $tender->project_id,
            'title' => filled($data['title'] ?? null) ? $data['title'] : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'kind' => $data['kind'],
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => $user->id,
        ]);

        $this->activities->log($tender, 'system', __('Document added: :title', ['title' => $document->title]), $user);

        return $document;
    }

    /** The file is kept so a mistaken delete can be undone from the database. */
    public function delete(CrmTenderDocument $document): void
    {
        $document->delete();
    }

    public function exists(CrmTenderDocument $document): bool
    {
        return Storage::exists($document->path);
    }

    /** @return Collection<int,CrmTenderDocument> */
    public function forProject(Project $project): Collection
    {
        return CrmTenderDocument::query()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->with('tender')
            ->orderBy('title')
            ->get();
    }
}
