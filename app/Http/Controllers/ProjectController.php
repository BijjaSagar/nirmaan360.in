<?php

namespace App\Http\Controllers;

use App\Models\BoqItem;
use App\Models\Party;
use App\Models\Project;
use App\Services\ProjectService;
use App\Support\Dec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $service) {}

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
        $this->service->assertWithinPlan($request->user()->organization);

        $project = $this->service->create($this->validated($request), $request->user());

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
        $data = $request->validate($this->service->rules($request->user(), $project));

        return [...$data, 'deduction_order' => explode(',', $data['deduction_order'])];
    }
}
