<?php

namespace App\Http\Controllers\ProjectSetup;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectPermit;
use App\Models\ProjectPermitFile;
use App\Services\ProjectSetup\PermitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PermitController extends Controller
{
    public function __construct(private PermitService $service) {}

    public function index(Request $request, Project $project): View
    {
        return view('modules.project_setup.permits', [
            'project' => $project,
            'permits' => ProjectPermit::with('files')->where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get(),
            'counts' => $this->service->counts($project),
            // The fees are a budget head: only money.overview sees them.
            'totalFee' => $request->user()->can('money.overview') ? $this->service->totalFee($project) : null,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->service->create($project, $request->validate($this->service->rules($request->user())));

        return back()->with('success', __('Permission added.'));
    }

    public function update(Request $request, ProjectPermit $permit): RedirectResponse
    {
        $this->service->update($permit, $request->validate($this->service->rules($request->user())));

        return back()->with('success', __('Permission updated.'));
    }

    public function destroy(ProjectPermit $permit): RedirectResponse
    {
        $this->service->delete($permit);

        return back()->with('success', __('Permission removed.'));
    }

    public function storeFile(Request $request, ProjectPermit $permit): RedirectResponse
    {
        $request->validate($this->service->fileRules());

        $this->service->addFile($permit, $request->file('file'), $request->user());

        return back()->with('success', __('Document added.'));
    }

    public function showFile(ProjectPermitFile $file): StreamedResponse
    {
        abort_unless(Storage::exists($file->path), 404);

        return Storage::response($file->path, $file->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function destroyFile(ProjectPermitFile $file): RedirectResponse
    {
        $this->service->removeFile($file);

        return back()->with('success', __('Document removed.'));
    }
}
