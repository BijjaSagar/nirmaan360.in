<?php

namespace App\Http\Controllers\Drawings;

use App\Http\Controllers\Controller;
use App\Models\Drawing;
use App\Models\DrawingFolder;
use App\Models\DrawingSheet;
use App\Models\DrawingTransmittalItem;
use App\Models\DrawingVersion;
use App\Models\Project;
use App\Services\Drawings\DrawingRegisterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegisterController extends Controller
{
    public function __construct(private DrawingRegisterService $service) {}

    public function index(Request $request, Project $project): View
    {
        $folderId = $request->integer('folder') ?: null;
        $kind = in_array($request->query('kind'), ['drawing', 'document'], true) ? $request->query('kind') : null;
        $search = trim((string) $request->query('q'));
        $view = $request->query('view') === 'sheets' ? 'sheets' : 'list';

        $folders = DrawingFolder::where('project_id', $project->id)->withCount('drawings')->orderBy('name')->get();

        $drawings = $view === 'list'
            ? Drawing::where('project_id', $project->id)->with('currentVersion.sheets', 'folder')
                ->when($folderId, fn ($query) => $query->where('drawing_folder_id', $folderId))
                ->when($kind, fn ($query) => $query->where('kind', $kind))
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%'.mb_strtolower($search).'%';
                    $query->where(fn ($inner) => $inner->whereRaw('LOWER(number) LIKE ?', [$like])->orWhereRaw('LOWER(title) LIKE ?', [$like]));
                })
                ->orderBy('number')->paginate(25)->withQueryString()
            : null;

        $sheets = $view === 'sheets'
            ? DrawingSheet::where('project_id', $project->id)
                ->whereHas('version', fn ($query) => $query->where('is_current', true)->whereHas('drawing'))
                ->with('version.drawing')->withCount('measurements', 'pins')
                ->orderBy('drawing_version_id')->orderBy('page_no')->paginate(50)->withQueryString()
            : null;

        return view('modules.drawings.index', [
            'project' => $project,
            'folders' => $folders,
            'tree' => $folders->groupBy(fn (DrawingFolder $folder) => $folder->parent_id ?? 0),
            'folderId' => $folderId,
            'kind' => $kind,
            'search' => $search,
            'view' => $view,
            'drawings' => $drawings,
            'sheets' => $sheets,
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function show(Drawing $drawing): View
    {
        $drawing->load('versions.uploader', 'folder', 'currentVersion');
        $current = $drawing->currentVersion;

        return view('modules.drawings.show', [
            'drawing' => $drawing,
            'project' => $drawing->project,
            'current' => $current,
            'sheets' => $current ? $current->sheets()->withCount('measurements', 'pins')->get() : collect(),
            'transmittalItems' => DrawingTransmittalItem::whereIn('drawing_version_id', $drawing->versions->pluck('id'))
                ->with('transmittal', 'version')->latest('id')->get(),
            'folders' => DrawingFolder::where('project_id', $drawing->project_id)->orderBy('name')->get(),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            ...$this->service->drawingRules($project),
            ...$this->service->versionRules(),
            ...$this->service->fileRules(),
        ]);

        $drawing = $this->service->create($project, $data, $request->file('file'), $request->user());

        return redirect()->route('drawings.register.show', $drawing)->with('success', __('Added to the register.'));
    }

    public function update(Request $request, Drawing $drawing): RedirectResponse
    {
        $this->service->update($drawing, $request->validate($this->service->drawingRules($drawing->project)));

        return back()->with('success', __('Register entry updated.'));
    }

    public function destroy(Drawing $drawing): RedirectResponse
    {
        $project = $drawing->project;
        $this->service->delete($drawing);

        return redirect()->route('drawings.register.index', $project)->with('success', __('Removed from the register.'));
    }

    public function storeVersion(Request $request, Drawing $drawing): RedirectResponse
    {
        $data = $request->validate([...$this->service->versionRules(), ...$this->service->fileRules()]);
        $this->service->addVersion($drawing, $data, $request->file('file'), $request->user());

        return back()->with('success', __('New revision added.'));
    }

    public function file(Request $request, DrawingVersion $version): StreamedResponse
    {
        abort_if($version->drawing === null, 404);

        $disposition = $version->isViewable() && ! $request->boolean('download') ? 'inline' : 'attachment';

        return Storage::response($version->path, $version->original_name, [
            'Content-Type' => $version->mime,
            'X-Content-Type-Options' => 'nosniff',
        ], $disposition);
    }

    public function storeFolder(Request $request, Project $project): RedirectResponse
    {
        $this->service->createFolder($project, $request->validate($this->service->folderRules($project)));

        return back()->with('success', __('Folder added.'));
    }

    public function updateFolder(Request $request, DrawingFolder $folder): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $this->service->renameFolder($folder, $data['name']);

        return back()->with('success', __('Folder renamed.'));
    }

    public function destroyFolder(DrawingFolder $folder): RedirectResponse
    {
        $this->service->deleteFolder($folder);

        return back()->with('success', __('Folder removed.'));
    }
}
