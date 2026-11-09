<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

class ProjectApiController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Project::whereIn('status', ['planning', 'active'])->orderBy('name')
                ->get(['id', 'code', 'name', 'location', 'status', 'start_date', 'end_date']),
        ]);
    }

    public function boq(Project $project): JsonResponse
    {
        $items = BoqItem::withLedger()->where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get();

        return response()->json([
            'data' => $items->map(fn (BoqItem $item) => [
                'id' => $item->id,
                'code' => $item->code,
                'description' => $item->description,
                'unit' => $item->unit,
                'qty' => $item->qty,
                'rate' => $item->rate,
                'balance_qty' => $item->balanceQty(),
            ]),
        ]);
    }
}
