<?php

namespace App\Services\MaterialControl;

use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\MaterialShortDelivery;
use App\Models\User;
use App\Notifications\ProcurementAlert;
use App\Services\Notify\MaterialNotifier;
use App\Services\Procurement\ProcurementRules;
use App\Services\Procurement\StockLedgerService;
use App\Services\Procurement\UnitConverter;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * A supplier delivering less than the challan said.
 *
 * Found at the gate, it is part of the challan: only the counted quantity entered stock.
 * Found later, full stock was already posted, so a negative GRN movement corrects the
 * ledger. Sand example: challan 4 brass, 3 arrived, 1 extra ordered. Received ends at 4.
 */
class ShortDeliveryService
{
    public function __construct(
        private StockLedgerService $ledger,
        private UnitConverter $units,
        private SupplierRecordService $suppliers,
        private MaterialNotifier $notifier,
    ) {}

    public function rules(): array
    {
        return [
            'short_qty' => ProcurementRules::qty(),
            'found_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ProcurementRules::clientUuid(),
        ];
    }

    /** Called while posting a challan whose counted quantity is below the paper quantity. */
    public function recordAtEntry(DeliveryChallan $challan, Material $material, User $by): MaterialShortDelivery
    {
        $short = Dec::of($challan->challan_qty)->minus(Dec::of($challan->qty));

        $row = MaterialShortDelivery::create([
            'organization_id' => $challan->organization_id,
            'project_id' => $challan->project_id,
            'party_id' => $challan->party_id,
            'delivery_challan_id' => $challan->id,
            'material_id' => $challan->material_id,
            'unit_id' => $challan->unit_id,
            'short_qty' => Dec::qty($short),
            'short_base_qty' => $this->units->toBase($material, Dec::qty($short), $challan->unit_id),
            'found_on' => $challan->challan_date,
            'ledger_posted' => false,
            'status' => 'POSTED',
            'created_by' => $by->id,
        ]);

        $this->alertIfRepeated($row);
        // A shortage opens a gap that needs a written reason, so the people who can write it are pushed.
        $this->notifier->shortDelivery($row, $by);

        return $row;
    }

    /** Record a shortage found after the delivery was posted, and correct the stock. */
    public function report(DeliveryChallan $challan, array $data, User $by): MaterialShortDelivery
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($challan->organization_id, $uuid))) {
            return $existing;
        }

        return DB::transaction(fn () => Idempotent::create(
            fn () => $this->postReport($challan, $data, $by),
            fn () => $this->find($challan->organization_id, (string) $uuid),
        ), 3);
    }

    public function reverse(MaterialShortDelivery $short, string $reason, User $by): MaterialShortDelivery
    {
        return DB::transaction(function () use ($short, $reason, $by) {
            $short = MaterialShortDelivery::lockForUpdate()->findOrFail($short->id);

            if ($short->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This document has already been reversed.')]);
            }

            if ($short->ledger_posted) {
                $this->ledger->reverse($short, $by);
            }

            $short->update(['status' => 'REVERSED', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);

            return $short;
        });
    }

    private function postReport(DeliveryChallan $challan, array $data, User $by): MaterialShortDelivery
    {
        $challan = DeliveryChallan::with('material.conversions')->lockForUpdate()->findOrFail($challan->id);

        if ($challan->isReversed()) {
            throw ValidationException::withMessages(['status' => __('This challan has been reversed.')]);
        }

        $base = $this->units->toBase($challan->material, $data['short_qty'], $challan->unit_id);
        $alreadyShort = MaterialShortDelivery::where('delivery_challan_id', $challan->id)
            ->where('status', 'POSTED')
            ->where('ledger_posted', true)
            ->sum('short_base_qty');

        if (Dec::of($alreadyShort)->plus(Dec::of($base))->isGreaterThan(Dec::of($challan->base_qty))) {
            throw ValidationException::withMessages(['short_qty' => __('A shortage cannot be more than the quantity on this challan.')]);
        }

        $row = MaterialShortDelivery::create([
            'organization_id' => $challan->organization_id,
            'project_id' => $challan->project_id,
            'party_id' => $challan->party_id,
            'delivery_challan_id' => $challan->id,
            'material_id' => $challan->material_id,
            'unit_id' => $challan->unit_id,
            'short_qty' => Dec::qty($data['short_qty']),
            'short_base_qty' => $base,
            'found_on' => $data['found_on'],
            'ledger_posted' => true,
            'note' => $data['note'] ?? null,
            'status' => 'POSTED',
            'client_uuid' => $data['client_uuid'] ?? null,
            'created_by' => $by->id,
        ]);

        $this->ledger->post($challan->organization_id, [[
            'project_id' => $challan->project_id,
            'store_id' => $challan->store_id,
            'material_id' => $challan->material_id,
            'type' => StockLedgerService::GRN,
            'qty' => Dec::qty(Dec::of($base)->negated()),
            'date' => $data['found_on'],
            'source' => $row,
            'key' => 'challan_short:'.$row->id,
        ]], $by);

        $this->alertIfRepeated($row);
        // A shortage opens a gap that needs a written reason, so the people who can write it are pushed.
        $this->notifier->shortDelivery($row, $by);

        return $row;
    }

    /** Same supplier short more than once: tell whoever keeps the supplier records. */
    private function alertIfRepeated(MaterialShortDelivery $row): void
    {
        $supplier = $row->supplier;
        $count = $this->suppliers->summary($supplier)['short_deliveries'];

        if ($count < SupplierRecordService::WARN_AT) {
            return;
        }

        $recipients = User::where('organization_id', $row->organization_id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('material_control.suppliers'));

        Notification::send($recipients, new ProcurementAlert(
            __('Repeat short delivery: :supplier', ['supplier' => $supplier->name]),
            __(':supplier has now delivered short :count times.', ['supplier' => $supplier->name, 'count' => $count]),
            route('material_control.suppliers.show', $supplier),
        ));
    }

    private function find(int $organizationId, string $uuid): ?MaterialShortDelivery
    {
        return ClientUuidRepeat::reachable(MaterialShortDelivery::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
