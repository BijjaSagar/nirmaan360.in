<?php

namespace App\Http\Controllers;

use App\Exceptions\ImmutableRecordException;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Services\ApprovalService;
use App\Services\MeasurementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MeasurementController extends Controller
{
    public function __construct(private MeasurementService $service) {}

    public function index(Request $request, Project $project): View
    {
        $status = $request->query('status');

        return view('measurements.index', [
            'project' => $project,
            'entries' => $project->measurements()->with('boqItem', 'creator')
                ->when($status, fn ($q) => $q->where('status', $status))
                ->latest('id')->paginate(30)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function create(Project $project): View
    {
        return view('measurements.form', [
            'project' => $project,
            'entry' => new MeasurementEntry(['measured_on' => now(), 'nos' => 1]),
            'boqItems' => $project->boqItems()->orderBy('sort')->orderBy('id')->get(),
            'workOrders' => $project->workOrders()->with('party')->get(),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $entry = $this->service->create($project, $this->validated($request, $project), $request->user());

        return redirect()->route('measurements.show', $entry)->with('success', __('Measurement saved as draft.'));
    }

    public function show(MeasurementEntry $measurement, ApprovalService $approvals): View
    {
        $measurement->load('boqItem', 'creator', 'workOrder', 'reversalOf', 'reversal', 'approvals.steps.user');

        return view('measurements.show', [
            'entry' => $measurement,
            'project' => $measurement->project,
            'levels' => $approvals->levelsFor('measurement', $measurement->organization_id),
        ]);
    }

    public function edit(MeasurementEntry $measurement): View
    {
        $this->assertEditable($measurement);

        return view('measurements.form', [
            'project' => $measurement->project,
            'entry' => $measurement,
            'boqItems' => $measurement->project->boqItems()->orderBy('sort')->orderBy('id')->get(),
            'workOrders' => $measurement->project->workOrders()->with('party')->get(),
            'clientUuid' => null,
        ]);
    }

    public function update(Request $request, MeasurementEntry $measurement): RedirectResponse
    {
        $this->assertEditable($measurement);

        $data = $this->validated($request, $measurement->project);
        unset($data['client_uuid']);
        $this->service->update($measurement, $data);

        return redirect()->route('measurements.show', $measurement)->with('success', __('Measurement updated.'));
    }

    public function destroy(MeasurementEntry $measurement): RedirectResponse
    {
        $this->assertEditable($measurement);

        $project = $measurement->project;
        $measurement->delete();

        return redirect()->route('measurements.index', $project)->with('success', __('Draft deleted.'));
    }

    public function submit(Request $request, MeasurementEntry $measurement): RedirectResponse
    {
        $this->service->submit($measurement, $request->user());

        return back()->with('success', __('Sent for approval.'));
    }

    public function reverse(Request $request, MeasurementEntry $measurement): RedirectResponse
    {
        $data = $request->validate(['remarks' => ['required', 'string', 'max:500']]);

        $reversal = $this->service->reverse($measurement, $request->user(), $data['remarks']);

        return redirect()->route('measurements.show', $reversal)->with('success', __('Reversal entry created. Submit it for approval.'));
    }

    private function assertEditable(MeasurementEntry $entry): void
    {
        if ($entry->status === 'APPROVED') {
            throw new ImmutableRecordException;
        }

        abort_unless($entry->isEditable(), 409, __('This entry is under review and cannot be changed.'));
    }

    private function validated(Request $request, Project $project): array
    {
        return [...$request->validate($this->service->rules($project)), 'is_deduction' => $request->boolean('is_deduction')];
    }
}
