<?php

namespace App\Services\SlipReading;

use App\Models\AuditLog;
use App\Models\DeliveryChallan;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\Material;
use App\Models\Project;
use App\Models\SlipRead;
use App\Models\User;
use App\Models\VendorBill;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The server's side of "touch every field the photo filled". The three save paths that can take a
 * photo reading (a new challan, a bill attached to a challan, a vendor bill) call check() before
 * they write and used() once the record exists, in the same transaction.
 *
 * check(): the reading must be this person's, of this company and project, of the right kind, and
 * ready (or already used by this same record, for a retry). Then every field the reading filled is
 * compared with what was submitted, canonically: numbers through Dec, dates as Y-m-d, text trimmed
 * in upper case, ids as integers. Equal and not named in read_confirmed is refused on that field;
 * different is recorded as typed over. Each warning a person must tick has to be ticked.
 *
 * A form that sends no read_id is not checked at all, so nothing changes for a form filled by hand.
 */
class SlipLink
{
    public const CHALLAN = 'challan';

    public const CHALLAN_BILL = 'challan_bill';

    public const VENDOR_BILL = 'vendor_bill';

    /** The kind of slip each save path takes. */
    public const SLIP_KINDS = [self::CHALLAN => SlipRead::CHALLAN, self::CHALLAN_BILL => SlipRead::BILL, self::VENDOR_BILL => SlipRead::BILL];

    /** A ticked warning is sent in read_confirmed as this prefix and its code. */
    public const TICK = 'warning:';

