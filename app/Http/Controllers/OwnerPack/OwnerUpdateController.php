<?php

namespace App\Http\Controllers\OwnerPack;

use App\Http\Controllers\Controller;
use App\Models\OwnerUpdate;
use App\Models\Project;
use App\Services\OwnerPack\OwnerUpdateDocument;
use App\Services\OwnerPack\OwnerUpdateService;
use App\Services\OwnerPack\OwnerUpdateShareService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Weekly updates for a client. A site engineer may prepare one that carries no money. An update that
 * states the payment position is refused, as a whole, to anyone without money.view.
 */
class OwnerUpdateController extends Controller
{
    public function __construct(private OwnerUpdateService $service) {}

    public function index(Project $project): View
    {
        return view('modules.owner_pack.updates.index', [
            'project' => $project,
            'updates' => OwnerUpdate::where('project_id', $project->id)->with('createdBy')->orderByDesc('week_start')->orderByDesc('id')->paginate(25),
        ]);
    }

    public function create(Request $request, Project $project): View
    {
        return view('modules.owner_pack.updates.create', [
            'project' => $project,
            'language' => in_array($request->user()->locale, OwnerUpdate::LANGUAGES, true) ? $request->user()->locale : 'en',
            'weekStart' => today()->startOfWeek()->toDateString(),
            'quotations' => Money::visible($request->user()) ? $this->service->standingQuotations($project->organization_id, $project->id) : collect(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $update = $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('owner-updates.show', $update)->with('success', __('Draft update prepared. Pick the photos, check every figure, then have it approved.'));
    }

    public function show(Request $request, OwnerUpdate $update, OwnerUpdateDocument $document, OwnerUpdateShareService $shares): View
    {
        $this->service->assertMayRead($update, $request->user());

        $draft = $update->isDraft();
        // A draft is shown as it would be written now. An approved update is shown as it was approved.
        [$sheet, $note] = $draft ? $this->service->review($update) : [$this->service->kept($update), null];
        $from = $update->week_start->toDateString();
        $to = $update->week_end->toDateString();

        return view('modules.owner_pack.updates.show', [
            'update' => $update->load('approvedBy', 'createdBy'),
            'project' => $update->project,
            'note' => $note,
            'body' => $draft ? [] : (array) $update->body_rendered,
            'tickList' => $note === null ? [] : $document->tickList($note, $sheet),
            'work' => $document->workLines($sheet, app()->getLocale()),
            'photos' => $document->photos($sheet, app()->getLocale()),
            'current' => $this->service->isCurrent($update),
            'weekPhotos' => $draft ? $this->service->weekPhotos($update->organization_id, $update->project_id, $from, $to) : collect(),
            'quotations' => $draft && Money::visible($request->user()) ? $this->service->standingQuotations($update->organization_id, $update->project_id) : collect(),
            'shareUrl' => $shares->url($update),
            'whatsappUrl' => $shares->whatsappUrl($update, (string) $update->organization?->name),
        ]);
    }

    public function update(Request $request, OwnerUpdate $update): RedirectResponse
    {
        $this->service->update($update, $request->validate($this->service->updateRules($update)), $request->user());

        return back()->with('success', __('Draft saved with the records as they stand today. Check every figure again before it is approved.'));
    }

    public function approve(Request $request, OwnerUpdate $update): RedirectResponse
    {
        $this->service->approve($update, $request->validate($this->service->approveRules()), $request->user());

        return back()->with('success', __('Update approved. You can now share it with the client.'));
    }
}
