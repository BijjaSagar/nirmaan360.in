<?php

namespace App\Services;

use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PettyCashService
{
    /** One rule set for the web form, the REST API and offline sync. */
    public function entryRules(): array
    {
        return [
            'type' => ['required', Rule::in(['topup', 'expense'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'category' => ['nullable', 'required_if:type,expense', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function walletRules(User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'user_id' => ['bail', 'nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $user->organization_id)],
        ];
    }

    /** Open a wallet. A repeated client_uuid returns the original row. */
    public function createWallet(Project $project, array $data): PettyCashWallet
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findWallet($project->organization_id, $uuid))) {
            return $existing;
        }

        return Idempotent::create(
            fn () => $project->pettyCashWallets()->create([...$data, 'organization_id' => $project->organization_id]),
            fn () => $this->findWallet($project->organization_id, (string) $uuid),
        );
    }

    /**
     * Append an entry. A repeated client_uuid returns the original row.
     * The wallet row lock serialises expenses, so two cannot both pass the balance check.
     */
    public function addEntry(PettyCashWallet $wallet, array $data, User $user, ?UploadedFile $bill = null): PettyCashEntry
    {
        return DB::transaction(function () use ($wallet, $data, $user, $bill) {
            $wallet = PettyCashWallet::lockForUpdate()->findOrFail($wallet->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = $this->findByClientUuid($wallet->organization_id, $uuid))) {
                return $existing;
            }

            if ($data['type'] === 'expense' && Dec::of($data['amount'])->isGreaterThan(Dec::of($wallet->balance()))) {
                throw ValidationException::withMessages(['amount' => __('This expense is more than the wallet balance.')]);
            }

            return $wallet->entries()->create([
                ...$data,
                'amount' => Dec::money($data['amount']),
                'organization_id' => $wallet->organization_id,
                'bill_path' => $bill?->store('org-'.$wallet->organization_id.'/petty'),
                'created_by' => $user->id,
            ]);
        });
    }

    /** Attach a bill uploaded after its entry. Returns false when the entry already has one. */
    public function attachBill(PettyCashEntry $entry, UploadedFile $file): bool
    {
        return DB::transaction(function () use ($entry, $file) {
            $entry = PettyCashEntry::lockForUpdate()->findOrFail($entry->id);

            if ($entry->bill_path !== null) {
                return false;
            }

            $entry->update(['bill_path' => $file->store('org-'.$entry->organization_id.'/petty')]);

            return true;
        });
    }

    private function findWallet(int $organizationId, string $uuid): ?PettyCashWallet
    {
        return PettyCashWallet::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    public function findByClientUuid(int $organizationId, string $uuid): ?PettyCashEntry
    {
        return PettyCashEntry::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
