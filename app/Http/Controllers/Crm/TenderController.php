<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Models\CrmTender;
use App\Models\Party;
use App\Services\Crm\ActivityService;
use App\Services\Crm\CustomFieldService;
use App\Services\Crm\Lookups;
use App\Services\Crm\MessageService;
use App\Services\Crm\ReasonService;
use App\Services\Crm\TagService;
use App\Services\Crm\TaskService;
use App\Services\Crm\TenderBoqService;
use App\Services\Crm\TenderConversionService;
use App\Services\Crm\TenderPaymentService;
use App\Services\Crm\TenderService;
use App\Services\Crm\Vocab;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenderController extends Controller
{
    public function __construct(
        private TenderService $service,
        private CustomFieldService $fields,
        private Lookups $lookups,
        private TagService $tags,
    ) {}

    /** The Kanban board, or a plain list with ?view=list. */
    public function index(Request $request): View
    {
        $organizationId = $request->user()->organization_id;
        $filters = $request->only(['q', 'tag', 'owner', 'stage', 'party']);
        $list = $request->query('view') === 'list';

        return view('modules.crm.tenders.index', [
            'list' => $list,
            'filters' => $filters,
            'columns' => $list ? null : $this->service->board($organizationId, $filters, $request->boolean('all')),
            'tenders' => $list ? $this->service->query($organizationId, $filters)->latest('id')->paginate(25)->withQueryString() : null,
            'owners' => $this->lookups->users($organizationId),
            'tagOptions' => $this->tags->suggestions($organizationId),
            'allClosed' => $request->boolean('all'),
        ]);
    }

    public function create(Request $request): View
    {
        $organizationId = $request->user()->organization_id;
        $tender = new CrmTender(['stage' => 'enquiry', 'owner_id' => $request->user()->id]);

        // Starting from a lead brings its company and name along.
        if ($lead = CrmLead::query()->where('organization_id', $organizationId)->find($request->query('lead'))) {
            $tender->fill([
                'lead_id' => $lead->id,
                'party_id' => $lead->party_id,
                'authority' => $lead->company_name ?: $lead->name,
                'location' => $lead->location,
                'tags' => $lead->tags,
                'owner_id' => $lead->owner_id ?? $request->user()->id,
            ]);
        }

        // Starting from a company fills in the client and the authority.
        if (! $tender->party_id && ($party = Party::query()->where('organization_id', $organizationId)->find($request->query('party')))) {
            $tender->fill(['party_id' => $party->id, 'authority' => $party->name]);
        }

        return view('modules.crm.tenders.form', $this->formData($tender, $organizationId));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $tender = $this->service->create($this->validated($request), $user);

        return redirect()->route('crm.tenders.show', $tender)->with('success', __('Tender added.'));
    }

    public function show(
        Request $request,
        CrmTender $tender,
        TenderPaymentService $payments,
        TenderBoqService $boq,
        ActivityService $activities,
        TaskService $tasks,
        MessageService $messages,
        ReasonService $reasons,
        TenderConversionService $conversion,
    ): View {
        $organizationId = $request->user()->organization_id;
        $tender->load(['party', 'owner', 'reason', 'project', 'lead']);

        $paymentRows = $tender->payments()->orderByDesc('id')->get()->each->setRelation('tender', $tender);

        return view('modules.crm.tenders.show', [
            'tender' => $tender,
            'payments' => $paymentRows,
            'totals' => $payments->totals($paymentRows),
            'boqItems' => $tender->boqItems()->get(),
            'boqTotal' => $boq->total($tender),
            'documents' => $tender->documents()->with('uploader')->latest('id')->get(),
            'timeline' => $activities->timeline($tender),
            'tasks' => $tasks->forSubject($tender),
            'templates' => $messages->templates($organizationId),
            'reasonOptions' => collect(CrmTender::CLOSED_STAGES)->mapWithKeys(fn ($kind) => [$kind => $reasons->options($organizationId, $kind)])->all(),
            'conversion' => $tender->stage === 'won' && ! $tender->isConverted() ? $conversion->preview($tender) : null,
            'fieldDefinitions' => $this->fields->definitions($organizationId, 'tender'),
            'owners' => $this->lookups->users($organizationId),
            'closing' => in_array($request->query('close'), CrmTender::CLOSED_STAGES, true) ? $request->query('close') : null,
        ]);
    }

    public function edit(Request $request, CrmTender $tender): View
    {
        return view('modules.crm.tenders.form', $this->formData($tender, $request->user()->organization_id));
    }

    public function update(Request $request, CrmTender $tender): RedirectResponse
    {
        $this->service->update($tender, $this->validated($request));

        return redirect()->route('crm.tenders.show', $tender)->with('success', __('Tender updated.'));
    }

    public function destroy(CrmTender $tender): RedirectResponse
    {
        $this->service->delete($tender);

        return redirect()->route('crm.tenders.index')->with('success', __('Tender deleted.'));
    }

    /** Move between open stages. Won, lost and not bid ask for the reason first. */
    public function move(Request $request, CrmTender $tender): RedirectResponse
    {
        $data = $request->validate(['stage' => ['required', Rule::in(CrmTender::STAGES)]]);

        if (in_array($data['stage'], CrmTender::CLOSED_STAGES, true)) {
            return redirect()->route('crm.tenders.show', [$tender, 'close' => $data['stage']]);
        }

        $this->service->move($tender, $data['stage'], $request->user());

        // Land on the column the card moved to, so on a phone the person sees it arrive.
        return redirect()->to(route('crm.tenders.index', $request->only(['q', 'tag', 'owner'])).'#'.$data['stage'])
            ->with('success', __('Moved to :stage.', ['stage' => Vocab::stages()[$data['stage']]]));
    }

    public function close(Request $request, CrmTender $tender): RedirectResponse
    {
        $data = $request->validate($this->service->closeRules());

        $this->service->close($tender, $data['outcome'], $data, $request->user());

        return redirect()->route('crm.tenders.show', $tender)->with('success', __('Tender closed as :outcome.', ['outcome' => mb_strtolower(Vocab::stages()[$data['outcome']])]));
    }

    public function reopen(Request $request, CrmTender $tender): RedirectResponse
    {
        $data = $request->validate(['stage' => ['nullable', Rule::in(CrmTender::OPEN_STAGES)]]);

        $this->service->reopen($tender, $data['stage'] ?? 'bidding', $request->user());

        return redirect()->route('crm.tenders.show', $tender)->with('success', __('Tender reopened.'));
    }

    private function formData(CrmTender $tender, int $organizationId): array
    {
        return [
            'tender' => $tender,
            'parties' => $this->lookups->parties($organizationId),
            'owners' => $this->lookups->users($organizationId),
            'tagOptions' => $this->tags->suggestions($organizationId),
            'fieldDefinitions' => $this->fields->definitions($organizationId, 'tender'),
        ];
    }

    private function validated(Request $request): array
    {
        $organizationId = $request->user()->organization_id;

        return $request->validate($this->service->rules($request->user()), [], $this->fields->attributes($organizationId, 'tender'));
    }
}
