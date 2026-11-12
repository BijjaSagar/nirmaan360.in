<?php

namespace App\Http\Controllers\Notify;

use App\Http\Controllers\Controller;
use App\Models\CompanyDataExport;
use App\Services\Access\AccountDeletionService;
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

    public function index(Request $request, AccountDeletionService $accounts): View
    {
        $organization = $request->user()->organization;
        $isOwner = $request->user()->role === 'owner';
        $deletion = $this->deletions->pending($organization->id);

        return view('modules.notify.company-data', [
            'organization' => $organization,
            'isOwner' => $isOwner,
            'exports' => CompanyDataExport::latest('id')->limit(5)->get(),
            'exportInProgress' => $this->exports->inProgress($organization->id),
            'deletion' => $deletion,
            // Whose account the cancel button deletes, so the owner is told before pressing it.
            'deletedOnCancel' => $isOwner && $deletion !== null ? $accounts->deletedIfCancelledBy($request->user()) : null,
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

    /**
     * A signed-in session left open is not enough to erase the company: the owner's own password is asked for too.
     * As on Delete my account, the name is checked first, so a wrong name says nothing about the password.
     */
    public function requestDeletion(Request $request): RedirectResponse
    {
        $this->ownerOnly($request);
        $organization = $request->user()->organization;
        $typed = $request->validate([
            'confirm_name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ])['confirm_name'];
        $this->deletions->assertConfirmName($organization, $typed);
        $request->validate(['password' => ['current_password:web']], ['password.current_password' => __('That password is not right.')]);

        $deletion = $this->deletions->request($organization, $request->user(), $typed);

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
