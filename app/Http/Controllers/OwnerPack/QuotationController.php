<?php

namespace App\Http\Controllers\OwnerPack;

use App\Http\Controllers\Controller;
use App\Models\ClientQuotation;
use App\Models\Party;
use App\Models\Project;
use App\Services\OwnerPack\PackDrafts;
use App\Services\OwnerPack\QuotationDocument;
use App\Services\OwnerPack\QuotationService;
use App\Services\OwnerPack\QuotationShareService;
use App\Services\OwnerPack\QuotationTranslations;
use App\Services\OwnerPack\ShownText;
use App\Services\Wording\ReviewedLanguages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Quotations for a client. Every route here also needs money.view: a quotation is nothing but money. */
class QuotationController extends Controller
{
    public function __construct(private QuotationService $service) {}

    public function index(Project $project): View
    {
        return view('modules.owner_pack.quotations.index', [
            'project' => $project,
            'quotations' => ClientQuotation::where('project_id', $project->id)->withCount('items')->orderByDesc('number')->orderByDesc('revision')->paginate(25),
        ]);
    }

    public function create(Request $request, Project $project): View
    {
        return view('modules.owner_pack.quotations.create', [
            'project' => $project,
            'boqItems' => $project->boqItems()->orderBy('sort')->orderBy('id')->get(),
            'clients' => $this->clients(),
            'language' => ReviewedLanguages::starting(ReviewedLanguages::OWNER_PACK, $request->user()->locale, ClientQuotation::LANGUAGES),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $quotation = $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('quotations.show', $quotation)->with('success', __('Draft quotation prepared. Add the payment stages, check every figure, then issue it.'));
    }

    public function show(Request $request, ClientQuotation $quotation, QuotationDocument $document, QuotationShareService $shares, PackDrafts $drafts, QuotationTranslations $translations): View
    {
        $project = $quotation->project;
        [$sheet, $note] = $this->service->review($quotation);

        return view('modules.owner_pack.quotations.show', [
            'quotation' => $quotation->load('issuedBy'),
            'project' => $project,
            'items' => $quotation->items()->with('acceptedBy')->get(),
            'stages' => $quotation->stages()->with('reachedBy')->get(),
            'note' => $note,
            'tickList' => $note === null ? [] : $document->tickList($note, $sheet),
            'textFingerprint' => $note === null ? null : ShownText::fingerprint($note),
            // On a draft revision: which stages marked on the revision it replaces keep their mark when it is issued.
            'carried' => $quotation->isDraft() ? $this->service->reachedOnEarlier($quotation) : null,
            'replacedBy' => $this->service->replacedBy($quotation),
            'openRevision' => $this->service->openRevision($quotation),
            'shareUrl' => $shares->url($quotation),
            'whatsappUrl' => $shares->whatsappUrl($quotation, (string) $quotation->organization?->name),
            'boqItems' => $quotation->isDraft() ? $project->boqItems()->orderBy('sort')->orderBy('id')->get() : collect(),
            'clients' => $quotation->isDraft() ? $this->clients() : collect(),
            // The AI: the two sentences of the cover note that state no figure, and translations of the descriptions.
            'ai' => $drafts->state($quotation, $request->user()),
            'translation' => $translations->state($quotation, $request->user()),
        ]);
    }

    public function update(Request $request, ClientQuotation $quotation): RedirectResponse
    {
        $this->service->update($quotation, $request->validate($this->service->updateRules($quotation)), $request->user());

        return back()->with('success', __('Draft saved. Check every figure again before you issue it.'));
    }

    public function issue(Request $request, ClientQuotation $quotation): RedirectResponse
    {
        $this->service->issue($quotation, $request->validate($this->service->issueRules()), $request->user());

        return back()->with('success', __('Quotation issued. You can now share it with the client.'));
    }

    public function revise(Request $request, ClientQuotation $quotation): RedirectResponse
    {
        $data = $request->validate(['client_uuid' => ['nullable', 'uuid']]);
        $revision = $this->service->revise($quotation, $request->user(), $data['client_uuid'] ?? null);

        return redirect()->route('quotations.show', $revision)->with('success', __('Revision :n started as a draft. The issued revision stays as it is.', ['n' => $revision->revision]));
    }

    /** The company's client parties, for the "prepared for" list. The tenant scope keeps this to the signed-in company. */
    private function clients()
    {
        return Party::where('type', 'client')->orderBy('name')->get(['id', 'name']);
    }
}
