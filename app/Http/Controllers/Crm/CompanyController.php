<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmCompanyProfile;
use App\Models\CrmLead;
use App\Models\CrmTender;
use App\Models\Party;
use App\Services\Crm\ActivityService;
use App\Services\Crm\CompanyService;
use App\Services\Crm\CustomFieldService;
use App\Services\Crm\Lookups;
use App\Services\Crm\MessageService;
use App\Services\Crm\TagService;
use App\Services\Crm\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function __construct(
        private CompanyService $service,
        private CustomFieldService $fields,
        private Lookups $lookups,
        private TagService $tags,
    ) {}

    public function index(Request $request): View
    {
        $organizationId = $request->user()->organization_id;
        $filters = $request->only(['type', 'q', 'tag']);
        $companies = $this->service->query($organizationId, $filters)->paginate(30)->withQueryString();

        return view('modules.crm.companies.index', [
            'filters' => $filters,
            'companies' => $companies,
            'profiles' => CrmCompanyProfile::query()->where('organization_id', $organizationId)->whereIn('party_id', $companies->pluck('id'))->get()->keyBy('party_id'),
            'tagOptions' => $this->tags->suggestions($organizationId),
        ]);
    }

    public function create(Request $request): View
    {
        return view('modules.crm.companies.form', $this->formData(new Party(['type' => 'client']), $request->user()->organization_id));
    }

    public function store(Request $request): RedirectResponse
    {
        $party = $this->service->create($this->validated($request), $request->user());

        return redirect()->route('crm.companies.show', $party)->with('success', __('Company added.'));
    }

    public function show(Request $request, Party $party, ActivityService $activities, TaskService $tasks, MessageService $messages): View
    {
        $organizationId = $request->user()->organization_id;

        return view('modules.crm.companies.show', [
            'party' => $party,
            'profile' => $this->service->profile($party)->load('owner'),
            'tenders' => CrmTender::query()->where('organization_id', $organizationId)->where('party_id', $party->id)->latest('id')->limit(20)->get(),
            'leads' => CrmLead::query()->where('organization_id', $organizationId)->where('party_id', $party->id)->latest('id')->limit(20)->get(),
            'timeline' => $activities->timeline($party),
            'tasks' => $tasks->forSubject($party),
            'templates' => $messages->templates($organizationId),
            'fieldDefinitions' => $this->fields->definitions($organizationId, 'company'),
        ]);
    }

    public function edit(Request $request, Party $party): View
    {
        return view('modules.crm.companies.form', $this->formData($party, $request->user()->organization_id));
    }

    public function update(Request $request, Party $party): RedirectResponse
    {
        $this->service->update($party, $this->validated($request));

        return redirect()->route('crm.companies.show', $party)->with('success', __('Company updated.'));
    }

    private function formData(Party $party, int $organizationId): array
    {
        return [
            'party' => $party,
            'profile' => $party->exists ? $this->service->profile($party) : null,
            'owners' => $this->lookups->users($organizationId),
            'tagOptions' => $this->tags->suggestions($organizationId),
            'fieldDefinitions' => $this->fields->definitions($organizationId, 'company'),
        ];
    }

    private function validated(Request $request): array
    {
        return $request->validate($this->service->rules($request->user()), [], $this->fields->attributes($request->user()->organization_id, 'company'));
    }
}
