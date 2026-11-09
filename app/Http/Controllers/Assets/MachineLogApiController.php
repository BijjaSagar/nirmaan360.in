<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Http\Presenters\MachineLogPresenter;
use App\Models\Asset;
use App\Models\MachineLog;
use App\Services\Assets\PlantLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mobile endpoints for the daily machine log. Posting the same client_uuid twice returns the first log. */
class MachineLogApiController extends Controller
{
    public function __construct(private PlantLogService $service) {}

    /** Working assets, to pick a machine from. */
    public function assets(): JsonResponse
    {
        return response()->json(
            Asset::where('status', '!=', 'retired')->orderBy('code')->paginate(100)->through(MachineLogPresenter::asset(...))
        );
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return response()->json(
            MachineLog::query()
                ->when($data['asset_id'] ?? null, fn ($query, $id) => $query->where('asset_id', $id))
                ->when($data['project_id'] ?? null, fn ($query, $id) => $query->where('project_id', $id))
                ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('log_date', '>=', $date))
                ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('log_date', '<=', $date))
                ->latest('log_date')->latest('id')
                ->paginate(50)->withQueryString()
                ->through(MachineLogPresenter::log(...))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $log = $this->service->record($request->validate($this->service->logRules($request->user())), $request->user());

        return response()->json(['data' => MachineLogPresenter::log($log)], $log->wasRecentlyCreated ? 201 : 200);
    }
}
