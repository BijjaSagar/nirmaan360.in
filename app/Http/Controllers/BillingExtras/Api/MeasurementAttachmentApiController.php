<?php

namespace App\Http\Controllers\BillingExtras\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\MeasurementPresenter;
use App\Models\MeasurementAttachment;
use App\Models\MeasurementEntry;
use App\Services\BillingExtras\MeasurementAttachmentService;
use App\Services\BillingExtras\PrivateFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeasurementAttachmentApiController extends Controller
{
    public function __construct(private MeasurementAttachmentService $service) {}

    public function index(MeasurementEntry $measurement): JsonResponse
    {
        return response()->json(['data' => $measurement->attachments->map(MeasurementPresenter::attachment(...))->all()]);
    }

    public function store(Request $request, MeasurementEntry $measurement): JsonResponse
    {
        $data = $request->validate($this->service->rules($request->input('kind')));

        $attachment = $this->service->add($measurement, $request->file('file'), $data, $request->user());

        return response()->json(['data' => MeasurementPresenter::attachment($attachment)], $attachment->wasRecentlyCreated ? 201 : 200);
    }

    public function show(MeasurementAttachment $attachment): StreamedResponse
    {
        return PrivateFile::response($attachment->path);
    }

    public function destroy(MeasurementAttachment $attachment): Response
    {
        $this->service->remove($attachment);

        return response()->noContent();
    }
}
