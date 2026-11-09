<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Party;
use App\Models\Project;
use App\Models\Site;
use App\Services\Assets\AssetService;
use App\Services\Assets\MaintenanceService;
use App\Services\Assets\QrCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AssetController extends Controller
{
    public function __construct(private AssetService $service, private MaintenanceService $maintenance) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $assets = Asset::with('project')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.mb_strtolower($search).'%';
                $query->where(fn ($inner) => $inner
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(code) like ?', [$like])
                    ->orWhereRaw('lower(registration_no) like ?', [$like]));
            })
            ->when($request->query('ownership'), fn ($query, $value) => $query->where('ownership', $value))
            ->when($request->query('category'), fn ($query, $value) => $query->where('category', $value))
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->when($request->query('project_id'), fn ($query, $value) => $query->where('project_id', $value))
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();

        $schedule = $this->maintenance->schedule();

        return view('modules.assets.index', [
            'assets' => $assets,
            'projects' => Project::orderBy('name')->get(['id', 'name', 'code']),
            'filters' => $request->only(['q', 'ownership', 'category', 'status', 'project_id']),
            'counts' => [
                'total' => Asset::count(),
                'hired' => Asset::where('ownership', 'hired')->where('status', '!=', 'retired')->count(),
                'under_repair' => Asset::where('status', 'under_repair')->count(),
                'service_due' => $schedule->whereIn('status.state', ['overdue', 'due_soon'])->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('modules.assets.form', [
            'asset' => new Asset(['ownership' => 'owned', 'hire_type' => 'dry']),
            'clientUuid' => (string) Str::uuid(),
            ...$this->formData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $asset = $this->service->create($request->validate($this->service->rules($request->user())), $request->user());

        return redirect()->route('assets.show', $asset)->with('success', __('Asset saved.'));
    }

    public function show(Asset $asset): View
    {
        $asset->load('project', 'site', 'vendor');

        return view('modules.assets.show', [
            'asset' => $asset,
            'summary' => $this->service->summary($asset),
            'logs' => $asset->logs()->with('project')->latest('log_date')->latest('id')->take(15)->get(),
            'fuelIssues' => $asset->fuelIssues()->latest('issue_date')->latest('id')->take(15)->get(),
            'breakdowns' => $asset->breakdowns()->orderByRaw("case when status = 'open' then 0 else 1 end")->latest('reported_at')->take(15)->get(),
            'maintenance' => $asset->maintenance()->orderByDesc('is_active')->orderBy('title')->get()
                ->map(fn ($item) => ['item' => $item, 'status' => $this->maintenance->status($item, $asset)]),
            'serviceRecords' => $asset->serviceRecords()->latest('done_on')->take(10)->get(),
            'movements' => $asset->movements()->with('fromProject', 'toProject', 'mover')->take(15)->get(),
            'hireBills' => $asset->hireBills()->latest('id')->take(10)->get(),
            ...$this->formData(),
        ]);
    }

    public function edit(Asset $asset): View
    {
        return view('modules.assets.form', ['asset' => $asset, ...$this->formData()]);
    }

    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $this->service->update($asset, $request->validate($this->service->rules($request->user(), $asset)));

        return redirect()->route('assets.show', $asset)->with('success', __('Asset updated.'));
    }

    /** One printable tag. */
    public function tag(Asset $asset): View
    {
        return view('modules.assets.tag', ['tags' => [$this->tagData($asset)]]);
    }

    /** A sheet of tags for every working asset, or for one project. */
    public function tags(Request $request): View
    {
        $assets = Asset::with('project')
            ->where('status', '!=', 'retired')
            ->when($request->query('project_id'), fn ($query, $value) => $query->where('project_id', $value))
            ->orderBy('code')
            ->take(120)
            ->get();

        return view('modules.assets.tag', ['tags' => $assets->map(fn (Asset $asset) => $this->tagData($asset))->all()]);
    }

    /** What a scanned tag opens. The asset has to belong to the signed-in company. */
    public function scan(string $tag): RedirectResponse
    {
        return redirect()->route('assets.show', Asset::where('tag', $tag)->firstOrFail());
    }

    private function tagData(Asset $asset): array
    {
        return ['asset' => $asset, 'svg' => QrCode::encode($asset->tagUrl())->svg()];
    }

    private function formData(): array
    {
        return [
            'projects' => Project::orderBy('name')->get(['id', 'name', 'code']),
            'sites' => Site::orderBy('name')->get(['id', 'project_id', 'name']),
            'vendors' => Party::whereIn('type', ['vendor', 'subcontractor'])->orderBy('name')->get(['id', 'name']),
        ];
    }
}
