<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AttendancePresenter;
use App\Models\Attendance;
use App\Models\Project;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceApiController extends Controller
{
    public function __construct(private AttendanceService $service) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? now()->toDateString();

        return response()->json([
            'date' => Carbon::parse($date)->toDateString(),
            'data' => Attendance::where('project_id', $project->id)->whereDate('attendance_date', $date)->get()
                ->map(AttendancePresenter::present(...)),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'marks' => ['required', 'array', 'max:500'],
            'marks.*.worker_id' => $this->service->workerRule(),
            'marks.*.status' => $this->service->markRules()['status'],
            'marks.*.ot_hours' => $this->service->markRules()['ot_hours'],
            'marks.*.client_uuid' => ['nullable', 'uuid', 'distinct'],
        ]);

        $date = Carbon::parse($data['date'])->toDateString();

        return response()->json([
            'date' => $date,
            'data' => $this->service->markDay($project, $date, array_values($data['marks']), $request->user())
                ->map(AttendancePresenter::present(...)),
        ]);
    }
}
