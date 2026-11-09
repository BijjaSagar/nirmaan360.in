<?php

namespace App\Http\Controllers\Qhs;

use App\Http\Controllers\Controller;
use App\Models\FieldAttachment;
use App\Services\Qhs\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Photos and documents on NCRs, incidents, RFIs and quality tests, kept on the private disk. */
class AttachmentController extends Controller
{
    public function __construct(private AttachmentService $service) {}

    public function store(Request $request, string $type, int $id): RedirectResponse
    {
        abort_unless(isset(AttachmentService::OWNERS[$type]), 404);

        [$class, $prefix] = AttachmentService::OWNERS[$type];
        abort_unless($request->user()->can("{$prefix}.manage"), 403);

        $owner = $class::findOrFail($id);
        $data = $request->validate($this->service->rules());

        $this->service->store($owner, $request->file('file'), $data, $request->user());

        return back()->with('success', __('File added.'));
    }

    public function show(Request $request, FieldAttachment $fieldAttachment): StreamedResponse
    {
        $prefix = AttachmentService::prefixFor((string) $fieldAttachment->attachable_type);

        abort_unless($prefix !== null && $request->user()->can("{$prefix}.view"), 403);
        abort_unless(Storage::exists($fieldAttachment->path), 404);

        return Storage::response($fieldAttachment->path, $fieldAttachment->original_name);
    }
}
