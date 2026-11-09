<?php

namespace App\Http\Controllers\SiteProgress;

use App\Http\Controllers\Controller;
use App\Http\Presenters\SiteProgressPresenter;
use App\Models\ProgressEntry;
use App\Models\ProgressPhoto;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Services\SiteProgress\ProgressEntryService;
use App\Services\SiteProgress\ProgressPhotoService;
use App\Services\SiteProgress\ProgressReport;
use App\Support\ClientTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Mobile API for floors, quantities and progress entries. Quantities and percentages only: no rate, amount or budget. */
class ProgressApiController extends Controller
{
    private const WITH = [
        'enteredBy:id,name',
        'photos',
    ];

    public function __construct(private ProgressEntryService $entries, private ProgressPhotoService $photos, private ProgressReport $report) {}

    public function floors(Project $project): JsonResponse
    {
        return response()->json(['data' => array_map(SiteProgressPresenter::floor(...), $this->report->floors($project))]);
    }

    public function floor(ProjectFloor $floor): JsonResponse
    {
        return response()->json(['data' => SiteProgressPresenter::floorDetail($this->report->floorDetail($floor))]);
    }

    /** BOQ lines for the entry screen: code, description, unit, BOQ quantity and what is done. */
    public function items(Project $project): JsonResponse
    {
        return response()->json(['data' => $this->report->items($project)->map(SiteProgressPresenter::item(...))->values()->all()]);
    }

    public function summary(Project $project): JsonResponse
    {
        return response()->json(['data' => $this->report->summary($project)]);
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'project_floor_id' => ['nullable', 'integer'],
            'boq_item_id' => ['nullable', 'integer'],
            'since' => ['nullable', 'date'],
        ]);

        $since = ClientTime::parse($request->query('since'));

        $page = ProgressEntry::where('project_id', $project->id)
            ->when($request->filled('project_floor_id'), fn ($query) => $query->where('project_floor_id', $request->integer('project_floor_id')))
            ->when($request->filled('boq_item_id'), fn ($query) => $query->where('boq_item_id', $request->integer('boq_item_id')))
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->with(self::WITH)
            ->orderBy('id')
            ->paginate(100);

        return response()->json($page->through(SiteProgressPresenter::entry(...)));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $entry = $this->entries->record($project, $request->validate($this->entries->rules($project)), $request->user());

        return $this->entryResponse($entry->load(self::WITH), $entry->wasRecentlyCreated ? 201 : 200);
    }

    /** Set what an entry should have been. The first entry stays; a new one holds the difference. */
    public function correct(Request $request, ProgressEntry $progressEntry): JsonResponse
    {
        $data = $request->validate($this->entries->correctionRules());
        $correction = $this->entries->correct($progressEntry, $data['qty_done'], $data['remark'], $request->user(), $data['client_uuid'] ?? null);

        return $this->entryResponse($correction->load(self::WITH), $correction->wasRecentlyCreated ? 201 : 200);
    }

    public function resolve(Request $request, ProgressEntry $progressEntry): JsonResponse
    {
        $data = $request->validate(['remark' => ['nullable', 'string', 'max:1000'], 'client_uuid' => ['nullable', 'uuid']]);
        $resolution = $this->entries->resolve($progressEntry, $data['remark'] ?? null, $request->user(), $data['client_uuid'] ?? null);

        return $this->entryResponse($resolution->load(self::WITH), $resolution->wasRecentlyCreated ? 201 : 200);
    }

    public function pending(Project $project): JsonResponse
    {
        return response()->json(['data' => $this->report->pending($project)->map(SiteProgressPresenter::pendingEntry(...))->values()->all()]);
    }

    public function storePhoto(Request $request, ProgressEntry $progressEntry): JsonResponse
    {
        $data = $request->validate($this->photos->rules());

        $photo = $this->photos->attach($progressEntry, $request->file('photo'), $request->user(), [
            'client_uuid' => $data['client_uuid'] ?? null,
            'caption' => $data['caption'] ?? null,
            'taken_at' => ClientTime::parse($data['taken_at'] ?? null),
        ]);

        return response()->json(['data' => SiteProgressPresenter::photo($photo)], $photo->wasRecentlyCreated ? 201 : 200);
    }

    public function photo(ProgressPhoto $progressPhoto): StreamedResponse
    {
        abort_unless(Storage::exists($progressPhoto->path), 404);

        return Storage::response($progressPhoto->path);
    }

    private function entryResponse(ProgressEntry $entry, int $status): JsonResponse
    {
        return response()->json([
            'data' => SiteProgressPresenter::entry($entry),
            // Where the BOQ line stands after this entry, so the phone can show it at once.
            'item' => $this->report->itemStanding($entry->boq_item_id),
            'warnings' => $this->entries->exceedsBoq($entry) ? [['code' => 'over_boq', 'message' => __('More has been done on this item than the BOQ quantity. Check the entries if this is not expected.')]] : [],
        ], $status);
    }
}
