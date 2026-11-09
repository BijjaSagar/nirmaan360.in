<?php

namespace App\Http\Controllers\Access;

use App\Exceptions\InvitationClosedException;
use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Services\Access\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitations) {}

    /** Public, signed link. */
    public function show(string $token): Response
    {
        $invitation = $this->invitations->findPending($token);

        if ($invitation === null) {
            return $this->closed(__('This invitation has expired or has already been used.'));
        }

        return response()->view('modules.access.invitation', [
            'invitation' => $invitation->load('organization'),
            'roleLabel' => $this->invitations->roleLabel($invitation),
            'signedInAs' => Auth::user(),
        ])->header('Referrer-Policy', 'no-referrer');
    }

    /** Public, signed link. */
    public function accept(Request $request, string $token): RedirectResponse|Response
    {
        if (Auth::check()) {
            return back()->withErrors(['email' => __('Please sign out before accepting an invitation.')]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        try {
            $user = $this->invitations->accept($token, $data);
        } catch (InvitationClosedException $exception) {
            return $this->closed($exception->getMessage());
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('dashboard')->with('success', __('Welcome to the team.'));
    }

    public function reissue(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $issued = $this->invitations->reissue($request->user(), $invitation);

        return back()->with([
            'success' => __('A new link has been created. The old one no longer works.'),
            'invite_link' => $issued['link'],
            'invite_email' => $issued['invitation']->email,
            'invite_emailed' => $issued['emailed'],
        ]);
    }

    public function destroy(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $this->invitations->revoke($request->user(), $invitation);

        return back()->with('success', __('Invitation cancelled.'));
    }

    private function closed(string $message): Response
    {
        return response()->view('modules.access.invitation-closed', ['message' => $message], 410);
    }
}
