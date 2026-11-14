<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Imports\BoqImportLayouts;
use App\Services\Imports\BoqSheetImport;
use App\Services\Reports\BoqExcelService;
use App\Services\Reports\BoqPdfImport;
use App\Services\Reports\BoqPdfReader;
use App\Services\Reports\Download;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Excel BOQ import and export, next to the CSV in BoqController. The same import form takes a PDF, which goes
 * to a preview first, and any contractor's own sheet (.xlsx, .xls, .ods or .csv, decided by its content),
 * which goes to the column check first. A file in the template's own layout imports straight away, as always.
 */
class BoqExcelController extends Controller
{
    /** Problems shown after an import. The rest are counted, so a huge broken file does not make a huge page. */
    private const SHOWN_ERRORS = 200;

    /** What a BOQ sheet may be called. Its content decides how it is read, so an .xls saved as .xlsx is fine. */
    private const SHEET_EXTENSIONS = ['xlsx', 'xlsm', 'xls', 'ods', 'csv', 'txt', 'tsv'];

    public function __construct(private BoqExcelService $service, private BoqPdfImport $pdf, private BoqSheetImport $sheets, private BoqImportLayouts $layouts) {}

    public function index(Request $request, Project $project): View
    {
        return view('modules.reports.boq-excel', [
            'project' => $project,
            'itemCount' => $project->boqItems()->count(),
            'import' => session('boq_import'),
            'layouts' => $request->user()->can('boq.manage') ? $this->layouts->forOrganization($project->organization_id) : collect(),
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
        if (strtolower((string) $request->file('file')?->getClientOriginalExtension()) === 'pdf') {
            return $this->importPdf($request, $project);
        }

        $request->validate(['file' => ['required', 'file', 'extensions:'.implode(',', self::SHEET_EXTENSIONS), 'max:5120']]);

        $file = $request->file('file');
        // Someone who may not see rates cannot set them either: their file's rates are not written.
        $result = $this->service->importTemplate($project, $file->getRealPath(), Money::visible($request->user()));

        if ($result?->ok()) {
            return redirect()->route('boq.excel.index', $project)
                ->with('success', __(':count BOQ rows imported.', ['count' => $result->rows()]))
                ->with('boq_import', ['created' => $result->created, 'updated' => $result->updated, 'unchanged' => $result->unchanged, 'errors' => [], 'notes' => $result->notes]);
        }

        // Any other layout, or a template-like file with problems, is read by its own headings and shown first.
        $staged = $this->sheets->stage($project, $request->user(), $file->getRealPath(), $file->getClientOriginalName());

        if ($result === null && isset($staged['error'])) {
            return redirect()->route('boq.excel.index', $project)->with('boq_import', [
                'errors' => [['row' => 0, 'field' => null, 'message' => $staged['error']]],
                'more' => 0,
            ]);
        }

        if (isset($staged['token'])) {
            $request->session()->put(BoqSheetImport::SESSION, $staged['token']);
        }

        if ($result === null) {
            return redirect()->route('boq.sheet.preview', $project);
        }

        return redirect()->route('boq.excel.index', $project)->with('boq_import', [
            'errors' => array_slice($result->errors, 0, self::SHOWN_ERRORS),
            'more' => max(0, count($result->errors) - self::SHOWN_ERRORS),
            'sheet_preview' => isset($staged['token']),
        ]);
    }

    /** A PDF is read and shown first. Nothing is written until the preview is confirmed. */
    private function importPdf(Request $request, Project $project): RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'extensions:pdf', 'max:'.(BoqPdfReader::MAX_BYTES / 1024)]]);

        $file = $request->file('file');
        $staged = $this->pdf->stage($project, $request->user(), $file->getRealPath(), $file->getClientOriginalName());

        if (isset($staged['error'])) {
            return redirect()->route('boq.excel.index', $project)->with('boq_import', [
                'errors' => [['row' => 0, 'field' => null, 'message' => $staged['error']]],
                'more' => 0,
                'pdf' => true,
                'scanned' => $staged['scanned'],
            ]);
        }

        $request->session()->put(BoqPdfImport::SESSION, $staged['token']);

        return redirect()->route('boq.pdf.preview', $project);
    }
}
