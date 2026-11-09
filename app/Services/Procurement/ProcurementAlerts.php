<?php

namespace App\Services\Procurement;

use App\Models\Material;
use App\Models\MaterialStore;
use App\Models\User;
use App\Notifications\ProcurementAlert;
use Illuminate\Support\Facades\Notification;

/** In-app alerts for people holding procurement.alerts. */
class ProcurementAlerts
{
    public function lowStock(MaterialStore $store, Material $material, string $balance, string $reorderLevel): void
    {
        $this->send($store->organization_id, new ProcurementAlert(
            __('Low stock: :material', ['material' => $material->name]),
            __(':store has :balance left, below the reorder level of :level.', ['store' => $store->name, 'balance' => fmt_qty($balance), 'level' => fmt_qty($reorderLevel)]),
            route('procurement.stock.index', $store->project_id),
        ));
    }

    public function wastage(int $organizationId, int $projectId, string $boqCode, Material $material, string $variancePct, string $allowedPct): void
    {
        $this->send($organizationId, new ProcurementAlert(
            __('Wastage alert: :material', ['material' => $material->name]),
            __('BOQ item :code has used :variance% more than the BOQ quantity, above the :allowed% allowed.', ['code' => $boqCode, 'variance' => $variancePct, 'allowed' => $allowedPct]),
            route('procurement.reconciliation.index', $projectId),
        ));
    }

    private function send(int $organizationId, ProcurementAlert $alert): void
    {
        $recipients = User::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('procurement.alerts'));

        Notification::send($recipients, $alert);
    }
}
