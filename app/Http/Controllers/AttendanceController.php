<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Project;
use App\Models\Worker;
use App\Services\AttendanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? now()->toDateString();

        return view('attendance.index', [
            'project' => $project,
            'date' => Date::parse($date),
            'workers' => Worker::where('is_active', true)->orderBy('trade')->orderBy('name')->get(),
            'marked' => Attendance::where('project_id', $project->id)->whereDate('attendance_date', $date)->get()->keyBy('worker_id'),
        ]);
    }

    public function store(Request $request, Project $project, AttendanceService $service): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'attendance' => ['required', 'array'],
            'attendance.*.status' => ['nullable', Rule::in(config('buildos.attendance_statuses'))],
            'attendance.*.ot_hours' => ['nullable', 'numeric', 'min:0', 'max:16'],
        ]);

        $marks = collect($data['attendance'])->map(fn ($row, $workerId) => [...$row, 'worker_id' => $workerId])->values()->all();
        $service->markDay($project, $data['date'], $marks, $request->user());

        return redirect()->route('attendance.index', ['project' => $project, 'date' => $data['date']])->with('success', __('Attendance saved.'));
    }
}
