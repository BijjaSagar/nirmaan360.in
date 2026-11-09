<?php

namespace App\Http\Controllers;

use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\User;
use App\Services\PettyCashService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PettyCashController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        return view('petty.index', [
            'project' => $project,
            'wallets' => $project->pettyCashWallets()->with(['holder', 'entries' => fn ($q) => $q->latest('entry_date')->latest('id')])->get(),
            'users' => User::where('organization_id', $request->user()->organization_id)->where('is_active', true)->orderBy('name')->get(),
            'clientUuid' => (string) Str::uuid(),
        ]);
    }

    public function storeWallet(Request $request, Project $project): RedirectResponse
    {
        $project->pettyCashWallets()->create([
            ...$request->validate([
                'name' => ['required', 'string', 'max:120'],
                'user_id' => ['nullable', Rule::exists('users', 'id')->where('organization_id', $request->user()->organization_id)],
            ]),
            'organization_id' => $project->organization_id,
        ]);

        return back()->with('success', __('Wallet created.'));
    }

    public function storeEntry(Request $request, PettyCashWallet $wallet, PettyCashService $service): RedirectResponse
    {
        $data = $request->validate([
            ...$service->entryRules(),
            'bill' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);
        unset($data['bill']);

        $entry = $service->addEntry($wallet, $data, $request->user(), $request->file('bill'));

        if (! $entry->wasRecentlyCreated) {
            return back();
        }

        return back()->with('success', __('Entry saved.'));
    }

    public function bill(PettyCashEntry $entry): StreamedResponse
    {
        abort_unless($entry->bill_path, 404);

        return Storage::response($entry->bill_path);
    }
}
