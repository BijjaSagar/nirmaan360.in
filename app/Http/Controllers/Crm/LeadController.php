<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmLead;
use App\Services\Crm\ActivityService;
use App\Services\Crm\CustomFieldService;
use App\Services\Crm\LeadService;
use App\Services\Crm\Lookups;
use App\Services\Crm\MessageService;
use App\Services\Crm\TagService;
use App\Services\Crm\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeadController extends Controller
{
    public function __construct(
        private LeadService $service,
        private CustomFieldService $fields,
        private Lookups $lookups,
        private TagService $tags,
    ) {}

    public function index(Request $request): View
    {
        $organizationId = $request->user()->organization_id;
        $filters = $request->only(['status', 'source', 'owner', 'q', 'tag']);

        return view('modules.crm.leads.index', [
            'filters' => $filters,
            'leads' => $this->service->query($organizationId, $filters)->paginate(25)->withQueryString(),
            'counts' => CrmLead::query()->where('organization_id', $organizationId)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'owners' => $this->lookups->users($organizationId),
            'tagOptions' => $this->tags->suggestions($organizationId),
        ]);
    }

    public function create(Request $request): View
    {
        $organizationId = $request->user()->organization_id;

        return view('modules.crm.leads.form', $this->formData(new CrmLead(['source' => 'other', 'owner_id' => $request->user()->id]), $organizationId));
    }

    public function store(Request $request): RedirectResponse
    {
        $lead = $this->service->create($this->validated($request), $request->user());

        return redirect()->route('crm.leads.show', $lead)->with('success', __('Lead added.'));
    }

    public function show(Request $request, CrmLead $lead, ActivityService $activities, TaskService $tasks, MessageService $messages): View
    {
        $organizationId = $request->user()->organization_id;

        return view('modules.crm.leads.show', [
            'lead' => $lead->load(['owner', 'party']),
            'timeline' => $activities->timeline($lead),
            'tasks' => $tasks->forSubject($lead),
            'templates' => $messages->templates($organizationId),
            'fieldDefinitions' => $this->fields->definitions($organizationId, 'lead'),
            'parties' => $this->lookups->parties($organizationId),
            'owners' => $this->lookups->users($organizationId),
        ]);
    }

    public function edit(Request $request, CrmLead $lead): View
    {
        return view('modules.crm.leads.form', $this->formData($lead, $request->user()->organization_id));
    }

    public function update(Request $request, CrmLead $lead): RedirectResponse
    {
        $this->service->update($lead, $this->validated($request));

        return redirect()->route('crm.leads.show', $lead)->with('success', __('Lead updated.'));
    }

    public function destroy(CrmLead $lead): RedirectResponse
    {
        $this->service->delete($lead);

        return redirect()->route('crm.leads.index')->with('success', __('Lead deleted.'));
    }

    public function status(Request $request, CrmLead $lead): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([...CrmLead::OPEN_STATUSES, 'lost'])],
            'lost_note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->setStatus($lead, $data['status'], $data['lost_note'] ?? null, $request->user());

        return back()->with('success', __('Lead updated.'));
    }

    /** Turn the lead into a company, using Party, or link it to one that already exists. */
    public function convert(Request $request, CrmLead $lead): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(config('buildos.party_types'))],
            'party_id' => ['nullable', org_exists('parties')],
        ]);

        $party = $this->service->convert($lead, $request->user(), $data['type'], $data['party_id'] ?? null);

        return redirect()->route('crm.companies.show', $party)->with('success', __('Lead converted to a company.'));
    }

    private function formData(CrmLead $lead, int $organizationId): array
    {
        return [
            'lead' => $lead,
            'owners' => $this->lookups->users($organizationId),
            'tagOptions' => $this->tags->suggestions($organizationId),
            'fieldDefinitions' => $this->fields->definitions($organizationId, 'lead'),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate($this->service->rules($request->user()), [], $this->fields->attributes($request->user()->organization_id, 'lead'));
    }
}
