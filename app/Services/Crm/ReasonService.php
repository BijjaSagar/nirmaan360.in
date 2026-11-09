<?php

namespace App\Services\Crm;

use App\Models\CrmOutcomeReason;
use App\Models\Organization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The reasons offered when a tender is won, lost or not bid. Each company can edit its own list. */
class ReasonService
{
    /** @return Collection<int,CrmOutcomeReason> */
    public function options(int $organizationId, string $kind, bool $onlyActive = true): Collection
    {
        $this->ensureDefaults($organizationId);

        return CrmOutcomeReason::query()
            ->where('organization_id', $organizationId)
            ->where('kind', $kind)
            ->when($onlyActive, fn ($query) => $query->where('active', true))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }

    /** Give a company the starting list once. Reasons are switched off, never deleted, so this never runs twice. */
    public function ensureDefaults(int $organizationId): void
    {
        if (CrmOutcomeReason::query()->where('organization_id', $organizationId)->exists()) {
            return;
        }

        DB::transaction(function () use ($organizationId) {
            // The organisation row lock stops two first visits both seeding the list.
            Organization::lockForUpdate()->find($organizationId);

            if (CrmOutcomeReason::query()->where('organization_id', $organizationId)->exists()) {
                return;
            }

            foreach ($this->defaults() as $kind => $labels) {
                foreach ($labels as $index => $label) {
                    CrmOutcomeReason::create(['organization_id' => $organizationId, 'kind' => $kind, 'label' => $label, 'sort' => $index + 1]);
                }
            }
        });
    }

    public function create(int $organizationId, string $kind, string $label): CrmOutcomeReason
    {
        $this->ensureDefaults($organizationId);

        $sort = (int) CrmOutcomeReason::query()->where('organization_id', $organizationId)->where('kind', $kind)->max('sort');

        return CrmOutcomeReason::create(['organization_id' => $organizationId, 'kind' => $kind, 'label' => $label, 'sort' => $sort + 1]);
    }

    public function update(CrmOutcomeReason $reason, string $label, bool $active): CrmOutcomeReason
    {
        $reason->update(['label' => $label, 'active' => $active]);

        return $reason;
    }

    /** The reason, only if it is one of this company's active reasons of the right kind. */
    public function resolve(int $organizationId, string $kind, int|string|null $id): ?CrmOutcomeReason
    {
        if (blank($id)) {
            return null;
        }

        return CrmOutcomeReason::query()
            ->where('organization_id', $organizationId)
            ->where('kind', $kind)
            ->where('active', true)
            ->find($id)
            ?? throw ValidationException::withMessages(['outcome_reason_id' => __('Choose one of the listed reasons.')]);
    }

    /** @return array<string,list<string>> */
    private function defaults(): array
    {
        return [
            'won' => [__('Competitive price'), __('Good relationship with the client'), __('Strong technical bid'), __('Relevant experience'), __('Other')],
            'lost' => [__('Price too high'), __('Did not meet eligibility'), __('Technical score too low'), __('Client preferred another bidder'), __('Missed the deadline'), __('Other')],
            'no_bid' => [__('Not enough capacity'), __('Margin too thin'), __('Terms not acceptable'), __('Site too far'), __('Other')],
        ];
    }
}
