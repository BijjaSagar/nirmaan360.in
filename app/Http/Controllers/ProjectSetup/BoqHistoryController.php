<?php

namespace App\Http\Controllers\ProjectSetup;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The BOQ history, newest first. Rates and valuations in it are money, shown only with money.view. */
class BoqHistoryController extends Controller
{
    public function __invoke(Request $request, Project $project): View
    {
        $filters = $request->validate([
            'item' => ['nullable', 'integer'],
            'batch' => ['nullable', 'uuid'],
        ]);

        $revisions = BoqRevision::with('user')
            ->where('project_id', $project->id)
            ->when($filters['item'] ?? null, fn ($query, $item) => $query->where('boq_item_id', $item))
            ->when($filters['batch'] ?? null, fn ($query, $batch) => $query->where('batch_uuid', $batch))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('modules.project_setup.history', [
            'project' => $project,
            'revisions' => $revisions,
            'item' => filled($filters['item'] ?? null) ? BoqItem::withTrashed()->where('project_id', $project->id)->find($filters['item']) : null,
            'filtered' => filled($filters['item'] ?? null) || filled($filters['batch'] ?? null),
            'showMoney' => $request->user()->can('money.view'),
        ]);
    }
}
