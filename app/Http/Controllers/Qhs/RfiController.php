<?php

namespace App\Http\Controllers\Qhs;

use App\Http\Controllers\Controller;
use App\Http\Presenters\ApprovalPresenter;
use App\Models\Project;
use App\Models\Rfi;
use App\Services\Qhs\RfiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RfiController extends Controller
{
    public function __construct(private RfiService $service) {}

    public function index(Request $request, Project $project): View
    {
        $status = $request->query('status');

        return view('modules.qhs.rfis.index', [
            'project' => $project,
            'rfis' => Rfi::with('raiser')->where('project_id', $project->id)
                ->when($status, fn ($query, $value) => $query->where('status', $value))
                ->latest('seq')->paginate(25)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function create(Project $project): View
    {
        return view('modules.qhs.rfis.form', ['project' => $project, 'rfi' => new Rfi(['priority' => 'medium']), 'clientUuid' => (string) Str::uuid()]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $rfi = $this->service->create($project, $request->validate($this->service->rules()), $request->user());

        return redirect()->route('rfis.show', $rfi)->with('success', __('RFI saved as draft.'));
    }

    public function show(Rfi $rfi): View
    {
        return view('modules.qhs.rfis.show', [
            'rfi' => $rfi->load('project', 'raiser', 'approvals.steps.user', 'attachments.uploader'),
            'project' => $rfi->project,
            'returnRemark' => ApprovalPresenter::returnRemark($rfi),
        ]);
    }

    public function edit(Rfi $rfi): View
    {
        abort_unless($rfi->isEditable(), 409, __('Only a draft or returned RFI can be changed.'));

        return view('modules.qhs.rfis.form', ['project' => $rfi->project, 'rfi' => $rfi, 'clientUuid' => null]);
    }

    public function update(Request $request, Rfi $rfi): RedirectResponse
    {
        $this->service->update($rfi, $request->validate($this->service->rules()));

        return redirect()->route('rfis.show', $rfi)->with('success', __('RFI updated.'));
    }

    public function submit(Request $request, Rfi $rfi): RedirectResponse
    {
        $this->service->submit($rfi, $request->user());

        return back()->with('success', __('RFI submitted for approval.'));
    }

    public function respond(Request $request, Rfi $rfi): RedirectResponse
    {
        $this->service->respond($rfi, $request->validate($this->service->responseRules()));

        return back()->with('success', __('Answer recorded.'));
    }

    public function close(Request $request, Rfi $rfi): RedirectResponse
    {
        $this->service->close($rfi, $request->user());

        return back()->with('success', __('RFI closed.'));
    }
}
