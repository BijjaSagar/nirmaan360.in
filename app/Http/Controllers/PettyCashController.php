<?php

namespace App\Http\Controllers;

use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function storeEntry(Request $request, PettyCashWallet $wallet): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['topup', 'expense'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'category' => ['nullable', 'required_if:type,expense', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'client_uuid' => ['nullable', 'uuid'],
            'bill' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        if (! empty($data['client_uuid']) && PettyCashEntry::where('client_uuid', $data['client_uuid'])->exists()) {
            return back();
        }

        if ($data['type'] === 'expense') {
            $wallet->load('entries');

            if (Dec::of($data['amount'])->isGreaterThan(Dec::of($wallet->balance()))) {
                throw ValidationException::withMessages(['amount' => __('This expense is more than the wallet balance.')]);
            }
        }

        unset($data['bill']);

        $wallet->entries()->create([
            ...$data,
            'amount' => Dec::money($data['amount']),
            'organization_id' => $wallet->organization_id,
            'bill_path' => $request->file('bill')?->store('org-'.$wallet->organization_id.'/petty'),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', __('Entry saved.'));
    }

    public function bill(PettyCashEntry $entry): StreamedResponse
    {
        abort_unless($entry->bill_path, 404);

        return Storage::response($entry->bill_path);
    }
}
