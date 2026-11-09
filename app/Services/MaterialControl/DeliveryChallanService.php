<?php

namespace App\Services\MaterialControl;

use App\Models\AuditLog;
use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\MaterialShortDelivery;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Services\Procurement\DocumentNumbers;
use App\Services\Procurement\ProcurementRules;
use App\Services\Procurement\StockLedgerService;
use App\Services\Procurement\UnitConverter;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** A delivery taken on site with no purchase order. It posts to the same ledger as a GRN. */
class DeliveryChallanService
{
    private const PHOTO_RULE = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'];

    private const BILL_RULE = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'];

    public function __construct(
        private StockLedgerService $ledger,
        private UnitConverter $units,
        private DocumentNumbers $numbers,
        private SiteStores $stores,
        private ShortDeliveryService $shorts,
    ) {}

    /** One rule set for the web form and the mobile API. */
    public function rules(Project $project, bool $canAttachBill = false): array
    {
        $rules = [
            'party_id' => ['bail', 'nullable', 'required_without:supplier_name', 'integer', Rule::exists('parties', 'id')->where('organization_id', Auth::user()->organization_id)->where('type', 'vendor')->whereNull('deleted_at')],
            'supplier_name' => ['nullable', 'required_without:party_id', 'string', 'max:160'],
            'material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'unit_id' => ['bail', 'nullable', 'integer', org_exists('material_units')],
            'store_id' => ['bail', 'nullable', 'integer', Rule::exists('material_stores', 'id')->where('project_id', $project->id)],
            'qty' => ProcurementRules::qty(),
            'challan_qty' => ProcurementRules::optionalQty(),
            'challan_date' => ['required', 'date', 'before_or_equal:today'],
            'challan_no' => ['nullable', 'string', 'max:60'],
            'vehicle_no' => ['nullable', 'string', 'max:30'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'photo' => self::PHOTO_RULE,
            'client_uuid' => ProcurementRules::clientUuid(),
        ];

        return $canAttachBill ? [...$rules, 'bill' => self::BILL_RULE] : $rules;
    }

    /** Rules for attaching a bill. The figures on it are only for people who hold money.view. */
    public function billRules(Project $project, bool $canSeeMoney): array
    {
        $rules = ['bill' => self::BILL_RULE];

        if ($canSeeMoney) {
            $rules += [
                'bill_no' => ['nullable', 'string', 'max:60'],
                'bill_date' => ['nullable', 'date', 'before_or_equal:today'],
                'bill_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999'],
                'vendor_bill_id' => ['bail', 'nullable', 'integer', Rule::exists('vendor_bills', 'id')->where('project_id', $project->id)],
            ];
        }

        return $rules;
    }

