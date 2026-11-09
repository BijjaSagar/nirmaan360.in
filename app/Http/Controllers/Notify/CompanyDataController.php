<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\CompanyDataExport;
use App\Services\Notify\CompanyDeletionService;
use App\Services\Notify\CompanyExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admins can read this page. Only an owner may export or delete, and role '*' cannot tell them apart. */
class CompanyDataController extends Controller
{
    public function __construct(private CompanyExportService $exports, private CompanyDeletionService $deletions) {}

    public function index(Request $request): View
    {
        $organization = $request->user()->organization;

        return view('modules.notify.company-data', [
            'organization' => $organization,
            'isOwner' => $request->user()->role === 'owner',
            'exports' => CompanyDataExport::latest('id')->limit(5)->get(),
            'exportInProgress' => $this->exports->inProgress($organization->id),
            'deletion' => $this->deletions->pending($organization->id),
            'graceDays' => CompanyDeletionService::GRACE_DAYS,
        ]);
    }

    public function export(Request $request): RedirectResponse
    {
        $this->ownerOnly($request);
        $this->exports->request($request->user()->organization, $request->user());

        return back()->with('success', __('We are preparing your export. You will get a notification when it is ready.'));
    }

    public function download(Request $request, CompanyDataExport $export): StreamedResponse
    {
        $this->ownerOnly($request);

        return $this->exports->download($export, $request->user());
    }

    public function requestDeletion(Request $request): RedirectResponse
    {
        $this->ownerOnly($request);
        $typed = $request->validate(['confirm_name' => ['required', 'string', 'max:255']])['confirm_name'];
        $deletion = $this->deletions->request($request->user()->organization, $request->user(), $typed);

        return back()->with('success', __('This company will be erased on :date. You can cancel until then.', ['date' => $deletion->purge_after->format('d M Y')]));
    }

    public function cancelDeletion(Request $request): RedirectResponse
    {
        $this->ownerOnly($request);
        $this->deletions->cancel($request->user()->organization, $request->user());

        return back()->with('success', __('Deletion cancelled. Your company stays as it is.'));
    }

    private function ownerOnly(Request $request): void
    {
        abort_unless($request->user()->role === 'owner', 403, __('Only a company owner can do this.'));
    }
}
