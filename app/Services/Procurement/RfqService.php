<?php

namespace App\Services\Procurement;

use App\Models\AuditLog;
use App\Models\Indent;
use App\Models\PurchaseOrder;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\RfqQuotation;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/** Request for quotation: invite vendors, record their rates, compare, award. */
class RfqService
{
    public function __construct(private DocumentNumbers $numbers) {}

    public function createRules(): array
    {
        return [
            'due_date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'client_uuid' => ProcurementRules::clientUuid(),
            'party_ids' => ['required', 'array', 'min:1', 'max:30'],
            'party_ids.*' => ['bail', 'required', 'integer', $this->vendorExists()],
        ];
    }

    public function vendorRules(): array
    {
        return ['party_id' => ['bail', 'required', 'integer', $this->vendorExists()]];
    }

    public function quotationRules(): array
    {
        return [
            'party_id' => ['bail', 'required', 'integer', $this->vendorExists()],
            'quote_ref' => ['nullable', 'string', 'max:60'],
            'quote_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'delivery_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'payment_terms' => ['nullable', 'string', 'max:160'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.rfq_item_id' => ['bail', 'required', 'integer', org_exists('rfq_items')],
            'items.*.rate' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999'],
            'items.*.gst_pct' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
        ];
    }

    public function awardRules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'picks' => ['required', 'array', 'min:1', 'max:200'],
            'picks.*.rfq_item_id' => ['bail', 'required', 'integer', org_exists('rfq_items')],
            'picks.*.quotation_id' => ['bail', 'required', 'integer', org_exists('rfq_quotations')],
        ];
    }

    /** Float an RFQ for an approved indent. Only one can be open per indent. */
    public function createFromIndent(Indent $indent, array $data, User $by): Rfq
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? Rfq::where('organization_id', $indent->organization_id)->where('client_uuid', $uuid)->first() : null;

        if ($existing = $find()) {
            return $existing;
        }

        return Idempotent::create(function () use ($indent, $data, $by, $uuid, $find) {
            $indent = Indent::with('items')->lockForUpdate()->findOrFail($indent->id);

            if ($existing = $find()) {
                return $existing;
            }

            if ($indent->status !== 'APPROVED') {
                throw ValidationException::withMessages(['indent' => __('Only approved indents can go out for quotation.')]);
            }

            if ($indent->rfqs()->where('status', 'OPEN')->exists()) {
                throw ValidationException::withMessages(['indent' => __('This indent already has an open RFQ.')]);
            }

            $rfq = Rfq::create([
                'organization_id' => $indent->organization_id,
                'project_id' => $indent->project_id,
                'indent_id' => $indent->id,
                'number' => $this->numbers->next($indent->organization_id, 'RFQ'),
                'due_date' => $data['due_date'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'status' => 'OPEN',
                'client_uuid' => $uuid,
                'created_by' => $by->id,
            ]);

            foreach ($indent->items as $line) {
                $rfq->items()->create([
                    'organization_id' => $rfq->organization_id,
                    'indent_item_id' => $line->id,
                    'material_id' => $line->material_id,
                    'qty' => $line->qty,
                    'unit_id' => $line->unit_id,
                    'base_qty' => $line->base_qty,
                ]);
            }

            foreach (array_unique($data['party_ids']) as $partyId) {
                $this->invite($rfq, (int) $partyId);
            }

            return $rfq;
        }, $find);
    }

    public function addVendor(Rfq $rfq, int $partyId): RfqQuotation
    {
        $this->assertOpen($rfq);

        return $this->invite($rfq, $partyId);
    }

    /** Record or replace a vendor's rates. Saving the same rates twice changes nothing. */
    public function saveQuotation(Rfq $rfq, array $data): RfqQuotation
    {
        return DB::transaction(function () use ($rfq, $data) {
            $rfq = Rfq::lockForUpdate()->findOrFail($rfq->id);
            $this->assertOpen($rfq);

            $quotation = $this->invite($rfq, (int) $data['party_id']);

            if (RfqItem::where('awarded_quotation_id', $quotation->id)->exists()) {
                throw ValidationException::withMessages(['party_id' => __('This quotation has already been awarded and cannot change.')]);
            }

            $lines = RfqItem::where('rfq_id', $rfq->id)->get()->keyBy('id');
            $total = Dec::of(0);
            $quotation->items()->delete();

            foreach ($data['items'] as $input) {
                $line = $lines->get($input['rfq_item_id']);

                if (! $line) {
                    throw ValidationException::withMessages(['items' => __('A rate was given for an item that is not on this RFQ.')]);
                }

                $amount = Dec::money(Dec::of($line->qty)->multipliedBy(Dec::of($input['rate'])));
                $gst = Dec::percentOf($amount, $input['gst_pct']);
                $total = $total->plus($amount)->plus($gst);

                $quotation->items()->create([
                    'organization_id' => $rfq->organization_id,
                    'rfq_item_id' => $line->id,
                    'rate' => Dec::money($input['rate']),
                    'gst_pct' => $input['gst_pct'],
                    'amount' => $amount,
                    'gst_amount' => $gst,
                ]);
            }

            $quotation->update([
                'status' => 'RECEIVED',
                'quote_ref' => $data['quote_ref'] ?? null,
                'quote_date' => $data['quote_date'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'delivery_days' => $data['delivery_days'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'total' => Dec::money($total),
            ]);

            return $quotation;
        });
    }

    /**
     * Rates side by side. L1 on each line is the lowest delivered cost including GST,
     * among quotations that have not expired. The first quoted wins a tie.
     *
     * @return array{vendors: Collection<int, RfqQuotation>, lines: list<array<string, mixed>>, cheapest_full: ?int}
     */
    public function compare(Rfq $rfq): array
    {
        $rfq->load('items.material', 'items.unit', 'quotations.vendor', 'quotations.items');
        $vendors = $rfq->quotations->where('status', 'RECEIVED')->values();

        $lines = $rfq->items->map(function (RfqItem $item) use ($vendors) {
            $cells = [];
            $lowest = null;
            $l1 = null;

            foreach ($vendors as $quotation) {
                $quote = $quotation->items->firstWhere('rfq_item_id', $item->id);

                if (! $quote) {
                    continue;
                }

                $total = Dec::money(Dec::of($quote->amount)->plus(Dec::of($quote->gst_amount)));
                $cells[$quotation->id] = ['rate' => $quote->rate, 'gst_pct' => $quote->gst_pct, 'amount' => $quote->amount, 'total' => $total];

                if (! $quotation->isExpired() && ($lowest === null || Dec::of($total)->isLessThan(Dec::of($lowest)))) {
                    $lowest = $total;
                    $l1 = $quotation->id;
                }
            }

            return ['item' => $item, 'cells' => $cells, 'l1' => $l1];
        })->all();

        // The cheapest single vendor who quoted every line, for a one-vendor award.
        $cheapestFull = $vendors
            ->filter(fn (RfqQuotation $quotation) => ! $quotation->isExpired() && $quotation->items->count() === $rfq->items->count())
            ->sort(fn ($a, $b) => Dec::of($a->total)->compareTo(Dec::of($b->total)) ?: $a->id <=> $b->id)
            ->first()?->id;

        return ['vendors' => $vendors, 'lines' => $lines, 'cheapest_full' => $cheapestFull];
    }

    /**
     * Award lines to vendors. Makes one draft PO per vendor.
     * A repeated client_uuid returns the POs it made and changes nothing.
     *
     * @param  list<array{rfq_item_id:int|string, quotation_id:int|string}>  $picks
     * @return Collection<int, PurchaseOrder>
     */
    public function award(Rfq $rfq, array $picks, string $clientUuid, User $by): Collection
    {
        return DB::transaction(function () use ($rfq, $picks, $clientUuid, $by) {
            $rfq = Rfq::with('items', 'indent', 'quotations.items')->lockForUpdate()->findOrFail($rfq->id);
            $quotations = $rfq->quotations->keyBy('id');
            $chosen = collect($picks)->map(fn ($pick) => $quotations->get($pick['quotation_id']));

            if ($chosen->contains(null)) {
                throw ValidationException::withMessages(['picks' => __('A chosen quotation is not on this RFQ.')]);
            }

            $uuids = $chosen->pluck('party_id')->unique()->mapWithKeys(fn ($partyId) => [
                $partyId => Uuid::uuid5($clientUuid, 'vendor:'.$partyId)->toString(),
            ]);

            $existing = PurchaseOrder::where('organization_id', $rfq->organization_id)->whereIn('client_uuid', $uuids->values())->orderBy('id')->get();

            if ($existing->isNotEmpty()) {
                return $existing;
            }

            $this->assertOpen($rfq);

            $lines = $rfq->items->keyBy('id');
            $byVendor = [];

            foreach ($picks as $pick) {
                $line = $lines->get($pick['rfq_item_id']);
                $quotation = $quotations->get($pick['quotation_id']);
                $quote = $quotation->items->firstWhere('rfq_item_id', $pick['rfq_item_id']);

                if (! $line || ! $quote) {
                    throw ValidationException::withMessages(['picks' => __('A chosen vendor did not quote that item.')]);
                }

                if ($line->awarded_quotation_id !== null) {
                    throw ValidationException::withMessages(['picks' => __('One of these items has already been awarded.')]);
                }

                if ($quotation->isExpired()) {
                    throw ValidationException::withMessages(['picks' => __('The quotation from :vendor has expired.', ['vendor' => $quotation->vendor->name])]);
                }

                $byVendor[$quotation->party_id][] = [$line, $quote, $quotation];
            }

            $orders = collect($byVendor)->map(fn ($rows, $partyId) => $this->createOrder($rfq, (int) $partyId, $rows, $uuids[$partyId], $by))->values();

            if (RfqItem::where('rfq_id', $rfq->id)->whereNull('awarded_quotation_id')->doesntExist()) {
                $rfq->update(['status' => 'AWARDED']);
            }

            return $orders;
        });
    }

    public function close(Rfq $rfq): Rfq
    {
        return DB::transaction(function () use ($rfq) {
            $rfq = Rfq::lockForUpdate()->findOrFail($rfq->id);
            $this->assertOpen($rfq);
            $rfq->update(['status' => 'CLOSED']);

            return $rfq;
        });
    }

    /** @param list<array{0: RfqItem, 1: mixed, 2: RfqQuotation}> $rows */
    private function createOrder(Rfq $rfq, int $partyId, array $rows, string $uuid, User $by): PurchaseOrder
    {
        $subtotal = Dec::of(0);
        $gst = Dec::of(0);

        $order = PurchaseOrder::create([
            'organization_id' => $rfq->organization_id,
            'project_id' => $rfq->project_id,
            'rfq_id' => $rfq->id,
            'party_id' => $partyId,
            'store_id' => $rfq->indent->store_id,
            'number' => $this->numbers->next($rfq->organization_id, 'PO'),
            'po_date' => today(),
            'delivery_by' => $rfq->indent->required_by,
            'status' => 'DRAFT',
            'client_uuid' => $uuid,
            'created_by' => $by->id,
        ]);

        foreach ($rows as [$line, $quote, $quotation]) {
            $order->items()->create([
                'organization_id' => $rfq->organization_id,
                'rfq_item_id' => $line->id,
                'material_id' => $line->material_id,
                'qty' => $line->qty,
                'unit_id' => $line->unit_id,
                'base_qty' => $line->base_qty,
                'rate' => $quote->rate,
                'gst_pct' => $quote->gst_pct,
                'amount' => $quote->amount,
                'gst_amount' => $quote->gst_amount,
            ]);

            $line->update(['awarded_quotation_id' => $quotation->id]);
            $subtotal = $subtotal->plus(Dec::of($quote->amount));
            $gst = $gst->plus(Dec::of($quote->gst_amount));
        }

        $order->update([
            'subtotal' => Dec::money($subtotal),
            'gst_amount' => Dec::money($gst),
            'total' => Dec::money($subtotal->plus($gst)),
        ]);

        AuditLog::record('purchase_order.awarded', $order, null, ['rfq' => $rfq->number, 'lines' => count($rows)]);

        return $order;
    }

    private function invite(Rfq $rfq, int $partyId): RfqQuotation
    {
        return RfqQuotation::firstOrCreate(
            ['rfq_id' => $rfq->id, 'party_id' => $partyId],
            ['organization_id' => $rfq->organization_id, 'status' => 'INVITED'],
        );
    }

    private function assertOpen(Rfq $rfq): void
    {
        if (! $rfq->isOpen()) {
            throw ValidationException::withMessages(['status' => __('This RFQ is no longer open.')]);
        }
    }

    private function vendorExists(): mixed
    {
        return Rule::exists('parties', 'id')
            ->where('organization_id', Auth::user()->organization_id)
            ->where('type', 'vendor')
            ->whereNull('deleted_at');
    }
}