    /** Post a delivery. A repeated client_uuid returns the original and adds no stock. */
    public function create(Project $project, array $data, User $by): DeliveryChallan
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($project->organization_id, $uuid))) {
            return $existing;
        }

        $folder = 'org-'.$project->organization_id.'/material-challans/'.$project->id;
        $photo = isset($data['photo']) && $data['photo'] instanceof UploadedFile ? $data['photo']->store($folder) : null;
        $bill = isset($data['bill']) && $data['bill'] instanceof UploadedFile ? $data['bill']->store($folder) : null;

        try {
            $challan = DB::transaction(fn () => Idempotent::create(
                fn () => $this->post($project, $data, $by, $photo, $bill),
                fn () => $this->find($project->organization_id, (string) $uuid),
            ), 3);
        } catch (Throwable $exception) {
            $this->discard($photo, $bill);

            throw $exception;
        }

        if (! $challan->wasRecentlyCreated) {
            $this->discard($photo, $bill);
        }

        return $challan;
    }

    public function attachBill(DeliveryChallan $challan, array $data, User $by): DeliveryChallan
    {
        $file = $data['bill'] ?? null;

        if (! $file && blank($data['bill_no'] ?? null) && blank($data['vendor_bill_id'] ?? null)) {
            throw ValidationException::withMessages(['bill' => __('Attach the bill, or enter its number.')]);
        }

        $path = $file instanceof UploadedFile ? $file->store('org-'.$challan->organization_id.'/material-challans/'.$challan->project_id) : null;

        return DB::transaction(function () use ($challan, $data, $by, $path) {
            $challan = DeliveryChallan::lockForUpdate()->findOrFail($challan->id);

            if ($challan->isReversed()) {
                $this->discard($path);

                throw ValidationException::withMessages(['status' => __('This challan has been reversed.')]);
            }

            if ($path && $challan->bill_path) {
                Storage::delete($challan->bill_path);
            }

            $challan->update([
                'bill_status' => 'attached',
                'bill_path' => $path ?? $challan->bill_path,
                'bill_no' => $data['bill_no'] ?? $challan->bill_no,
                'bill_date' => $data['bill_date'] ?? $challan->bill_date,
                'bill_amount' => $data['bill_amount'] ?? $challan->bill_amount,
                'vendor_bill_id' => $data['vendor_bill_id'] ?? $challan->vendor_bill_id,
                'bill_attached_by' => $by->id,
                'bill_attached_at' => now(),
            ]);

            return $challan;
        });
    }

    /** Undo a delivery with reversal entries. Refused if the stock has since been used. */
    public function reverse(DeliveryChallan $challan, string $reason, User $by): DeliveryChallan
    {
        return DB::transaction(function () use ($challan, $reason, $by) {
            $challan = DeliveryChallan::lockForUpdate()->findOrFail($challan->id);

            if ($challan->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This document has already been reversed.')]);
            }

            if ($challan->shortDeliveries()->where('status', 'POSTED')->where('ledger_posted', true)->exists()) {
                throw ValidationException::withMessages(['status' => __('Reverse the short delivery on this challan first.')]);
            }

            $this->ledger->reverse($challan, $by);
            $stamp = ['status' => 'REVERSED', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reversal_reason' => $reason];
            $challan->update($stamp);
            $challan->shortDeliveries()->where('status', 'POSTED')->get()->each(fn (MaterialShortDelivery $short) => $short->update($stamp));

            return $challan;
        });
    }

    public function find(int $organizationId, string $uuid): ?DeliveryChallan
    {
        return ClientUuidRepeat::reachable(DeliveryChallan::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    private function post(Project $project, array $data, User $by, ?string $photo, ?string $bill): DeliveryChallan
    {
        $organizationId = $project->organization_id;
        $material = Material::with('conversions')->findOrFail($data['material_id']);
        $unitId = $data['unit_id'] ?? $material->base_unit_id;
        $base = $this->units->toBase($material, $data['qty'], $unitId);
        $store = $this->stores->resolve($project, $data['store_id'] ?? null);
        $supplier = $this->supplier($organizationId, $data);

        $challan = DeliveryChallan::create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'party_id' => $supplier->id,
            'store_id' => $store->id,
            'material_id' => $material->id,
            'unit_id' => $unitId,
            'number' => $this->numbers->next($organizationId, 'DC'),
            'challan_no' => $data['challan_no'] ?? null,
            'challan_date' => $data['challan_date'],
            'vehicle_no' => $data['vehicle_no'] ?? null,
            'qty' => Dec::qty($data['qty']),
            'base_qty' => $base,
            'challan_qty' => isset($data['challan_qty']) ? Dec::qty($data['challan_qty']) : null,
            'photo_path' => $photo,
            'bill_status' => $bill ? 'attached' : 'pending',
            'bill_path' => $bill,
            'bill_attached_by' => $bill ? $by->id : null,
            'bill_attached_at' => $bill ? now() : null,
            'remarks' => $data['remarks'] ?? null,
            'status' => 'POSTED',
            'client_uuid' => $data['client_uuid'] ?? null,
            'created_by' => $by->id,
        ]);

        $this->ledger->post($organizationId, [[
            'project_id' => $project->id,
            'store_id' => $store->id,
            'material_id' => $material->id,
            'type' => StockLedgerService::GRN,
            'qty' => $base,
            'date' => $data['challan_date'],
            'source' => $challan,
            'key' => 'challan:'.$challan->id,
        ]], $by);

        AuditLog::record('delivery_challan.posted', $challan, null, ['supplier' => $supplier->name, 'qty' => $challan->qty]);

        // Counted less than the challan says: only the counted quantity went into stock.
        if (isset($data['challan_qty']) && Dec::of($data['challan_qty'])->isGreaterThan(Dec::of($data['qty']))) {
            $this->shorts->recordAtEntry($challan, $material, $by);
        }

        return $challan;
    }

    private function supplier(int $organizationId, array $data): Party
    {
        if (! empty($data['party_id'])) {
            return Party::where('type', 'vendor')->findOrFail($data['party_id']);
        }

        return Party::firstOrCreate([
            'organization_id' => $organizationId,
            'type' => 'vendor',
            'name' => trim($data['supplier_name']),
        ]);
    }

    private function discard(?string ...$paths): void
    {
        foreach (array_filter($paths) as $path) {
            Storage::delete($path);
        }
    }
}
