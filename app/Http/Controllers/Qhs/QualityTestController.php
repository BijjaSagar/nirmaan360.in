<?php

namespace App\Http\Controllers\Qhs;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\QualityTest;
use App\Models\QualityTestResult;
use App\Services\Qhs\ConcreteStrength;
use App\Services\Qhs\QualityTestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class QualityTestController extends Controller
{
    public function __construct(private QualityTestService $service) {}

    public function index(Request $request, Project $project): View
    {
        $type = $request->query('type');

        $tests = QualityTest::with('results')
            ->where('project_id', $project->id)
            ->when($type, fn ($query, $value) => $query->where('test_type', $value))
            ->latest('cast_date')->latest('id')
            ->paginate(25)->withQueryString();

        return view('modules.qhs.tests.index', [
            'project' => $project,
            'tests' => $tests,
            'evaluations' => $tests->mapWithKeys(fn (QualityTest $test) => [$test->id => ConcreteStrength::evaluate($test)]),
            'type' => $type,
            'dueCount' => QualityTestResult::whereHas('test', fn ($query) => $query->where('project_id', $project->id))
                ->whereNull('strength')->whereDate('due_on', '<=', today())->count(),
            'sites' => $project->sites,
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $test = $this->service->create($project, $request->validate($this->service->rules($project)), $request->user());

        return redirect()->route('quality-tests.show', $test)->with('success', __('Test registered.'));
    }

    public function show(QualityTest $test): View
    {
        $test->load('project', 'site', 'creator', 'results.test', 'attachments.uploader');

        return view('modules.qhs.tests.show', [
            'test' => $test,
            'project' => $test->project,
            'evaluation' => ConcreteStrength::evaluate($test),
        ]);
    }

    public function record(Request $request, QualityTestResult $result): RedirectResponse
    {
        $this->service->recordResult($result, $request->validate($this->service->resultRules()), $request->user());

        return back()->with('success', __('Result saved.'));
    }

    public function addSpecimen(Request $request, QualityTest $test): RedirectResponse
    {
        $this->service->addSpecimen($test, (int) $request->validate($this->service->specimenRules())['age_days']);

        return back()->with('success', __('Specimen added.'));
    }
}
