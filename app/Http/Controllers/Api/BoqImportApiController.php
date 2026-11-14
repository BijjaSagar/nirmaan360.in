<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\BoqImportPresenter;
use App\Models\Project;
use App\Services\Imports\AppBoqImport;
use App\Services\Imports\BoqSheetImport;
use App\Services\Imports\SheetFile;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A BOQ file from the field app, through the website's column check: upload, see the check, change how the file is
 * read, confirm, or set it aside. The work is AppBoqImport's; this answers. See docs/API.md, "BOQ file import".
 */
class BoqImportApiController extends Controller
{
    public function __construct(private AppBoqImport $imports) {}

    /** POST /projects/{project}/boq-imports. One file, staged and shown. Nothing is written, whatever its layout. */
    public function store(Request $request, Project $project): JsonResponse
    {
        // One file no bigger than the largest the website takes, before anything in it is read.
        $request->validate(['file' => ['required', 'file', 'max:'.AppBoqImport::PDF_MAX_KB]]);
        $file = $request->file('file');

        if (! AppBoqImport::isPdf($file)) {
            // The website's own rules for a sheet (BoqExcelController::import).
            $request->validate(['file' => ['extensions:'.implode(',', SheetFile::UPLOAD_EXTENSIONS), 'max:'.SheetFile::UPLOAD_MAX_KB]]);
        }

        $staged = $this->imports->stage($project, $request->user(), $file);

        if (isset($staged['error'])) {
            return response()->json([
                'message' => $staged['error'],
                'code' => $staged['code'],
                ...(array_key_exists('scan_reader', $staged) ? ['scan_reader' => $staged['scan_reader']] : []),
                'errors' => ['file' => [$staged['error']]],
            ], 422);
        }

        $preview = $this->imports->preview($project, $request->user(), $staged['token']);

        return $preview === null ? $this->gone() : response()->json(['data' => $this->present($request, $preview)], 201);
    }

    /** GET /projects/{project}/boq-imports/{token}. The check again. */
    public function show(Request $request, Project $project, string $token): JsonResponse
    {
        $preview = $this->imports->preview($project, $request->user(), $token);

        return $preview === null ? $this->gone() : response()->json(['data' => $this->present($request, $preview)]);
    }

    /** PATCH /projects/{project}/boq-imports/{token}. The website's choices, with its rules. */
    public function update(Request $request, Project $project, string $token): JsonResponse
    {
        $data = $request->validate([
            'sheet' => ['nullable', 'integer', 'min:0', 'max:50'],
            'row' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'row_typed' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'span' => ['nullable', 'integer', 'min:1', 'max:3'],
            'fields' => ['nullable', 'array', 'max:'.SheetFile::MAX_COLUMNS],
            'fields.*' => ['nullable', 'string', 'max:20'],
            'unit' => ['nullable', 'string', 'max:16'],
            'skip' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'keep' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $preview = $this->imports->choose($project, $request->user(), $token, $data);

        return $preview === null ? $this->gone() : response()->json(['data' => $this->present($request, $preview)]);
    }

    /** POST /projects/{project}/boq-imports/{token}/confirm. Imports the file as shown at `version`, once. */
    public function confirm(Request $request, Project $project, string $token): JsonResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $done = $this->imports->confirm($project, $request->user(), $token, $version);

        return match ($done['outcome']) {
            'imported', 'repeated' => response()->json([
                'message' => __(':count BOQ rows imported.', ['count' => $done['result']['rows']]),
                'data' => BoqImportPresenter::result($done['result'], $done['outcome'] === 'repeated', Money::visible($request->user())),
            ]),
            'busy' => response()->json([
                'message' => __('This file is being imported now. Wait a moment, then confirm again to see what was imported.'),
                'code' => 'confirming',
            ], 409),
            'changed' => $done['preview'] === null ? $this->gone() : response()->json([
                'message' => $done['message'],
                'code' => 'version_changed',
                'version' => (int) $done['preview']['version'],
                'data' => $this->present($request, $done['preview']),
            ], 409),
            'refused' => response()->json([
                'message' => __('This file cannot be imported as it is'),
                'code' => 'import_refused',
                'errors' => ['import' => BoqImportPresenter::problems($done['result'])],
                'data' => $done['preview'] === null ? null : $this->present($request, $done['preview']),
            ], 422),
            default => $this->gone(),
        };
    }

    /** DELETE /projects/{project}/boq-imports/{token}. Sets the file aside. */
    public function destroy(Request $request, Project $project, string $token): Response|JsonResponse
    {
        return $this->imports->discard($project, $request->user(), $token) ? response()->noContent() : $this->gone();
    }

    private function present(Request $request, array $preview): array
    {
        return BoqImportPresenter::preview($preview, Money::visible($request->user()));
    }

    /** The same answer for a token that never was, one that expired, and someone else's. */
    private function gone(): JsonResponse
    {
        return response()->json([
            'message' => __('This preview has expired or belongs to someone else. Previews are kept for :minutes minutes. Please upload the file again.', ['minutes' => BoqSheetImport::TTL_MINUTES]),
            'code' => 'import_not_found',
        ], 404);
    }
}
