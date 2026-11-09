<?php

namespace App\Http\Controllers\SiteProgress;

use App\Http\Controllers\Controller;
use App\Http\Presenters\SiteProgressPresenter;
use App\Models\MepStageCell;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Services\SiteProgress\MepTracker;
use App\Services\SiteProgress\ProgressPhotoService;
use App\Support\ClientTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mobile API for the plumbing and electrical stage matrix. */
class StageApiController extends Controller
{
    public function __construct(private MepTracker $tracker, private ProgressPhotoService $photos) {}

    /** The stage lists and every row with its cells. */
    public function matrix(Project $project): JsonResponse
    {
        return response()->json([
            'data' => [
                ...SiteProgressPresenter::stages($this->tracker->trades(), $this->tracker->statuses()),
                ...$this->tracker->matrix($project),
            ],
        ]);
    }

    /** Set a stage's status. A warning comes back when an earlier stage on the same row is pending; the change is still saved. */
    public function update(Request $request, Project $project): JsonResponse
    {
        $result = $this->tracker->update($project, $request->validate($this->tracker->rules($project)), $request->user());

        return response()->json([
            'data' => SiteProgressPresenter::cell($result['cell']),
            'warnings' => $result['warnings'],
            // False when the change was older than what the server already holds.
            'applied' => $result['event']->applied,
        ], $result['repeat'] ? 200 : 201);
    }

    public function storePhoto(Request $request, MepStageCell $cell): JsonResponse
    {
        $data = $request->validate($this->photos->rules());

        $photo = $this->photos->attach($cell, $request->file('photo'), $request->user(), [
            'client_uuid' => $data['client_uuid'] ?? null,
            'caption' => $data['caption'] ?? null,
            'taken_at' => ClientTime::parse($data['taken_at'] ?? null),
        ]);

        return response()->json(['data' => SiteProgressPresenter::photo($photo)], $photo->wasRecentlyCreated ? 201 : 200);
    }

    /** Pending plumbing and electrical items on a floor and its flats, to read before plaster, POP or paint starts. */
    public function beforeFinishing(ProjectFloor $floor): JsonResponse
    {
        return response()->json(['data' => $this->tracker->preFinishing($floor)]);
    }
}