    /**
     * The three keys each save path adds to its rules.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'read_id' => ['bail', 'nullable', 'integer', org_exists('slip_reads')],
            'read_confirmed' => ['nullable', 'array', 'max:60'],
            'read_confirmed.*' => ['string', 'max:60'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  The validated form.
     * @param  ?User  $by  The person saving. With nobody, a form that names a reading is refused.
     * @param  ?Model  $record  The record being saved, when it exists already (a challan getting its bill, a bill being changed).
     * @return ?LinkedSlip Null when the form names no reading.
     *
     * @throws ValidationException
     */
    public function check(Project $project, array $data, ?User $by, string $context, ?Model $record = null): ?LinkedSlip
    {
        if (blank($data['read_id'] ?? null)) {
            return null;
        }

        $slip = $by === null ? null : SlipRead::withoutGlobalScopes()->where('organization_id', $by->organization_id)->find((int) $data['read_id']);

        // A bill reading is money: someone who no longer sees money cannot save a form with one.
        if ($by === null || $slip === null || (int) $slip->project_id !== (int) $project->id || (int) $slip->user_id !== (int) $by->id
            || $slip->kind !== (self::SLIP_KINDS[$context] ?? null) || ! app(SlipReadService::class)->mayUseKind($by, $slip->kind)) {
            throw ValidationException::withMessages(['read_id' => __('This photo reading cannot be used for this form.')]);
        }

        if (! $this->usable($slip, $context, $data, $record)) {
            throw ValidationException::withMessages(['read_id' => __('This photo reading can no longer be used. Read the photo again or type the details yourself.')]);
        }

        $sent = array_values(array_unique(array_map('strval', array_filter((array) ($data['read_confirmed'] ?? []), 'is_scalar'))));
        $errors = [];
        $confirmed = [];
        $typedOver = [];

        foreach ($this->offers($slip, $context, $data) as $field => $offer) {
            if ($offer['values'] === []) {
                continue;
            }

            if ($offer['submitted'] !== null && in_array($offer['submitted'], $offer['values'], true)) {
                in_array($field, $sent, true)
                    ? $confirmed[] = $field
                    : $errors[$field] = __('Confirm this against the photo.');
            } else {
                $typedOver[] = $field;
            }
        }

        $ticked = [];

        foreach ($slip->warnings ?? [] as $warning) {
            if (! ($warning['tick'] ?? false)) {
                continue;
            }

            if (in_array(self::TICK.$warning['code'], $sent, true)) {
                $ticked[] = (string) $warning['code'];
            } else {
                $errors['read_confirmed'] = __('Tick each warning about this photo before saving.');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new LinkedSlip($slip, $context, $confirmed, $typedOver, array_values(array_unique($ticked)));
    }

    /**
     * Marks the reading as used by the record, with the fields confirmed and typed over and who did
     * it, and closes its document read as confirmed. Call it inside the transaction that writes the
     * record, so a reading cannot be used twice and a refused one leaves nothing written.
     *
     * @throws ValidationException The reading was used for another record in the meantime.
     */
    public function used(LinkedSlip $link, Model $record, ?string $clientUuid, User $by): void
    {
        // The read first, then the slip: the order the page reader uses.
        $read = DocumentRead::withoutGlobalScopes()->where('organization_id', $link->slip->organization_id)->lockForUpdate()->find($link->slip->document_read_id);
        $slip = SlipRead::withoutGlobalScopes()->whereKey($link->slip->id)->lockForUpdate()->firstOrFail();

        if ($slip->status === SlipRead::USED && $this->sameRecord($slip, $link->context, $clientUuid, $record)) {
            return;
        }

        if ($slip->status !== SlipRead::READY || $read?->status !== DocumentRead::READY) {
            throw ValidationException::withMessages(['read_id' => __('This photo reading can no longer be used. Read the photo again or type the details yourself.')]);
        }

        $slip->forceFill([
            'status' => SlipRead::USED,
            'used_client_uuid' => $clientUuid,
            'delivery_challan_id' => $record instanceof DeliveryChallan ? $record->id : $slip->delivery_challan_id,
            'vendor_bill_id' => $record instanceof VendorBill ? $record->id : $slip->vendor_bill_id,
            'confirmed' => [
                'context' => $link->context,
                'fields' => $link->confirmed,
                'typed_over' => $link->typedOver,
                'warnings' => $link->ticked,
                'by' => $by->id,
                'at' => now()->toIso8601String(),
            ],
        ])->save();

        $read->forceFill(['status' => DocumentRead::CONFIRMED])->save();

        // Counts and field names only: never a value read from the photo.
        AuditLog::record('ai.read_confirmed', $read, ['status' => DocumentRead::READY], [
            'status' => DocumentRead::CONFIRMED,
            'kind' => $read->kind,
            'context' => $link->context,
            'record_id' => $record->getKey(),
            'fields_confirmed' => count($link->confirmed),
            'fields_typed_over' => count($link->typedOver),
            'warnings_ticked' => count($link->ticked),
        ], $by, (int) $read->organization_id);
    }

    /**
     * Keeps a copy of the photo the reading was made from, for a form saved without a photo of its
     * own. The copy belongs to the record, so it stays when the reading's files are deleted.
     *
     * @return ?string The copy's path, or null when the reading's picture is gone.
     */
    public function copyPhoto(LinkedSlip $link, string $folder): ?string
    {
        $path = DocumentReadPage::withoutGlobalScopes()->where('organization_id', $link->slip->organization_id)
            ->where('document_read_id', $link->slip->document_read_id)->where('page_no', 1)->where('part', 'full')
            ->whereNotNull('image_path')->orderBy('id')->value('image_path');

        if (! is_string($path) || ! Storage::exists($path)) {
            return null;
        }

        $copy = rtrim($folder, '/').'/'.Str::random(40).'.jpg';

        try {
            return Storage::copy($path, $copy) ? $copy : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * The reading a saved record was filled from, if any. For a note to the people who look at or
     * approve the record. A challan can have two: the challan's own, and its bill's (kind bill).
     */
    public function readingOf(Model $record, ?string $kind = null): ?SlipRead
    {
        $column = match (true) {
            $record instanceof DeliveryChallan => 'delivery_challan_id',
            $record instanceof VendorBill => 'vendor_bill_id',
            default => null,
        };

        return $column === null ? null : SlipRead::withoutGlobalScopes()->where('organization_id', $record->getAttribute('organization_id'))
            ->where($column, $record->getKey())->where('status', SlipRead::USED)->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->latest('id')->first();
    }

    private function usable(SlipRead $slip, string $context, array $data, ?Model $record): bool
    {
        if ($slip->status === SlipRead::USED) {
            return $this->sameRecord($slip, $context, $data['client_uuid'] ?? null, $record);
        }

        $read = DocumentRead::withoutGlobalScopes()->where('organization_id', $slip->organization_id)->find($slip->document_read_id);

        return $slip->status === SlipRead::READY && $read?->status === DocumentRead::READY;
    }

    /** A reading already used may be sent again only by the record it was used for, as a retry of the same save. */
    private function sameRecord(SlipRead $slip, string $context, ?string $clientUuid, ?Model $record): bool
    {
        if (filled($clientUuid) && $slip->used_client_uuid === $clientUuid) {
            return true;
        }

        return match ($context) {
            self::CHALLAN_BILL => $record instanceof DeliveryChallan && (int) $slip->delivery_challan_id === (int) $record->getKey(),
            self::VENDOR_BILL => $record instanceof VendorBill && (int) $slip->vendor_bill_id === (int) $record->getKey(),
            default => false,
        };
    }

    /**
     * For each form field the reading can fill: the values it offered and what was submitted, both in
     * their canonical form. A field with no offered value was not filled by the reading.
     *
     * @return array<string, array{values: list<string>, submitted: ?string}>
     */
    private function offers(SlipRead $slip, string $context, array $data): array
    {
        $fields = $slip->fields ?? [];
        $value = fn (string $key) => $fields[$key]['value'] ?? null;
        $one = fn (?string $offered, callable $canonical) => $offered === null ? [] : [$canonical($offered)];
        $text = fn (mixed $submitted) => blank($submitted) ? null : ChallanChecks::plainText((string) $submitted);
        $date = fn (mixed $submitted) => blank($submitted) ? null : Carbon::parse((string) $submitted)->toDateString();
        $money = fn (mixed $submitted) => blank($submitted) || ! is_numeric($submitted) ? null : Dec::money($submitted);
        $id = fn (mixed $submitted) => blank($submitted) ? null : (string) (int) $submitted;
        $party = ($slip->suggestions['party']['id'] ?? null) === null ? [] : [(string) (int) $slip->suggestions['party']['id']];

        return match ($context) {
            self::CHALLAN => $this->challanOffers($slip, $data, $value, $one, $text, $date, $id, $party),
            self::CHALLAN_BILL => [
                'bill_no' => ['values' => $one($value('bill_no'), $text), 'submitted' => $text($data['bill_no'] ?? null)],
                'bill_date' => ['values' => $one($value('bill_date'), $date), 'submitted' => $date($data['bill_date'] ?? null)],
                'bill_amount' => ['values' => $one($value('grand_total'), $money), 'submitted' => $money($data['bill_amount'] ?? null)],
            ],
            self::VENDOR_BILL => [
                'party_id' => ['values' => $party, 'submitted' => $id($data['party_id'] ?? null)],
                'bill_no' => ['values' => $one($value('bill_no'), $text), 'submitted' => $text($data['bill_no'] ?? null)],
                'bill_date' => ['values' => $one($value('bill_date'), $date), 'submitted' => $date($data['bill_date'] ?? null)],
                'taxable_amount' => ['values' => $one($value('taxable_total'), $money), 'submitted' => $money($data['taxable_amount'] ?? null)],
                'gst_pct' => ['values' => $one($value('gst_pct'), $money), 'submitted' => $money($data['gst_pct'] ?? null)],
                'supply_type' => ['values' => $one($value('supply_type'), $text), 'submitted' => $text($data['supply_type'] ?? null)],
            ],
            default => [],
        };
    }

    /**
     * The challan form. The counted quantity is not here: the reading never fills it. The supplier
     * name as read counts only when no supplier was picked, because a picked supplier wins. A unit
     * counts only when it is one of the chosen material's, which is when the form offered it.
     *
     * @param  list<string>  $party
     * @return array<string, array{values: list<string>, submitted: ?string}>
     */
    private function challanOffers(SlipRead $slip, array $data, callable $value, callable $one, callable $text, callable $date, callable $id, array $party): array
    {
        $lines = $slip->lines ?? [];
        $quantities = array_values(array_unique(collect($lines)->pluck('qty.value')->push($value('net_weight'))->filter()->map(fn ($qty) => Dec::qty($qty))->all()));
        $unitIds = array_values(array_unique(collect($lines)->pluck('unit.unit_id')->push($slip->fields['weight_unit']['unit_id'] ?? null)->filter()->map(fn ($unit) => (string) (int) $unit)->all()));
        $materials = collect($slip->suggestions['materials'] ?? [])->pluck('id')->map(fn ($material) => (string) (int) $material)->values()->all();

        $offers = [
            'challan_no' => ['values' => $one($value('challan_no'), $text), 'submitted' => $text($data['challan_no'] ?? null)],
            'challan_date' => ['values' => $one($value('challan_date'), $date), 'submitted' => $date($data['challan_date'] ?? null)],
            'vehicle_no' => ['values' => $one($value('vehicle_no'), ChallanChecks::vehicleNumber(...)), 'submitted' => blank($data['vehicle_no'] ?? null) ? null : ChallanChecks::vehicleNumber((string) $data['vehicle_no'])],
            'party_id' => ['values' => $party, 'submitted' => $id($data['party_id'] ?? null)],
            'material_id' => ['values' => $materials, 'submitted' => $id($data['material_id'] ?? null)],
            'challan_qty' => ['values' => $quantities, 'submitted' => blank($data['challan_qty'] ?? null) || ! is_numeric($data['challan_qty']) ? null : Dec::qty($data['challan_qty'])],
        ];

        if (blank($data['party_id'] ?? null)) {
            $offers['supplier_name'] = ['values' => $one($value('supplier_name'), $text), 'submitted' => $text($data['supplier_name'] ?? null)];
        }

        $material = blank($data['material_id'] ?? null) ? null
            : Material::withoutGlobalScopes()->with('conversions')->where('organization_id', $slip->organization_id)->find((int) $data['material_id']);

        if ($material !== null) {
            $ofMaterial = $material->conversions->pluck('unit_id')->push($material->base_unit_id)->map(fn ($unit) => (string) (int) $unit)->all();
            $offers['unit_id'] = [
                'values' => array_values(array_intersect($unitIds, $ofMaterial)),
                'submitted' => (string) (int) ($data['unit_id'] ?? $material->base_unit_id),
            ];
        }

        return $offers;
    }
}
