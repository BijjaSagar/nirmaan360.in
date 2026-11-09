<?php

namespace App\Http\Controllers\Workforce;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AttendancePresenter;
use App\Http\Presenters\WorkerPresenter;
use App\Models\Project;
use App\Services\Workforce\QrAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /api/v1/projects/{project}/attendance/scan with the text read from a worker's QR card. */
class QrAttendanceApiController extends Controller
{
    public function store(Request $request, Project $project, QrAttendanceService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'client_uuid' => ['nullable', 'uuid'],
        ]);

        $result = $service->scan($project, $data['code'], $request->user(), $data['client_uuid'] ?? null);

        return response()->json([
            'already_marked' => $result['already_marked'],
            'worker' => WorkerPresenter::present($result['worker']),
            'attendance' => AttendancePresenter::present($result['attendance']),
        ], $result['already_marked'] ? 200 : 201);
    }
}
