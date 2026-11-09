<?php

namespace App\Http\Controllers\ContractorPortal;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\ContractorPortal\ContractorAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Staff side: who may sign in for one subcontractor, opened from that contractor's page. */
class AccessController extends Controller
{
    public function __construct(private ContractorAccessService $access) {}

    public function show(Party $party): View
    {
        abort_unless($party->type === 'subcontractor', 404);

        return view('modules.contractor_portal.access', ['party' => $party, ...$this->access->state($party)]);
    }

    public function store(Request $request, Party $party): RedirectResponse
    {
        $issued = $this->access->invite($request->user(), $party, $request->validate($this->access->rules()));

        return back()->with($this->issued($issued, __('Invitation created.')));
    }

    public function reissue(Request $request, Party $party, TeamInvitation $invitation): RedirectResponse
    {
        $issued = $this->access->reissue($request->user(), $party, $invitation);

        return back()->with($this->issued($issued, __('A new link has been created. The old one no longer works.')));
    }

    public function cancel(Request $request, Party $party, TeamInvitation $invitation): RedirectResponse
    {
        $this->access->cancel($request->user(), $party, $invitation);

        return back()->with('success', __('Invitation cancelled.'));
    }

    public function revoke(Request $request, Party $party, User $user): RedirectResponse
    {
        $this->access->revoke($request->user(), $party, $user);

        return back()->with('success', __('Access taken away. The contractor is signed out everywhere.'));
    }

    public function restore(Request $request, Party $party, User $user): RedirectResponse
    {
        $this->access->restore($request->user(), $party, $user);

        return back()->with('success', __('Access restored.'));
    }

    /** @param  array{invitation: TeamInvitation, link: string, emailed: bool}  $issued */
    private function issued(array $issued, string $message): array
    {
        return [
            'success' => $message,
            'invite_link' => $issued['link'],
            'invite_email' => $issued['invitation']->email,
            'invite_emailed' => $issued['emailed'],
        ];
    }
}
