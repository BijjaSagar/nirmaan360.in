<?php

namespace App\Http\Controllers\SiteProgress;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Services\SiteProgress\ProgressEntryService;
use App\Services\SiteProgress\ProgressPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Daily quantity entries, with corrections and the closing of pending remarks. */
class EntryController extends Controller
{
    public function __construct(private ProgressEntryService $service, private ProgressPhotoService $photos) {}

    public function index(Request $request, Project $project): View
    {
        $request->validate([
            'boq_item_id' => ['nullable', 'integer'],
            'project_floor_id' => ['nullable', 'integer'],
        ]);

        $entries = ProgressEntry::where('project_id', $project->id)
            ->when($request->filled('boq_item_id'), fn ($query) => $query->where('boq_item_id', $request->integer('boq_item_id')))
            ->when($request->filled('project_floor_id'), fn ($query) => $query->where('project_floor_id', $request->integer('project_floor_id')))
            ->with([
                'boqItem' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'description', 'unit']),
                'floor:id,name,parent_id', 'floor.parent:id,name', 'enteredBy:id,name', 'photos',
                'corrections.enteredBy:id,name',
            ])
            ->withCount('resolutions')
            ->latest('id')->paginate(25)->withQueryString();

        return view('modules.site_progress.entries', [
            'project' => $project,
            'entries' => $entries,
            'nets' => $this->service->netQuantities($entries->getCollection()),
            'items' => BoqItem::where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get(['id', 'code', 'description']),
            'floorOptions' => ProgressController::floorOptions($project),
            'filters' => $request->only('boq_item_id', 'project_floor_id'),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            ...$this->service->rules($project),
            'photos' => ['nullable', 'array', 'max:'.ProgressPhotoService::MAX_PER_RECORD],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $entry = $this->service->record($project, $data, $request->user());

        if ($entry->wasRecentlyCreated && $request->hasFile('photos')) {
            $this->photos->attachMany($entry, $request->file('photos'), $request->user());
        }

        // The next entry is usually on the same floor.
        $request->session()->put('site_progress.floor.'.$project->id, $entry->project_floor_id);

        $response = back()->with('success', $entry->is_pending ? __('Saved as pending.') : __('Progress saved.'));

        return $this->service->exceedsBoq($entry)
            ? $response->with('site_progress_warning', [__('More has been done on this item than the BOQ quantity. Check the entries if this is not expected.')])
            : $response;
    }

    public function correct(Request $request, ProgressEntry $progressEntry): RedirectResponse
    {
        $data = $request->validate($this->service->correctionRules());

        $this->service->correct($progressEntry, $data['qty_done'], $data['remark'], $request->user(), $data['client_uuid'] ?? null);

        return back()->with('success', __('Correction added. The first entry stays in the history.'));
    }

    public function resolve(Request $request, ProgressEntry $progressEntry): RedirectResponse
    {
        $data = $request->validate(['remark' => ['nullable', 'string', 'max:1000'], 'client_uuid' => ['nullable', 'uuid']]);

        $this->service->resolve($progressEntry, $data['remark'] ?? null, $request->user(), $data['client_uuid'] ?? null);

        return back()->with('success', __('Pending item marked finished.'));
    }
}
