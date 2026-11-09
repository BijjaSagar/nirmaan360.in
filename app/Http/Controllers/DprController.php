<?php

namespace App\Http\Controllers;

use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\Project;
use App\Services\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DprController extends Controller
{
    public function index(Project $project): View
    {
        return view('dprs.index', [
            'project' => $project,
            'dprs' => $project->dprs()->with('creator', 'site')->withSum('manpower', 'count')->latest('report_date')->latest('id')->paginate(25),
        ]);
    }

    public function create(Project $project): View
    {
        return view('dprs.create', [
            'project' => $project,
            'sites' => $project->sites,
            'boqItems' => $project->boqItems()->orderBy('sort')->orderBy('id')->get(),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'report_date' => ['required', 'date', 'before_or_equal:today'],
            'site_id' => ['nullable', Rule::exists('sites', 'id')->where('project_id', $project->id)],
            'weather' => ['nullable', 'string', 'max:60'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'hindrances' => ['nullable', 'string', 'max:5000'],
            'client_uuid' => ['nullable', 'uuid'],
            'manpower' => ['array'],
            'manpower.*.trade' => ['nullable', 'string', 'max:60'],
            'manpower.*.count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'machinery' => ['array'],
            'machinery.*.name' => ['nullable', 'string', 'max:80'],
            'machinery.*.hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'work' => ['array'],
            'work.*.boq_item_id' => ['nullable', Rule::exists('boq_items', 'id')->where('project_id', $project->id)],
            'work.*.description' => ['nullable', 'string', 'max:255'],
            'work.*.qty' => ['nullable', 'numeric', 'min:0'],
            'photos' => ['array', 'max:10'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        if (! empty($data['client_uuid']) && ($existing = Dpr::where('client_uuid', $data['client_uuid'])->first())) {
            return redirect()->route('dprs.show', $existing);
        }

        $dpr = DB::transaction(function () use ($data, $project, $request) {
            $dpr = Dpr::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'site_id' => $data['site_id'] ?? null,
                'report_date' => $data['report_date'],
                'weather' => $data['weather'] ?? null,
                'summary' => $data['summary'] ?? null,
                'hindrances' => $data['hindrances'] ?? null,
                'client_uuid' => $data['client_uuid'] ?? null,
                'status' => 'DRAFT',
                'created_by' => $request->user()->id,
            ]);

            $this->saveChildren($dpr, $data);

            return $dpr;
        });

        foreach ($request->file('photos', []) as $photo) {
            $dpr->photos()->create([
                'organization_id' => $dpr->organization_id,
                'path' => $photo->store('org-'.$dpr->organization_id.'/dprs/'.$dpr->id),
            ]);
        }

        return redirect()->route('dprs.show', $dpr)->with('success', __('DPR saved as draft.'));
    }

    public function show(Dpr $dpr): View
    {
        return view('dprs.show', [
            'dpr' => $dpr->load('manpower', 'machinery', 'workItems.boqItem', 'photos', 'creator', 'site', 'approvals.steps.user'),
            'project' => $dpr->project,
        ]);
    }

    public function submit(Request $request, Dpr $dpr, ApprovalService $approvals): RedirectResponse
    {
        $approvals->submit($dpr, $request->user());

        return back()->with('success', __('DPR submitted for approval.'));
    }

    public function photo(DprPhoto $photo): StreamedResponse
    {
        return Storage::response($photo->path);
    }

    private function saveChildren(Dpr $dpr, array $data): void
    {
        $base = ['organization_id' => $dpr->organization_id];

        foreach ($data['manpower'] ?? [] as $row) {
            if (filled($row['trade'] ?? null) && (int) ($row['count'] ?? 0) > 0) {
                $dpr->manpower()->create([...$base, 'trade' => $row['trade'], 'count' => (int) $row['count']]);
            }
        }

        foreach ($data['machinery'] ?? [] as $row) {
            if (filled($row['name'] ?? null)) {
                $dpr->machinery()->create([...$base, 'name' => $row['name'], 'hours' => $row['hours'] ?? 0]);
            }
        }

        foreach ($data['work'] ?? [] as $row) {
            if (filled($row['boq_item_id'] ?? null) || filled($row['description'] ?? null)) {
                $dpr->workItems()->create([
                    ...$base,
                    'boq_item_id' => $row['boq_item_id'] ?? null,
                    'description' => $row['description'] ?? null,
                    'qty' => $row['qty'] ?? 0,
                ]);
            }
        }
    }
}
