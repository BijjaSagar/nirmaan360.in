<?php

namespace App\Http\Controllers;

use App\Models\BoqItem;
use App\Models\Party;
use App\Models\Project;
use App\Support\Dec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(): View
    {
        return view('projects.index', ['projects' => Project::with('client')->latest('id')->paginate(20)]);
    }

    public function create(): View
    {
        return view('projects.form', ['project' => new Project, 'clients' => $this->clients()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->organization->canAddProject(), 403, __('Your plan project limit has been reached. Upgrade to add more.'));

        $project = Project::create([
            ...$this->validated($request),
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('projects.show', $project)->with('success', __('Project created.'));
    }

    public function show(Project $project): View
    {
        $items = BoqItem::withLedger()->where('project_id', $project->id)->get();

        $boqValue = Dec::of(0);
        $approvedValue = Dec::of(0);

        foreach ($items as $item) {
            $boqValue = $boqValue->plus(Dec::of($item->qty)->multipliedBy(Dec::of($item->rate)));
            $approvedValue = $approvedValue->plus(Dec::of(Dec::qty($item->approved_qty ?? 0))->multipliedBy(Dec::of($item->rate)));
        }

        return view('projects.show', [
            'project' => $project->load('client', 'sites'),
            'boqValue' => Dec::money($boqValue),
            'approvedValue' => Dec::money($approvedValue),
            'itemCount' => $items->count(),
            'openIssues' => $project->issues()->whereIn('status', ['open', 'in_progress'])->count(),
            'dprCount' => $project->dprs()->count(),
            'recentDprs' => $project->dprs()->with('creator')->latest('report_date')->take(5)->get(),
            'bills' => $project->raBills()->latest('id')->take(5)->get(),
        ]);
    }

    public function edit(Project $project): View
    {
        return view('projects.form', ['project' => $project, 'clients' => $this->clients()]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $project->update([...$this->validated($request, $project), 'status' => $request->validate([
            'status' => ['required', Rule::in(config('buildos.project_statuses'))],
        ])['status']]);

        return redirect()->route('projects.show', $project)->with('success', __('Project updated.'));
    }

    public function storeSite(Request $request, Project $project): RedirectResponse
    {
        $project->sites()->create($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'geofence_radius_m' => ['nullable', 'integer', 'min:10', 'max:50000'],
        ]) + ['organization_id' => $project->organization_id]);

        return back()->with('success', __('Site added.'));
    }

    private function clients()
    {
        return Party::where('type', 'client')->orderBy('name')->get();
    }

    private function validated(Request $request, ?Project $project = null): array
    {
        $organizationId = $request->user()->organization_id;

        $data = $request->validate([
            'deduction_order' => ['required', Rule::in(array_keys(config('buildos.deduction_orders')))],
            'code' => [
                'required', 'string', 'max:32', 'alpha_dash',
                Rule::unique('projects', 'code')->where('organization_id', $organizationId)->ignore($project?->id),
            ],
            'name' => ['required', 'string', 'max:160'],
            'client_id' => ['nullable', org_exists('parties')],
            'location' => ['nullable', 'string', 'max:160'],
            'state' => ['nullable', 'string', 'max:60'],
            'contract_value' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'retention_pct' => ['required', 'numeric', 'between:0,100'],
            'tds_pct' => ['required', 'numeric', 'between:0,100'],
            'gst_pct' => ['required', 'numeric', 'between:0,100'],
            'advance_amount' => ['required', 'numeric', 'min:0'],
            'advance_recovery_pct' => ['required', 'numeric', 'between:0,100'],
            'measurement_tolerance_pct' => ['required', 'numeric', 'between:0,100'],
            'deduction_basis' => ['required', Rule::in(['gross', 'running'])],
        ]);

        return [...$data, 'deduction_order' => explode(',', $data['deduction_order'])];
    }
}
