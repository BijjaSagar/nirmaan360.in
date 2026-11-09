<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\BoqItemPresenter;
use App\Http\Presenters\ProjectPresenter;
use App\Models\BoqItem;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

class ProjectApiController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Project::whereIn('status', ['planning', 'active'])->orderBy('name')->get()->map(ProjectPresenter::present(...)),
        ]);
    }

    public function boq(Project $project): JsonResponse
    {
        return response()->json([
            'data' => BoqItem::withLedger()->with('section')->where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get()
                ->map(BoqItemPresenter::present(...)),
        ]);
    }
}
