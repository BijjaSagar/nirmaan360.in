<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Presenters\PettyEntryPresenter;
use App\Http\Presenters\WalletPresenter;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Services\PettyCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PettyCashApiController extends Controller
{
    private const RECENT_ENTRIES = 30;

    public function __construct(private PettyCashService $service) {}

    public function index(Project $project): JsonResponse
    {
        $wallets = $project->pettyCashWallets()->with(['entries' => fn ($q) => $q->latest('entry_date')->latest('id')])->get();

        return response()->json([
            'data' => $wallets->map(fn (PettyCashWallet $wallet) => [
                ...WalletPresenter::present($wallet),
                'entries' => $wallet->entries->take(self::RECENT_ENTRIES)->map(PettyEntryPresenter::present(...))->values(),
            ]),
        ]);
    }

    public function storeWallet(Request $request, Project $project): JsonResponse
    {
        $wallet = $this->service->createWallet($project, $request->validate([
            ...$this->service->walletRules($request->user()),
            'client_uuid' => ['nullable', 'uuid'],
        ]));

        return response()->json(['data' => WalletPresenter::present($wallet)], $wallet->wasRecentlyCreated ? 201 : 200);
    }

    public function storeEntry(Request $request, PettyCashWallet $wallet): JsonResponse
    {
        $data = $request->validate([
            ...$this->service->entryRules(),
            'bill' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);
        unset($data['bill']);

        $entry = $this->service->addEntry($wallet, $data, $request->user(), $request->file('bill'));

        return response()->json(['data' => PettyEntryPresenter::present($entry)], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function bill(PettyCashEntry $entry): StreamedResponse
    {
        abort_unless($entry->bill_path && Storage::exists($entry->bill_path), 404);

        return Storage::response($entry->bill_path);
    }
}
