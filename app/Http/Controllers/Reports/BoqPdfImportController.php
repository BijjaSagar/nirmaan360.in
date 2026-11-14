<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Reports\BoqPdfImport;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The preview and confirmation of a BOQ read from a PDF. The upload itself arrives through
 * BoqExcelController::import, the same form as Excel, which stages the file and sends people here.
 */
class BoqPdfImportController extends Controller
{
    /** Problems shown on the preview. The rest are counted, so a badly read file does not make a huge page. */
    private const SHOWN_ERRORS = 200;

    public function __construct(private BoqPdfImport $import) {}

    public function preview(Request $request, Project $project): View|RedirectResponse
    {
        $token = $request->session()->get(BoqPdfImport::SESSION);
        $entry = $this->import->staged($project, $request->user(), is_string($token) ? $token : null);

        if ($entry === null) {
            return $this->expired($project);
        }

        return view('modules.reports.boq-pdf-preview', [
            'project' => $project,
            'token' => $token,
            'preview' => $this->import->preview($project, $entry),
            'money' => Money::visible($request->user()),
            'shownErrors' => self::SHOWN_ERRORS,
            'languages' => BoqPdfImport::languages(),
        ]);
    }

    public function confirm(Request $request, Project $project): RedirectResponse
    {
        $token = $request->input('token');

        try {
            $result = $this->import->confirm($project, $request->user(), is_string($token) ? $token : null, $request->only(['printed_language', 'pages_checked', 'ack_notes', 'total_reason']));
        } catch (ValidationException $missing) {
            // Back to the preview, which is still staged, with what was answered and what is missing.
            throw $missing->redirectTo(route('boq.pdf.preview', $project));
        }

        if ($result === null) {
            return $this->expired($project);
        }

        $request->session()->forget(BoqPdfImport::SESSION);
        $request->session()->flash(BoqPdfImport::ENDED, true);

        if ($result->ok()) {
            return redirect()->route('boq.excel.index', $project)
                ->with('success', __(':count BOQ rows imported from the PDF.', ['count' => $result->rows()]))
                ->with('boq_import', ['created' => $result->created, 'updated' => $result->updated, 'unchanged' => $result->unchanged, 'errors' => [], 'notes' => $result->notes]);
        }

        return redirect()->route('boq.excel.index', $project)->with('boq_import', [
            'errors' => array_slice($result->errors, 0, self::SHOWN_ERRORS),
            'more' => max(0, count($result->errors) - self::SHOWN_ERRORS),
            'pdf' => true,
        ]);
    }

    public function discard(Request $request, Project $project): RedirectResponse
    {
        $token = $request->input('token');
        $this->import->discard($project, $request->user(), is_string($token) ? $token : null);
        $request->session()->forget(BoqPdfImport::SESSION);
        $request->session()->flash(BoqPdfImport::ENDED, true);

        return redirect()->route('boq.excel.index', $project)->with('success', __('The PDF was set aside. Nothing was imported.'));
    }

    private function expired(Project $project): RedirectResponse
    {
        return redirect()->route('boq.excel.index', $project)->with('boq_import', [
            'errors' => [['row' => 0, 'field' => null, 'message' => __('This PDF preview has expired or belongs to someone else. Previews are kept for :minutes minutes. Please upload the PDF again.', ['minutes' => BoqPdfImport::TTL_MINUTES])]],
            'more' => 0,
            'pdf' => true,
        ]);
    }
}
