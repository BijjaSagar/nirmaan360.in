<?php

namespace App\Http\Controllers\Drawings;

use App\Http\Controllers\Controller;
use App\Models\DrawingTransmittal;
use App\Models\DrawingVersion;
use App\Models\Party;
use App\Models\Project;
use App\Services\Drawings\TransmittalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TransmittalController extends Controller
{
    public function __construct(private TransmittalService $service) {}

    public function index(Project $project): View
    {
        return view('modules.drawings.transmittals.index', [
            'project' => $project,
            'transmittals' => DrawingTransmittal::where('project_id', $project->id)->withCount('items')->latest('id')->paginate(25),
        ]);
    }

    public function create(Project $project): View
    {
        return view('modules.drawings.transmittals.create', [
            'project' => $project,
            'versions' => DrawingVersion::where('project_id', $project->id)->where('is_current', true)
                ->whereHas('drawing')->with('drawing')->get()->sortBy(fn ($version) => $version->drawing->number)->values(),
            'parties' => Party::orderBy('name')->get(['id', 'name']),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $transmittal = $this->service->issue($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('drawings.transmittals.show', $transmittal)->with('success', __('Transmittal issued.'));
    }

    public function show(DrawingTransmittal $transmittal): View
    {
        return view('modules.drawings.transmittals.show', [
            'transmittal' => $transmittal->load('items.version.drawing', 'issuer'),
            'project' => $transmittal->project,
        ]);
    }

    public function pdf(DrawingTransmittal $transmittal): Response
    {
        return Pdf::loadView('modules.drawings.pdf.transmittal', [
            'transmittal' => $transmittal->load('items.version.drawing', 'issuer', 'project', 'organization'),
        ])->setPaper('a4')->download($transmittal->number.'.pdf');
    }

    public function acknowledge(Request $request, DrawingTransmittal $transmittal): RedirectResponse
    {
        $this->service->acknowledge($transmittal, $request->validate([
            'acknowledged_on' => ['required', 'date'],
            'acknowledged_by' => ['required', 'string', 'max:160'],
        ]));

        return back()->with('success', __('Acknowledgement recorded.'));
    }

    public function cancel(Request $request, DrawingTransmittal $transmittal): RedirectResponse
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);
        $this->service->cancel($transmittal, $data['cancel_reason']);

        return back()->with('success', __('Transmittal cancelled.'));
    }
}
