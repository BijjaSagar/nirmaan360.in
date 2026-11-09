<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Project;
use App\Services\Workforce\QrAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The scan screen for a tablet or phone at the site gate. The mobile API uses the same service. */
class QrScanController extends Controller
{
    public function show(Project $project): View
    {
        return view('modules.workforce.scan', [
            'project' => $project,
            'recent' => Attendance::with('worker')
                ->where('project_id', $project->id)
                ->where('method', 'qr')
                ->whereDate('attendance_date', now()->toDateString())
                ->latest('id')
                ->take(15)
                ->get(),
        ]);
    }

    public function store(Request $request, Project $project, QrAttendanceService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        $result = $service->scan($project, $data['code'], $request->user(), $data['client_uuid'] ?? null);

        return response()->json([
            'worker' => ['id' => $result['worker']->id, 'name' => $result['worker']->name, 'trade' => $result['worker']->trade],
            'status' => $result['attendance']->status,
            'already_marked' => $result['already_marked'],
        ]);
    }
}
