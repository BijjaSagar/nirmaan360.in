<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ReportSchedule;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportMailGateway;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Saved schedules that email a report. */
class ReportScheduleController extends Controller
{
    public function __construct(private ScheduleService $service, private ReportBuilder $builder) {}

    public function index(Request $request, ReportMailGateway $mail): View
    {
        $user = $request->user();

        return view('modules.reports.schedules', [
            'schedules' => ReportSchedule::with(['project' => fn ($q) => $q->withTrashed(), 'creator'])->latest('id')->get(),
            'available' => $this->builder->availableFor($user, $user->organization),
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'periods' => collect(ReportPeriod::LABELS)->only(ReportPeriod::SCHEDULABLE)->all(),
            'days' => ScheduleService::DAYS,
            'mailConfigured' => $mail->configured(),
            'service' => $this->service,
            // Coming from a report on screen, the form starts with that report's choices.
            'defaults' => $request->only(['report', 'project_id', 'period']),
            'limit' => ScheduleService::MAX_PER_ORGANIZATION,
            'maxRecipients' => ScheduleService::MAX_RECIPIENTS,
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['recipients' => $this->service->recipients((string) $request->input('recipients'))]);

        $this->service->create($request->user(), $request->validate($this->service->rules($request->user())));

        return redirect()->route('reports.schedules.index')->with('success', __('Schedule saved.'));
    }

    public function toggle(ReportSchedule $schedule): RedirectResponse
    {
        $schedule->update(['active' => ! $schedule->active]);

        return back()->with('success', $schedule->active ? __('Schedule resumed.') : __('Schedule paused.'));
    }

    public function destroy(ReportSchedule $schedule): RedirectResponse
    {
        $schedule->delete();

        return back()->with('success', __('Schedule deleted.'));
    }
}
