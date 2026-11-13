<?php

namespace App\Http\Controllers\BoqImport;

use App\Http\Controllers\Controller;
use App\Models\BoqImportLayout;
use App\Models\Project;
use App\Services\Imports\BoqImportLayouts;
use App\Services\Imports\BoqSheetImport;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The column check of a BOQ sheet in a contractor's own layout. The upload arrives through
 * BoqExcelController::import, the same form as the template and PDFs, which stages the file and sends
 * people here. Nothing is written until the lines are confirmed.
 */
class BoqSheetImportController extends Controller
{
    /** Problems shown on the preview and after a refused import. The rest are counted. */
    private const SHOWN_ERRORS = 200;

    public function __construct(private BoqSheetImport $import, private BoqImportLayouts $layouts) {}

    public function preview(Request $request, Project $project): View|RedirectResponse
    {
        $token = $this->token($request);
        $entry = $this->import->staged($project, $request->user(), $token);

        if ($entry === null) {
            return $this->expired($project);
        }

        return view('modules.boq_import.preview', [
            'project' => $project,
            'token' => $token,
            'preview' => $this->import->preview($project, $entry),
            'money' => Money::visible($request->user()),
            'fields' => BoqImportLayouts::fieldLabels(),
            'shownErrors' => self::SHOWN_ERRORS,
        ]);
    }

    /** Change the sheet, the heading row, a column's meaning or the unit, then show the preview again. */
    public function choose(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'sheet' => ['nullable', 'integer', 'min:0', 'max:50'],
            'row' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'span' => ['nullable', 'integer', 'min:1', 'max:3'],
            'fields' => ['nullable', 'array', 'max:26'],
            'fields.*' => ['nullable', 'string', 'max:20'],
            'unit' => ['nullable', 'string', 'max:16'],
        ]);

        $entry = $this->import->choose($project, $request->user(), $this->token($request), $data);

        return $entry === null ? $this->expired($project) : redirect()->route('boq.sheet.preview', $project);
    }

    public function confirm(Request $request, Project $project): RedirectResponse
    {
        try {
            $result = $this->import->confirm($project, $request->user(), $this->token($request), $request->input('version'));
        } catch (ValidationException $changed) {
            throw $changed->redirectTo(route('boq.sheet.preview', $project));
        }

        if ($result === null) {
            return $this->expired($project);
        }

        if ($result->ok()) {
            $request->session()->forget(BoqSheetImport::SESSION);

            return redirect()->route('boq.excel.index', $project)
                ->with('success', __(':count BOQ rows imported.', ['count' => $result->rows()]))
                ->with('boq_import', ['created' => $result->created, 'updated' => $result->updated, 'unchanged' => $result->unchanged, 'errors' => [], 'notes' => $result->notes]);
        }

        return redirect()->route('boq.sheet.preview', $project);
    }

    public function discard(Request $request, Project $project): RedirectResponse
    {
        $this->import->discard($project, $request->user(), $this->token($request));
        $request->session()->forget(BoqSheetImport::SESSION);

        return redirect()->route('boq.excel.index', $project)->with('success', __('The file was set aside. Nothing was imported.'));
    }

    public function destroyLayout(BoqImportLayout $layout): RedirectResponse
    {
        $this->layouts->delete($layout);

        return back()->with('success', __('The saved layout was removed. Files in that layout will be read afresh.'));
    }

    private function token(Request $request): ?string
    {
        $token = $request->input('token', $request->session()->get(BoqSheetImport::SESSION));

        return is_string($token) ? $token : null;
    }

    private function expired(Project $project): RedirectResponse
    {
        return redirect()->route('boq.excel.index', $project)->with('boq_import', [
            'errors' => [['row' => 0, 'field' => null, 'message' => __('This preview has expired or belongs to someone else. Previews are kept for :minutes minutes. Please upload the file again.', ['minutes' => BoqSheetImport::TTL_MINUTES])]],
            'more' => 0,
        ]);
    }
}
