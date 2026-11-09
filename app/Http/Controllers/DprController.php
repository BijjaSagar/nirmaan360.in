<?php

namespace App\Http\Controllers;

use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\Project;
use App\Services\DprService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function store(Request $request, Project $project, DprService $service): RedirectResponse
    {
        $data = $request->validate([
            ...$service->rules($project),
            'photos' => ['array', 'max:10'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $dpr = $service->create($project, $data, $request->user());

        if (! $dpr->wasRecentlyCreated) {
            return redirect()->route('dprs.show', $dpr);
        }

        foreach ($request->file('photos', []) as $photo) {
            $service->addPhoto($dpr, $photo);
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

    public function submit(Request $request, Dpr $dpr, DprService $service): RedirectResponse
    {
        $service->submit($dpr, $request->user());

        return back()->with('success', __('DPR submitted for approval.'));
    }

    public function photo(DprPhoto $photo): StreamedResponse
    {
        return Storage::response($photo->path);
    }
}
