<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmTender;
use App\Models\CrmTenderDocument;
use App\Models\Project;
use App\Services\Crm\TenderDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenderDocumentController extends Controller
{
    public function __construct(private TenderDocumentService $service) {}

    public function store(Request $request, CrmTender $tender): RedirectResponse
    {
        $data = $request->validate($this->service->rules());

        $this->service->store($tender, $request->file('file'), $data, $request->user());

        return redirect()->to(route('crm.tenders.show', $tender).'#documents')->with('success', __('Document added.'));
    }

    /** Always a download, never shown in the browser, so an uploaded page cannot run on our domain. */
    public function download(CrmTenderDocument $document): StreamedResponse
    {
        abort_unless($this->service->exists($document), 404);

        return Storage::download($document->path, $document->original_name);
    }

    public function destroy(CrmTenderDocument $document): RedirectResponse
    {
        $this->service->delete($document);

        return redirect()->to(route('crm.tenders.show', $document->tender_id).'#documents')->with('success', __('Document removed.'));
    }

    /** The tender a project came from, and the documents that came with it. */
    public function project(Project $project): View
    {
        $documents = $this->service->forProject($project);

        return view('modules.crm.tenders.project-file', [
            'project' => $project,
            'documents' => $documents,
            'tender' => CrmTender::query()->with('party')->where('organization_id', $project->organization_id)->where('project_id', $project->id)->first(),
        ]);
    }
}
