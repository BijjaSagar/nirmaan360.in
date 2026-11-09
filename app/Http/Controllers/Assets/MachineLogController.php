<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\MachineLog;
use App\Models\Project;
use App\Services\Assets\PlantLogService;
use App\Support\Dec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MachineLogController extends Controller
{
    use RedirectsToAsset;

    public function __construct(private PlantLogService $service) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'asset_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $data['from'] ?? today()->subDays(6)->toDateString();
        $to = $data['to'] ?? today()->toDateString();

        $query = MachineLog::query()
            ->when($data['asset_id'] ?? null, fn ($q, $id) => $q->where('asset_id', $id))
            ->whereDate('log_date', '>=', $from)
            ->whereDate('log_date', '<=', $to);

        $totals = (clone $query)->get(['hours', 'km', 'breakdown_hours']);

        return view('modules.assets.logs', [
            'logs' => $query->with('asset', 'project', 'creator')->latest('log_date')->latest('id')->paginate(30)->withQueryString(),
            'assets' => Asset::where('status', '!=', 'retired')->orderBy('code')->get(['id', 'code', 'name', 'meter_unit', 'project_id']),
            'projects' => Project::orderBy('name')->get(['id', 'name']),
            'filters' => ['asset_id' => $data['asset_id'] ?? null, 'from' => $from, 'to' => $to],
            'totals' => [
                'hours' => $this->sum($totals, 'hours'),
                'km' => $this->sum($totals, 'km'),
                'breakdown_hours' => $this->sum($totals, 'breakdown_hours'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $log = $this->service->record($request->validate($this->service->logRules($request->user())), $request->user());

        return $this->backToAsset($request, $log->asset_id, __('Log saved.'));
    }

    public function destroy(Request $request, MachineLog $log): RedirectResponse
    {
        $this->service->deleteLog($log);

        return $this->backToAsset($request, $log->asset_id, __('Log deleted.'));
    }

    /** Display total only. Exact because it is added as decimals. */
    private function sum($rows, string $column): string
    {
        return Dec::qty($rows->reduce(fn ($carry, $row) => $carry->plus(Dec::of($row->{$column})), Dec::of(0)));
    }
}
