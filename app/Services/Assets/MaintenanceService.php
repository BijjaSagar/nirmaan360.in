<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\AssetServiceRecord;
use App\Models\User;
use App\Services\FieldOps\CreatesOnce;
use App\Support\Dec;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The service schedule. A service falls due by date, by hours or km worked since the last
 * one, or both. Work since the last service is added up from the machine log, so it can
 * never drift from what was logged.
 */
class MaintenanceService
{
    use CreatesOnce;

    public function rules(User $user): array
    {
        return [
            'asset_id' => ['bail', 'required', 'integer', $this->inOrganization('assets', $user->organization_id)],
            'title' => ['required', 'string', 'max:120'],
            'interval_usage' => ['nullable', 'numeric', 'gt:0', 'max:99999999', 'required_without:interval_days'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:3650', 'required_without:interval_usage'],
            'last_done_on' => ['nullable', 'date', 'before_or_equal:today'],
            'next_due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function doneRules(): array
    {
        return [
            'done_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:255'],
            'done_by' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function add(array $data, User $user): AssetMaintenance
    {
        $asset = Asset::withoutGlobalScopes()->where('organization_id', $user->organization_id)->findOrFail($data['asset_id']);

        return $this->createOnce(AssetMaintenance::class, $user->organization_id, $data['client_uuid'] ?? null, function () use ($asset, $data) {
            if (filled($data['interval_usage'] ?? null) && $asset->meter_unit === null) {
                throw ValidationException::withMessages(['interval_usage' => __('Set the meter of this asset (hours or km) before scheduling by usage.')]);
            }

            $days = filled($data['interval_days'] ?? null) ? (int) $data['interval_days'] : null;
            $next = $data['next_due_on'] ?? null;

            if (! $next && $days) {
                $next = Carbon::parse($data['last_done_on'] ?? today())->addDays($days)->toDateString();
            }

            return AssetMaintenance::create([
                'organization_id' => $asset->organization_id,
                'asset_id' => $asset->id,
                'title' => $data['title'],
                'interval_usage' => filled($data['interval_usage'] ?? null) ? Dec::qty($data['interval_usage']) : null,
                'interval_days' => $days,
                'last_done_on' => $data['last_done_on'] ?? null,
                'next_due_on' => $next,
                'is_active' => true,
                'notes' => $data['notes'] ?? null,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);
        });
    }

    /** Record that a service was done: keep the history and push the next due date out. */
    public function markDone(AssetMaintenance $item, array $data, User $user): AssetServiceRecord
    {
        return $this->createOnce(AssetServiceRecord::class, $item->organization_id, $data['client_uuid'] ?? null, function () use ($item, $data, $user) {
            $record = AssetServiceRecord::create([
                'organization_id' => $item->organization_id,
                'asset_id' => $item->asset_id,
                'maintenance_id' => $item->id,
                'done_on' => $data['done_on'],
                'description' => $data['description'] ?? $item->title,
                'done_by' => $data['done_by'] ?? null,
                'cost' => Dec::money($data['cost'] ?? 0),
                'created_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            $done = Carbon::parse($data['done_on'])->startOfDay();

            if ($item->last_done_on === null || $done->gte($item->last_done_on)) {
                $item->update([
                    'last_done_on' => $done->toDateString(),
                    'next_due_on' => $item->interval_days ? $done->copy()->addDays($item->interval_days)->toDateString() : null,
                ]);
            }

            return $record;
        });
    }

    public function toggle(AssetMaintenance $item): AssetMaintenance
    {
        $item->update(['is_active' => ! $item->is_active]);

        return $item;
    }

    /** Hours or km worked after a date, in the asset's own meter unit. Zero for an asset with no meter. */
    public function usageSince(Asset $asset, ?CarbonInterface $after): string
    {
        if (! in_array($asset->meter_unit, ['hours', 'km'], true)) {
            return Dec::qty(0);
        }

        return Dec::qty(
            $asset->logs()
                ->when($after, fn ($query) => $query->whereDate('log_date', '>', $after->toDateString()))
                ->sum($asset->meter_unit)
        );
    }

    /**
     * Where one scheduled service stands today.
     *
     * @return array{state:string,usage_since:string,usage_left:?string,days_left:?int,unit:?string}
     */
    public function status(AssetMaintenance $item, ?Asset $asset = null, ?CarbonInterface $today = null): array
    {
        $asset ??= $item->asset;
        $today = ($today ?? today())->copy()->startOfDay();
        $soonDays = (int) config('modules.assets.maintenance.due_soon_days');
        $soonPct = config('modules.assets.maintenance.due_soon_usage_pct');

        $usage = $this->usageSince($asset, $item->last_done_on);
        $overdue = false;
        $soon = false;

        $daysLeft = $item->next_due_on ? (int) $today->diffInDays($item->next_due_on->copy()->startOfDay(), false) : null;

        if ($daysLeft !== null) {
            $overdue = $daysLeft < 0;
            $soon = ! $overdue && $daysLeft <= $soonDays;
        }

        $usageLeft = null;

        if ($item->interval_usage !== null && $asset->meter_unit !== null) {
            $left = Dec::of($item->interval_usage)->minus($usage);
            $usageLeft = Dec::qty($left);

            if ($left->isLessThanOrEqualTo(0)) {
                $overdue = true;
            } elseif (Dec::of($usage)->multipliedBy(100)->isGreaterThanOrEqualTo(Dec::of($item->interval_usage)->multipliedBy($soonPct))) {
                $soon = true;
            }
        }

        return [
            'state' => $overdue ? 'overdue' : ($soon ? 'due_soon' : 'ok'),
            'usage_since' => $usage,
            'usage_left' => $usageLeft,
            'days_left' => $daysLeft,
            'unit' => $asset->meter_unit,
        ];
    }

    /**
     * Every active scheduled service of every working asset, the most urgent first.
     *
     * @return Collection<int, array{item:AssetMaintenance,asset:Asset,status:array}>
     */
    public function schedule(): Collection
    {
        $order = ['overdue' => 0, 'due_soon' => 1, 'ok' => 2];

        return AssetMaintenance::with('asset')
            ->where('is_active', true)
            ->whereHas('asset', fn ($query) => $query->where('status', '!=', 'retired'))
            ->get()
            ->map(fn (AssetMaintenance $item) => ['item' => $item, 'asset' => $item->asset, 'status' => $this->status($item)])
            ->sortBy(fn (array $row) => [$order[$row['status']['state']], $row['status']['days_left'] ?? PHP_INT_MAX])
            ->values();
    }
}
