<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Reports\BoqExcelService;
use App\Services\Reports\Download;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/** Excel BOQ import and export, next to the CSV in BoqController. */
class BoqExcelController extends Controller
{
    /** Problems shown after an import. The rest are counted, so a huge broken file does not make a huge page. */
    private const SHOWN_ERRORS = 200;

    public function __construct(private BoqExcelService $service) {}

    public function index(Project $project): View
    {
        return view('modules.reports.boq-excel', [
            'project' => $project,
            'itemCount' => $project->boqItems()->count(),
            'import' => session('boq_import'),
        ]);
    }

    public function export(Request $request, Project $project): Response
    {
        return Download::file($this->service->export($project, Money::visible($request->user())), 'boq-'.$project->code.'.xlsx', Download::XLSX);
    }

    public function template(): Response
    {
        return Download::file($this->service->template(), 'boq-template.xlsx', Download::XLSX);
    }

    public function import(Request $request, Project $project): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx', 'max:5120']]);

        $result = $this->service->import($project, $request->file('file')->getRealPath());

        if ($result->ok()) {
            return redirect()->route('boq.excel.index', $project)
                ->with('success', __(':count BOQ rows imported.', ['count' => $result->rows()]))
                ->with('boq_import', ['created' => $result->created, 'updated' => $result->updated, 'unchanged' => $result->unchanged, 'errors' => [], 'notes' => $result->notes]);
        }

        return redirect()->route('boq.excel.index', $project)->with('boq_import', [
            'errors' => array_slice($result->errors, 0, self::SHOWN_ERRORS),
            'more' => max(0, count($result->errors) - self::SHOWN_ERRORS),
        ]);
    }
}
