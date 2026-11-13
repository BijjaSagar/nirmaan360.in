<?php

namespace App\Http\Controllers\BoqScan;

use App\Http\Controllers\Controller;
use App\Models\DocumentRead;
use App\Models\Project;
use App\Services\BoqScan\BoqReadKind;
use App\Services\BoqScan\BoqScanPresenter;
use App\Services\BoqScan\BoqScanReview;
use App\Services\DocumentReading\ReadAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The scanned BOQ reader for the field app: create a read for a project, and see how far it has
 * come. The phone uploads, prices, starts and watches the read through the shared read routes;
 * checking and confirming the lines is done on the web, at review_url.
 */
class BoqScanApiController extends Controller
{
    public function __construct(private BoqScanReview $review, private BoqScanPresenter $presenter) {}

    public function store(Request $request, Project $project): JsonResponse
    {
        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'pages_total' => ['required', 'integer', 'min:1', 'max:60'],
            'language' => ['required', Rule::in(['en', 'mr', 'hi'])],
            'source_name' => ['nullable', 'string', 'max:160'],
        ]);

        [$read, $created] = $this->review->open($project, $request->user(), $data, 'mobile');

        return response()->json(['id' => $read->id, 'status' => $read->status], $created ? 201 : 200);
    }

    public function show(Request $request, DocumentRead $read): JsonResponse
    {
        abort_unless($read->kind === BoqReadKind::KEY, 404);
        ReadAccess::assert($read, $request->user());

        return response()->json($this->presenter->forApp($read));
    }
}
