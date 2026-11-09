<?php

namespace App\Http\Controllers\SiteProgress;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Services\SiteProgress\MepTracker;
use App\Services\SiteProgress\ProgressPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The plumbing and electrical stage matrix, and the checklist to read before finishing starts. */
class StageController extends Controller
{
    public function __construct(private MepTracker $tracker, private ProgressPhotoService $photos) {}

    public function index(Request $request, Project $project): View
    {
        $trade = array_key_exists((string) $request->query('trade'), $this->tracker->trades()) ? $request->query('trade') : array_key_first($this->tracker->trades());

        return view('modules.site_progress.stages', [
            'project' => $project,
            'trade' => $trade,
            'trades' => $this->tracker->trades(),
            'statuses' => $this->tracker->statuses(),
            'matrix' => $this->tracker->matrix($project),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            ...$this->tracker->rules($project),
            'photos' => ['nullable', 'array', 'max:'.ProgressPhotoService::MAX_PER_RECORD],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $result = $this->tracker->update($project, $data, $request->user());

        if (! $result['repeat'] && $request->hasFile('photos')) {
            $this->photos->attachMany($result['cell'], $request->file('photos'), $request->user());
        }

        $response = back()->with('success', __('Stage updated.'));

        return $result['warnings'] === []
            ? $response
            : $response->with('site_progress_warning', array_column($result['warnings'], 'message'));
    }

    public function checklist(Request $request, ProjectFloor $floor): View
    {
        $work = array_key_exists((string) $request->query('for'), config('modules.site_progress.finishing_works')) ? $request->query('for') : 'plaster';

        return view('modules.site_progress.checklist', [
            'project' => $floor->project,
            'floor' => $floor,
            'work' => $work,
            'trades' => $this->tracker->trades(),
            'statuses' => $this->tracker->statuses(),
            'checklist' => $this->tracker->preFinishing($floor),
        ]);
    }
}
