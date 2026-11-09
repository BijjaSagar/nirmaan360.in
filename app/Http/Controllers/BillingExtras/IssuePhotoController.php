<?php

namespace App\Http\Controllers\BillingExtras;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssuePhoto;
use App\Services\BillingExtras\IssuePhotoService;
use App\Services\BillingExtras\PrivateFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IssuePhotoController extends Controller
{
    public function __construct(private IssuePhotoService $service) {}

    public function store(Request $request, Issue $issue): RedirectResponse
    {
        $data = $request->validate($this->service->rules());

        $this->service->add($issue, $request->file('photo'), $data, $request->user());

        return back()->with('success', __('Photo added.'));
    }

    public function show(IssuePhoto $photo): StreamedResponse
    {
        return PrivateFile::response($photo->path);
    }

    public function destroy(IssuePhoto $photo): RedirectResponse
    {
        $this->service->remove($photo);

        return back()->with('success', __('Photo removed.'));
    }
}
