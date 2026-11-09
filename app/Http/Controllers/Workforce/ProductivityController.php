<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\LabourAllocation;
use App\Models\Project;
use App\Services\Workforce\ProductivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductivityController extends Controller
{
    public function __construct(private ProductivityService $service) {}

    public function index(Request $request, Project $project): View
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'date' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $to = Carbon::parse($data['to'] ?? now())->toDateString();
        $from = Carbon::parse($data['from'] ?? Carbon::parse($to)->subDays(29))->toDateString();
        $date = Carbon::parse($data['date'] ?? now())->toDateString();

        return view('modules.workforce.productivity', [
            'project' => $project,
            'from' => $from,
            'to' => $to,
            'date' => $date,
            'report' => $this->service->report($project, $from, $to),
            'roster' => $this->service->dayRoster($project, $date),
            'items' => BoqItem::where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get(['id', 'code', 'description', 'unit']),
            'allocations' => LabourAllocation::with('worker', 'boqItem')->where('project_id', $project->id)->whereDate('work_date', $date)->orderBy('id')->get(),
        ]);
    }

    public function allocate(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'allocations' => ['required', 'array', 'max:500'],
            'allocations.*.boq_item_id' => ['nullable', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'allocations.*.man_days' => ['nullable', 'numeric', 'gt:0', 'max:1'],
        ]);

        // A row counts only when both an activity and man-days are given.
        $rows = collect($data['allocations'])
            ->filter(fn ($row) => filled($row['boq_item_id'] ?? null) && filled($row['man_days'] ?? null))
            ->map(fn ($row, $workerId) => [...$row, 'worker_id' => $workerId])
            ->values()
            ->all();

        $date = Carbon::parse($data['date'])->toDateString();
        $saved = $this->service->allocate($project, $date, $rows, $request->user());

        return redirect()->route('workforce.productivity', ['project' => $project, 'date' => $date])
            ->with('success', $saved->isEmpty() ? __('Nothing to save. Choose an activity and man-days for at least one worker.') : __('Allocations saved: :count', ['count' => $saved->count()]));
    }

    public function destroy(LabourAllocation $allocation): RedirectResponse
    {
        $this->service->remove($allocation);

        return back()->with('success', __('Allocation removed.'));
    }
}
