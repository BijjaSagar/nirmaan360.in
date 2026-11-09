<?php

namespace App\Http\Controllers\Drawings;

use App\Http\Controllers\Controller;
use App\Models\BbsBar;
use App\Models\BbsSchedule;
use App\Models\Drawing;
use App\Models\Project;
use App\Services\Drawings\BbsCalculator;
use App\Services\Drawings\BbsExporter;
use App\Services\Drawings\BbsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BbsController extends Controller
{
    public function __construct(private BbsService $service) {}

    public function index(Project $project): View
    {
        return view('modules.drawings.bbs.index', [
            'project' => $project,
            'schedules' => BbsSchedule::where('project_id', $project->id)->with('drawing')->withCount('bars')->latest('id')->paginate(25),
            'drawings' => $this->drawings($project),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $schedule = $this->service->createSchedule($project, $request->validate($this->service->scheduleRules($project)), $request->user());

        return redirect()->route('drawings.bbs.show', $schedule)->with('success', __('Schedule created.'));
    }

    public function show(BbsSchedule $schedule): View
    {
        return view('modules.drawings.bbs.show', [
            'schedule' => $schedule->load('bars', 'drawing'),
            'project' => $schedule->project,
            'summary' => $this->service->summary($schedule),
            'shapes' => BbsCalculator::shapes(),
            'steels' => BbsCalculator::steelTypes(),
            'sizes' => BbsCalculator::SIZES,
            'drawings' => $this->drawings($schedule->project),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function update(Request $request, BbsSchedule $schedule): RedirectResponse
    {
        $this->service->updateSchedule($schedule, $request->validate($this->service->scheduleRules($schedule->project)));

        return back()->with('success', __('Schedule updated.'));
    }

    public function destroy(BbsSchedule $schedule): RedirectResponse
    {
        $project = $schedule->project;
        $this->service->deleteSchedule($schedule);

        return redirect()->route('drawings.bbs.index', $project)->with('success', __('Schedule removed.'));
    }

    public function storeBar(Request $request, BbsSchedule $schedule): RedirectResponse
    {
        $this->service->addBar($schedule, $request->validate($this->service->barRules()));

        return back()->with('success', __('Bar added.'));
    }

    public function updateBar(Request $request, BbsBar $bar): RedirectResponse
    {
        $this->service->updateBar($bar, $request->validate($this->service->barRules()));

        return back()->with('success', __('Bar updated.'));
    }

    public function destroyBar(BbsBar $bar): RedirectResponse
    {
        $this->service->deleteBar($bar);

        return back()->with('success', __('Bar removed.'));
    }

    public function export(BbsSchedule $schedule, BbsExporter $exporter): StreamedResponse
    {
        $name = Str::slug($schedule->project->code.'-'.$schedule->number).'.xlsx';
        $book = $exporter->spreadsheet($schedule->load('project'));

        return response()->streamDownload(
            fn () => (new Xlsx($book))->save('php://output'),
            $name,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function drawings(Project $project)
    {
        return Drawing::where('project_id', $project->id)->orderBy('number')->get(['id', 'number', 'title']);
    }
}
