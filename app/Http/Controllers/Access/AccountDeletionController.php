<?php

namespace App\Http\Controllers\Access;

use App\Exceptions\AccountDeletionChanged;
use App\Http\Controllers\Controller;
use App\Services\Access\AccountDeletionService;
use App\Services\ContractorPortal\ContractorPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The website's "Delete my account", the same as the app's. Open to everyone signed in. */
class AccountDeletionController extends Controller
{
    public function __construct(private AccountDeletionService $deletions) {}

    public function show(Request $request, ContractorPortalService $portal): View
    {
        $user = $request->user();

        return view('modules.access.account-delete', [
            'preview' => $this->deletions->preview($user),
            // A contractor login sees the page in its own portal frame, without the company menu.
            'party' => $portal->partyFor($user),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(AccountDeletionService::MODES)],
            'password' => ['required', 'string'],
            'confirm_name' => ['required_if:mode,company', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->deletions->deleteAccount($request->user(), $data['mode'], $data['password'], $data['confirm_name'] ?? null, 'web');
        } catch (AccountDeletionChanged $changed) {
            return back()->withErrors(['mode' => $changed->getMessage()]);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', $result['result'] === 'scheduled'
            ? __('Your company will be erased after :date. Sign in again before then to cancel.', ['date' => $result['erase_after']->format('d M Y')])
            : __('Your account has been deleted.'));
    }
}
