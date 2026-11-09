<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSelfie;
use App\Models\Project;
use App\Models\Worker;
use App\Services\Notify\SelfieAttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SelfieAttendanceController extends Controller
{
    public function __construct(private SelfieAttendanceService $service) {}

    public function index(Project $project): View
    {
        return view('modules.notify.selfie', [
            'project' => $project,
            'sites' => $project->sites()->whereNotNull('lat')->whereNotNull('lng')->orderBy('name')->get(),
            'workers' => Worker::where('is_active', true)->orderBy('name')->get(),
            'selfies' => AttendanceSelfie::with('worker', 'site')->where('project_id', $project->id)->whereDate('attendance_date', now()->toDateString())->latest('id')->get(),
            'consentNote' => $this->service->consentNote(),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate($this->service->rules($project));
        $this->service->record($project, $data, $request->file('selfie'), $request->user());

        return redirect()->route('notify.selfie.index', $project)->with('success', __('Attendance marked from the selfie.'));
    }

    public function photo(AttendanceSelfie $selfie): StreamedResponse
    {
        abort_unless(Storage::exists($selfie->photo_path), 404);

        return Storage::response($selfie->photo_path, null, ['Cache-Control' => 'private, no-store']);
    }

    public function destroy(AttendanceSelfie $selfie): RedirectResponse
    {
        $this->service->delete($selfie);

        return back()->with('success', __('Selfie deleted. The attendance mark is unchanged.'));
    }
}
