<?php

namespace App\Http\Controllers;

use App\Models\BoqItem;
use App\Models\Party;
use App\Models\Project;
use App\Services\ProjectImageService;
use App\Services\ProjectService;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $service, private ProjectImageService $images) {}

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
        $this->images->save($project, $request->file('image'), false);

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

        // Everyone sees how far the work has come as a percentage. The rupee figures behind it need money.view.
        $seesMoney = Money::visible();

        return view('projects.show', [
            'project' => $project->load('client', 'sites'),
            'seesMoney' => $seesMoney,
            'boqValue' => $seesMoney ? Dec::money($boqValue) : null,
            'approvedValue' => $seesMoney ? Dec::money($approvedValue) : null,
            'approvedPercent' => app(WorkProgress::class)->percent($approvedValue, $boqValue),
            'itemCount' => $items->count(),
            'openIssues' => $project->issues()->whereIn('status', ['open', 'in_progress'])->count(),
            'dprCount' => $project->dprs()->count(),
            'recentDprs' => $project->dprs()->with('creator')->latest('report_date')->take(5)->get(),
            'bills' => $project->raBills()->latest('id')->take(5)->get(),
            'phases' => $seesMoney ? $this->service->phasesOf($project) : [],
        ]);
    }

    public function edit(Project $project): View
    {
        return view('projects.form', ['project' => $project, 'clients' => $this->clients(), 'phases' => $this->service->phasesOf($project)]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        // Through the service, which keeps a phased project's value equal to its phases.
        $this->service->update($project, [...$this->validated($request, $project), 'status' => $request->validate([
            'status' => ['required', Rule::in(config('buildos.project_statuses'))],
        ])['status']], $request->user());
        $this->images->save($project, $request->file('image'), $request->boolean('remove_image'));

        return redirect()->route('projects.show', $project)->with('success', __('Project updated.'));
    }

    /** Project photos are private: they are read through here, after the company and project checks on the model. */
    public function image(Project $project): StreamedResponse
    {
        abort_unless($project->image_path !== null && Storage::exists($project->image_path), 404);

        // The address changes with the photo, so it can be kept. "private" keeps it out of shared caches.
        return Storage::response($project->image_path, null, ['Cache-Control' => 'private, max-age=604800']);
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
        $data = $request->validate([...$this->service->rules($request->user(), $project), ...$this->images->rules()]);

        return [...Arr::except($data, ['image', 'remove_image']), 'deduction_order' => explode(',', $data['deduction_order'])];
    }
}
