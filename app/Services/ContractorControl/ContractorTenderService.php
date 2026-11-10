<?php

namespace App\Services\ContractorControl;

use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\ContractorBid;
use App\Models\ContractorTender;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Procurement\DocumentNumbers;
use App\Services\SiteControl\WorkProgress;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Quotes from several contractors against the BOQ, and the contract that comes of the chosen one. */
class ContractorTenderService
{
    public function __construct(private DocumentNumbers $numbers, private WorkProgress $progress) {}

    public function rules(Project $project): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'scope' => ['nullable', 'string', 'max:5000'],
            'due_on' => ['nullable', 'date'],
            'boq_item_ids' => ['required', 'array', 'min:1'],
            'boq_item_ids.*' => ['bail', 'integer', 'distinct', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function bidRules(ContractorTender $tender): array
    {
        return [
            'party_id' => ['bail', 'nullable', 'integer', 'required_without:new_contractor', Rule::exists('parties', 'id')->where('organization_id', $tender->organization_id)->where('type', 'subcontractor')->whereNull('deleted_at')],
            'new_contractor' => ['nullable', 'string', 'max:160'],
            'new_contractor_phone' => ['nullable', 'string', 'max:20'],
            'quoted_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'rates' => ['nullable', 'array'],
            'rates.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'received_on' => ['nullable', 'date', 'before_or_equal:today'],
            // What the contractor offers besides the price.
            // A quote cannot run out before it was received. With no date given it counts as received today.
            'valid_until' => ['nullable', 'date', request()->filled('received_on') ? 'after_or_equal:received_on' : 'after_or_equal:today'],
            'includes_material' => ['nullable', 'boolean'],
            'completion_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'payment_terms' => ['nullable', 'string', 'max:500'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'quotation' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** For the popup that adds a contractor while a quote is being entered. */
    public function contractorRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190'],
            'gstin' => ['nullable', 'string', 'size:15'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function awardRules(ContractorTender $tender): array
    {
        return [
            'bid_id' => ['bail', 'required', 'integer', Rule::exists('contractor_bids', 'id')->where('contractor_tender_id', $tender->id)->whereNull('superseded_by_id')],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'retention_pct' => ['required', 'numeric', 'between:0,100'],
            'tds_pct' => ['required', 'numeric', 'between:0,100'],
            'gst_pct' => ['required', 'numeric', 'between:0,100'],
            'advance_amount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'advance_recovery_pct' => ['required', 'numeric', 'between:0,100'],
            'deduction_basis' => ['required', Rule::in(['gross', 'running'])],
            'deduction_order' => ['required', Rule::in(array_keys(config('buildos.deduction_orders')))],
            'award_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function create(Project $project, array $data, User $by): ContractorTender
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? $this->findTender($project->organization_id, $uuid) : null;

        if ($existing = $find()) {
            return $existing;
        }

        $items = BoqItem::where('project_id', $project->id)->whereIn('id', $data['boq_item_ids'])->get();

        // Quotes are compared by their totals, so a tender whose lines all have no quantity could never take one.
        if ($items->every(fn (BoqItem $item) => Dec::of($item->qty)->isZero())) {
            throw ValidationException::withMessages(['boq_item_ids' => __('These BOQ lines have no quantity yet. Enter the quantity in the BOQ first, so the quotes can be compared.')]);
        }

        return Idempotent::create(function () use ($project, $data, $items, $by, $uuid) {
            $tender = ContractorTender::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'number' => $this->numbers->next($project->organization_id, 'CT'),
                'title' => $data['title'],
                'scope' => $data['scope'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'status' => 'OPEN',
                'created_by' => $by->id,
                'client_uuid' => $uuid,
            ]);

            foreach ($items as $item) {
                $tender->items()->create([
                    'organization_id' => $project->organization_id,
                    'boq_item_id' => $item->id,
                    'qty' => $item->qty,
                    'boq_rate' => $item->rate,
                ]);
            }

            return $tender;
        }, $find);
    }

    /**
     * Record a quote, either as rates for each item or as one figure for the whole tender.
     * A later quote from the same contractor replaces the earlier one in the comparison
     * and the earlier one is kept. The quotation they sent can be attached.
     */
    public function addBid(ContractorTender $tender, array $data, User $by, ?UploadedFile $quotation = null): ContractorBid
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? $this->findBid($tender->organization_id, $uuid) : null;

        if ($existing = $find()) {
            return $existing;
        }

        $this->assertOpen($tender);
        $tender->loadMissing('items');
        [$rates, $quoted] = $this->pricing($tender, $data);

        // The file is kept only if this request is the one that saves the quote.
        $path = $quotation ? $this->keep($quotation, $tender) : null;
        $file = $path ? ['quotation_path' => $path, 'quotation_name' => mb_substr($quotation->getClientOriginalName(), 0, 255)] : [];

        try {
            $bid = Idempotent::create(function () use ($tender, $data, $by, $uuid, $rates, $quoted, $file) {
                $party = $this->contractor($tender, $data);

                $bid = ContractorBid::create([
                    'organization_id' => $tender->organization_id,
                    'contractor_tender_id' => $tender->id,
                    'party_id' => $party->id,
                    'quoted_amount' => $quoted,
                    'received_on' => $data['received_on'] ?? today()->toDateString(),
                    ...$this->offer($data),
                    ...$file,
                    'entered_by' => $by->id,
                    'client_uuid' => $uuid,
                ]);

                foreach ($tender->items as $item) {
                    $bid->items()->create([
                        'organization_id' => $tender->organization_id,
                        'contractor_tender_item_id' => $item->id,
                        'rate' => $rates[$item->id],
                        'amount' => Dec::money(Dec::of($item->qty)->multipliedBy(Dec::of($rates[$item->id]))),
                    ]);
                }

                ContractorBid::where('contractor_tender_id', $tender->id)
                    ->where('party_id', $party->id)
                    ->whereNull('superseded_by_id')
                    ->whereKeyNot($bid->id)
                    ->update(['superseded_by_id' => $bid->id]);

                return $bid;
            }, $find);
        } catch (Throwable $exception) {
            $this->discard($path);

            throw $exception;
        }

        // A repeat of a quote already saved: the first request's file stands.
        if (! $bid->wasRecentlyCreated) {
            $this->discard($path);
        }

        return $bid;
    }

    /**
     * A subcontractor who can quote. A name the company already has returns that contractor,
     * so asking twice never makes a second copy.
     */
    public function addContractor(int $organizationId, array $data): Party
    {
        $name = trim($data['name']);

        $existing = Party::where('organization_id', $organizationId)
            ->where('type', 'subcontractor')
            // SQLite's lower() folds only A to Z and PostgreSQL's folds every letter, so try both foldings.
            ->where(fn ($query) => $query->where('name', $name)
                ->orWhereRaw('lower(name) = ?', [mb_strtolower($name)])
                ->orWhereRaw('lower(name) = ?', [strtolower($name)]))
            ->orderBy('id')
            ->first();

        return $existing ?? Party::create([
            'organization_id' => $organizationId,
            'type' => 'subcontractor',
            'name' => $name,
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'gstin' => filled($data['gstin'] ?? null) ? strtoupper($data['gstin']) : null,
            'address' => $data['address'] ?? null,
        ]);
    }

    /**
     * Every quote against the BOQ, the current ones first and cheapest first, then the older ones.
     *
     * @return array{boq_amount: string, lowest: ?string, bids: list<array<string, mixed>>}
     */
    public function comparison(ContractorTender $tender): array
    {
        $tender->loadMissing('items.boqItem', 'bids.party', 'bids.items.tenderItem.boqItem', 'bids.enteredBy');
        $boq = $tender->boqAmount();

        $lowest = null;

        foreach ($tender->bids->filter->isCurrent() as $bid) {
            $amount = Dec::of($bid->quoted_amount);
            $lowest = $lowest === null || $amount->isLessThan($lowest) ? $amount : $lowest;
        }

        $rows = $tender->bids->map(fn (ContractorBid $bid) => $this->row($bid, $boq, $lowest))
            ->sortBy(fn (array $row) => [$row['current'] ? 0 : 1, $row['current'] ? (float) $row['amount'] : -$row['bid']->id])
            ->values()
            ->all();

        return ['boq_amount' => $boq, 'lowest' => $lowest ? Dec::money($lowest) : null, 'bids' => $rows];
    }

    /**
     * Accept one quote. The work order is made at that quote's rates, so measurements,
     * RA bills and payments all run on it from here on as on any other contract.
     */
    public function award(ContractorTender $tender, ContractorBid $bid, array $terms, User $by): WorkOrder
    {
        return DB::transaction(function () use ($tender, $bid, $terms, $by) {
            $tender = ContractorTender::lockForUpdate()->findOrFail($tender->id);
            $bid = ContractorBid::findOrFail($bid->id);

            abort_unless($bid->contractor_tender_id === $tender->id, 404);

            if ($tender->status === 'AWARDED' && $bid->is_selected) {
                return WorkOrder::findOrFail($tender->work_order_id);
            }

            $this->assertOpen($tender);

            if (! $bid->isCurrent()) {
                throw ValidationException::withMessages(['bid_id' => __('This quote has been replaced by a newer one.')]);
            }

            $project = Project::lockForUpdate()->findOrFail($tender->project_id);
            $tender->load('items');
            $bid->load('items');

            $workOrder = WorkOrder::create([
                'organization_id' => $tender->organization_id,
                'project_id' => $tender->project_id,
                'party_id' => $bid->party_id,
                'number' => sprintf('WO-%s-%03d', $project->code, WorkOrder::withTrashed()->where('project_id', $project->id)->count() + 1),
                'title' => $tender->title,
                'scope' => $tender->scope,
                'rate_type' => 'item_rate',
                'status' => 'ACTIVE',
                'start_date' => $terms['start_date'] ?? null,
                'end_date' => $terms['end_date'] ?? null,
                'retention_pct' => $terms['retention_pct'],
                'tds_pct' => $terms['tds_pct'],
                'gst_pct' => $terms['gst_pct'],
                'advance_amount' => $terms['advance_amount'],
                'advance_recovery_pct' => $terms['advance_recovery_pct'],
                'deduction_basis' => $terms['deduction_basis'],
                'deduction_order' => explode(',', $terms['deduction_order']),
                'created_by' => $by->id,
            ]);

            $rates = $bid->items->keyBy('contractor_tender_item_id');

            foreach ($tender->items as $item) {
                $workOrder->items()->create([
                    'organization_id' => $tender->organization_id,
                    'boq_item_id' => $item->boq_item_id,
                    'qty' => $item->qty,
                    'rate' => $rates[$item->id]->rate,
                ]);
            }

            $bid->update(['is_selected' => true]);

            $tender->update([
                'status' => 'AWARDED',
                'work_order_id' => $workOrder->id,
                'awarded_by' => $by->id,
                'awarded_at' => now(),
                'award_note' => $terms['award_note'] ?? null,
            ]);

            AuditLog::record('contractor_tender.awarded', $tender, null, ['bid_id' => $bid->id, 'work_order_id' => $workOrder->id]);

            return $workOrder;
        });
    }

    /** The quotes stay on record. */
    public function cancel(ContractorTender $tender): ContractorTender
    {
        $this->assertOpen($tender);
        $tender->update(['status' => 'CANCELLED']);

        return $tender;
    }

    private function row(ContractorBid $bid, string $boq, ?BigDecimal $lowest): array
    {
        $amount = Dec::of($bid->quoted_amount);
        $diff = $amount->minus(Dec::of($boq));

        return [
            'bid' => $bid,
            'contractor' => $bid->party->name,
            'amount' => Dec::money($amount),
            'diff' => Dec::money($diff),
            'diff_pct' => Dec::of($boq)->isZero() ? null : $this->progress->percent($diff, $boq),
            'lowest' => $bid->isCurrent() && $lowest !== null && $amount->isEqualTo($lowest),
            'selected' => $bid->is_selected,
            'current' => $bid->isCurrent(),
        ];
    }

    /** @return array{0: array<int, string>, 1: string} Rate per tender item, and the quoted total. */
    private function pricing(ContractorTender $tender, array $data): array
    {
        $typed = array_filter($data['rates'] ?? [], fn ($rate) => filled($rate));
        $quoted = $data['quoted_amount'] ?? null;

        if ($typed !== []) {
            return $this->fromRates($tender, $typed, $quoted);
        }

        if (blank($quoted)) {
            throw ValidationException::withMessages(['quoted_amount' => __('Enter the quoted amount or a rate for each item.')]);
        }

        return $this->fromTotal($tender, $quoted);
    }

    private function fromRates(ContractorTender $tender, array $typed, mixed $quoted): array
    {
        $rates = [];
        $total = BigDecimal::zero();

        foreach ($tender->items as $item) {
            if (! isset($typed[$item->id])) {
                throw ValidationException::withMessages(['rates' => __('Enter a rate for every item, or leave the rates empty and enter one total.')]);
            }

            $rates[$item->id] = Dec::money($typed[$item->id]);
            $total = $total->plus(Dec::of($item->qty)->multipliedBy(Dec::of($rates[$item->id])));
        }

        if (! $total->isPositive()) {
            throw ValidationException::withMessages(['rates' => __('A quote must be more than zero.')]);
        }

        $total = Dec::money($total);

        if (filled($quoted) && ! Dec::of($quoted)->isEqualTo(Dec::of($total))) {
            throw ValidationException::withMessages(['quoted_amount' => __('The rates add up to :total, not the quoted amount.', ['total' => inr($total)])]);
        }

        return [$rates, $total];
    }

    /** Spread one figure across the items in proportion to their BOQ amounts. */
    private function fromTotal(ContractorTender $tender, mixed $quoted): array
    {
        $boq = Dec::of($tender->boqAmount());

        if ($boq->isZero()) {
            throw ValidationException::withMessages(['quoted_amount' => __('These BOQ items have no amount, so enter a rate for each item.')]);
        }

        $factor = Dec::of($quoted)->dividedBy($boq, 12, RoundingMode::HalfUp);
        $rates = [];

        foreach ($tender->items as $item) {
            $rates[$item->id] = Dec::money(Dec::of($item->boq_rate)->multipliedBy($factor));
        }

        return [$rates, Dec::money($quoted)];
    }

    private function contractor(ContractorTender $tender, array $data): Party
    {
        if (filled($data['party_id'] ?? null)) {
            return Party::findOrFail($data['party_id']);
        }

        return $this->addContractor($tender->organization_id, ['name' => $data['new_contractor'], 'phone' => $data['new_contractor_phone'] ?? null]);
    }

    /** What the contractor offers besides the price. */
    private function offer(array $data): array
    {
        return [
            'valid_until' => $data['valid_until'] ?? null,
            'includes_material' => isset($data['includes_material']) ? (bool) $data['includes_material'] : null,
            'completion_days' => $data['completion_days'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /** Private storage, under the company and the project. A quote is not saved without the file that came with it. */
    private function keep(UploadedFile $quotation, ContractorTender $tender): string
    {
        $path = $quotation->store('org-'.$tender->organization_id.'/contractor-quotations/'.$tender->project_id);

        if ($path === false) {
            throw ValidationException::withMessages(['quotation' => __('The quotation file could not be saved. Try again.')]);
        }

        return $path;
    }

    private function discard(?string $path): void
    {
        if ($path) {
            Storage::delete($path);
        }
    }

    private function assertOpen(ContractorTender $tender): void
    {
        if (! $tender->isOpen()) {
            throw ValidationException::withMessages(['tender' => __('This tender is closed.')]);
        }
    }

    private function findTender(int $organizationId, string $uuid): ?ContractorTender
    {
        return ClientUuidRepeat::reachable(ContractorTender::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    private function findBid(int $organizationId, string $uuid): ?ContractorBid
    {
        return ClientUuidRepeat::reachable(ContractorBid::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
