<?php

namespace App\Http\Controllers\Qhs;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ToolboxTalk;
use App\Services\Qhs\SafetyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ToolboxTalkController extends Controller
{
    public function __construct(private SafetyService $service) {}

    public function index(Project $project): View
    {
        return view('modules.qhs.safety.toolbox', [
            'project' => $project,
            'talks' => ToolboxTalk::where('project_id', $project->id)->latest('talk_date')->latest('id')->paginate(20),
            'sites' => $project->sites,
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->service->holdToolboxTalk($project, $request->validate($this->service->toolboxRules($project)), $request->user());

        return back()->with('success', __('Toolbox talk saved.'));
    }
}
