<?php

namespace App\Http\Controllers\SiteProgress;

use App\Http\Controllers\Controller;
use App\Http\Presenters\SiteProgressPresenter;
use App\Models\BoqItem;
use App\Models\ProgressEntry;
use App\Models\ProgressPhoto;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Services\SiteProgress\FloorService;
use App\Services\SiteProgress\ProgressEntryService;
use App\Services\SiteProgress\ProgressPhotoService;
use App\Services\SiteProgress\ProgressReport;
use App\Support\ClientTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Mobile API for floors, quantities and progress entries. Quantities and percentages only: no rate, amount or budget. */
class ProgressApiController extends Controller
{
    private const WITH = [
        'enteredBy:id,name',
        'photos',
    ];

    public function __construct(
        private ProgressEntryService $entries,
        private ProgressPhotoService $photos,
        private ProgressReport $report,
        private FloorService $floorService,
    ) {}

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

    /*
     * Setting up floors and segments from the phone (progress.manage), with the web's rules from FloorService.
     * Removing a floor is for the company owner only. Online only: each answer is what the server now holds.
     */

    /** Add a floor, or a segment (flat) under a floor with parent_id. A repeated client_uuid returns the first one. */
    public function storeFloor(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([...$this->floorService->rules($project), 'client_uuid' => ['nullable', 'uuid']]);
        $floor = $this->floorService->create($project, $data);

        return response()->json(['data' => SiteProgressPresenter::floorSetup($floor)], $floor->wasRecentlyCreated ? 201 : 200);
    }

    /** Rename a floor, or move it: only the fields sent change. parent_id null makes a segment a floor of its own. */
    public function updateFloor(Request $request, ProjectFloor $floor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            'parent_id' => $this->floorService->rules($floor->project)['parent_id'],
        ]);

        $floor = $this->floorService->update($floor, [
            'name' => $data['name'] ?? $floor->name,
            'parent_id' => array_key_exists('parent_id', $data) ? $data['parent_id'] : $floor->parent_id,
        ]);

        return response()->json(['data' => SiteProgressPresenter::floorSetup($floor)]);
    }

    /** Set what is planned for one BOQ line on this floor. Zero removes the target. Answers with the floor as it now stands. */
    public function floorTarget(Request $request, ProjectFloor $floor): JsonResponse
    {
        $data = $request->validate($this->floorService->targetRules($floor));
        $this->floorService->setTarget($floor, BoqItem::findOrFail($data['boq_item_id']), (string) $data['planned_qty']);

        return response()->json(['data' => SiteProgressPresenter::floorDetail($this->report->floorDetail($floor))]);
    }

    /** Share whole BOQ lines equally across the chosen floors, as the web does. Sending it again gives the same result. */
    public function splitFloors(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate($this->floorService->splitRules($project));
        $itemIds = $request->boolean('all_items')
            ? BoqItem::where('project_id', $project->id)->pluck('id')->all()
            : ($data['boq_item_ids'] ?? []);

        $count = $this->floorService->splitEqually($project, $itemIds, $data['floor_ids']);

        return response()->json(['data' => [
            'count' => $count,
            'floors' => array_map(SiteProgressPresenter::floor(...), $this->report->floors($project)),
        ]]);
    }

    /**
     * Only the company owner removes a floor: the built-in owner role, no custom role, and active. FloorService::delete
     * is the one place a floor is removed, so every protection it holds applies here too.
     */
    public function destroyFloor(Request $request, ProjectFloor $floor): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isOwner() && $user->is_active, 403, __('Only the company owner can remove a floor.'));

        $id = $floor->id;
        $this->floorService->delete($floor);

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    /** The project's time frame, as on the web. Send all three; null clears one. Answers with the new summary. */
    public function timeFrame(Request $request, Project $project): JsonResponse
    {
        $rules = ProgressController::timeFrameRules();

        foreach ($rules as $field => $rule) {
            $rules[$field] = ['present', ...$rule];
        }

        $project->update($request->validate($rules));

        return response()->json(['data' => $this->report->summary($project->refresh())]);
    }

    /** The week that holds a day (today when none is sent): what was done, what is pending, what is under way. */
    public function week(Request $request, Project $project): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date']]);
        $day = $request->filled('date') ? Carbon::parse($request->query('date')) : now();

        return response()->json(['data' => SiteProgressPresenter::week($this->report->week($project, $day))]);
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
