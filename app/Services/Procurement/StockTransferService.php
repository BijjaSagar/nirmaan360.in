<?php

namespace App\Services\Procurement;

use App\Models\AuditLog;
use App\Models\Material;
use App\Models\MaterialStore;
use App\Models\Project;
use App\Models\StockTransfer;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Two steps: stock leaves on dispatch and arrives on receipt. In between it is in transit. */
class StockTransferService
{
    public function __construct(
        private StockLedgerService $ledger,
        private UnitConverter $units,
        private DocumentNumbers $numbers,
    ) {}

    public function rules(Project $project): array
    {
        return [
            'from_store_id' => ['bail', 'required', 'integer', Rule::exists('material_stores', 'id')->where('project_id', $project->id)],
            'to_store_id' => ['bail', 'required', 'integer', 'different:from_store_id', org_exists('material_stores')],
            'dispatch_date' => ['required', 'date', 'before_or_equal:today'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'client_uuid' => ProcurementRules::clientUuid(),
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'items.*.qty' => ProcurementRules::qty(),
            'items.*.unit_id' => ['bail', 'nullable', 'integer', org_exists('material_units')],
        ];
    }

    public function receiveRules(): array
    {
        return [
            'receive_date' => ['required', 'date', 'before_or_equal:today'],
            'items' => ['sometimes', 'array', 'max:200'],
            'items.*.line_id' => ['bail', 'required', 'integer', org_exists('stock_transfer_items')],
            'items.*.received_qty' => ProcurementRules::optionalQty(),
        ];
    }

    public function dispatch(Project $project, array $data, User $by): StockTransfer
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($project->organization_id, $uuid))) {
            return $existing;
        }

        return DB::transaction(fn () => Idempotent::create(
            fn () => $this->postDispatch($project, $data, $by),
            fn () => $this->find($project->organization_id, (string) $uuid),
        ), 3);
    }

    /**
     * Book the goods in at the destination. received_qty is in base units and defaults to
     * everything dispatched. Anything short stays recorded on the line as lost in transit.
     *
     * @param  array<int, mixed>  $receivedByLine  Line id => received base quantity.
     */
    public function receive(StockTransfer $transfer, string $date, array $receivedByLine, User $by): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $date, $receivedByLine, $by) {
            $transfer = StockTransfer::with('items')->lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->status !== 'DISPATCHED') {
                throw ValidationException::withMessages(['status' => __('This transfer is not in transit.')]);
            }

            $movements = [];

            foreach ($transfer->items as $line) {
                $received = Dec::of($receivedByLine[$line->id] ?? $line->base_qty);

                if ($received->isNegative() || $received->isGreaterThan(Dec::of($line->base_qty))) {
                    throw ValidationException::withMessages(['received_qty' => __('Received quantity cannot be more than was dispatched.')]);
                }

                $line->update(['received_base_qty' => Dec::qty($received)]);

                if ($received->isPositive()) {
                    $movements[] = [
                        'project_id' => $transfer->to_project_id,
                        'store_id' => $transfer->to_store_id,
                        'material_id' => $line->material_id,
                        'type' => StockLedgerService::TRANSFER_IN,
                        'qty' => Dec::qty($received),
                        'date' => $date,
                        'source' => $transfer,
                        'key' => 'transfer_item:'.$line->id.':in',
                    ];
                }
            }

            $this->ledger->post($transfer->organization_id, $movements, $by);
            $transfer->update(['status' => 'RECEIVED', 'receive_date' => $date, 'received_by' => $by->id]);

            return $transfer;
        });
    }

    /** In transit: puts the stock back. Already received: also takes it out of the destination. */
    public function reverse(StockTransfer $transfer, string $reason, User $by): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $reason, $by) {
            $transfer = StockTransfer::lockForUpdate()->findOrFail($transfer->id);

            if ($transfer->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This document has already been reversed.')]);
            }

            $this->ledger->reverse($transfer, $by);
            $transfer->update(['status' => 'REVERSED', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);

            return $transfer;
        });
    }

    private function postDispatch(Project $project, array $data, User $by): StockTransfer
    {
        $organizationId = $project->organization_id;
        $uuid = $data['client_uuid'] ?? null;
        $materials = Material::with('conversions')->whereIn('id', array_column($data['items'], 'material_id'))->get()->keyBy('id');
        $destination = MaterialStore::findOrFail($data['to_store_id']);

        $this->ledger->lockPairs($organizationId, array_map(
            fn ($line) => ['store_id' => $data['from_store_id'], 'material_id' => $line['material_id']],
            $data['items'],
        ));

        if ($uuid && ($existing = $this->find($organizationId, $uuid))) {
            return $existing;
        }

        $transfer = StockTransfer::create([
            'organization_id' => $organizationId,
            'from_project_id' => $project->id,
            'to_project_id' => $destination->project_id,
            'from_store_id' => $data['from_store_id'],
            'to_store_id' => $destination->id,
            'number' => $this->numbers->next($organizationId, 'ST'),
            'dispatch_date' => $data['dispatch_date'],
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'status' => 'DISPATCHED',
            'client_uuid' => $uuid,
            'created_by' => $by->id,
        ]);

        $movements = [];

        foreach ($data['items'] as $input) {
            $material = $materials->get($input['material_id']);
            $unitId = $input['unit_id'] ?? $material->base_unit_id;
            $base = $this->units->toBase($material, $input['qty'], $unitId);

            $line = $transfer->items()->create([
                'organization_id' => $organizationId,
                'material_id' => $material->id,
                'qty' => Dec::qty($input['qty']),
                'unit_id' => $unitId,
                'base_qty' => $base,
            ]);

            $movements[] = [
                'project_id' => $project->id,
                'store_id' => $transfer->from_store_id,
                'material_id' => $material->id,
                'type' => StockLedgerService::TRANSFER_OUT,
                'qty' => Dec::qty(Dec::of($base)->negated()),
                'date' => $data['dispatch_date'],
                'source' => $transfer,
                'key' => 'transfer_item:'.$line->id.':out',
            ];
        }

        $this->ledger->post($organizationId, $movements, $by);
        AuditLog::record('stock_transfer.dispatched', $transfer, null, ['lines' => count($movements)]);

        return $transfer;
    }

    private function find(int $organizationId, string $uuid): ?StockTransfer
    {
        return StockTransfer::where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
