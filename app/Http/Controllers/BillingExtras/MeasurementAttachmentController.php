<?php

namespace App\Http\Controllers\BillingExtras;

use App\Http\Controllers\Controller;
use App\Models\MeasurementAttachment;
use App\Models\MeasurementEntry;
use App\Services\BillingExtras\MeasurementAttachmentService;
use App\Services\BillingExtras\PrivateFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeasurementAttachmentController extends Controller
{
    public function __construct(private MeasurementAttachmentService $service) {}

    public function store(Request $request, MeasurementEntry $measurement): RedirectResponse
    {
        $data = $request->validate($this->service->rules($request->input('kind')));

        $this->service->add($measurement, $request->file('file'), $data, $request->user());

        return back()->with('success', __('Attachment added.'));
    }

    public function show(MeasurementAttachment $attachment): StreamedResponse
    {
        return PrivateFile::response($attachment->path);
    }

    public function destroy(MeasurementAttachment $attachment): RedirectResponse
    {
        $this->service->remove($attachment);

        return back()->with('success', __('Attachment removed.'));
    }
}
