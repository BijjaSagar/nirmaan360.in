<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Http\Presenters\AttendancePresenter;
use App\Models\AttendanceSelfie;
use App\Models\Project;
use App\Services\Notify\SelfieAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SelfieAttendanceApiController extends Controller
{
    public function __construct(private SelfieAttendanceService $service) {}

    /** The app shows this note, in the user's language, before the camera opens. */
    public function consent(): JsonResponse
    {
        return response()->json(['data' => ['version' => SelfieAttendanceService::CONSENT_VERSION, 'text' => $this->service->consentNote()]]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate($this->service->rules($project));
        $selfie = $this->service->record($project, $data, $request->file('selfie'), $request->user());

        return response()->json([
            'data' => [
                'id' => $selfie->id,
                'client_uuid' => $selfie->client_uuid,
                'worker_id' => $selfie->worker_id,
                'site_id' => $selfie->site_id,
                'attendance_date' => $selfie->attendance_date->toDateString(),
                'distance_m' => $selfie->distance_m,
                'radius_m' => $selfie->radius_m,
                'consent_version' => $selfie->consent_version,
            ],
            'attendance' => AttendancePresenter::present($selfie->attendance),
        ], $selfie->wasRecentlyCreated ? 201 : 200);
    }

    public function photo(AttendanceSelfie $selfie): StreamedResponse
    {
        abort_unless(Storage::exists($selfie->photo_path), 404);

        return Storage::response($selfie->photo_path, null, ['Cache-Control' => 'private, no-store']);
    }
}
