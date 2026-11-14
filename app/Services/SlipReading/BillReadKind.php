<?php

namespace App\Services\SlipReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\SlipRead;
use App\Services\DocumentReading\Cell;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\DocumentReading\ReaderRequest;

/**
 * The reader for a photo of a supplier's bill, under the "bill" figures in
 * config/modules/document_reading.php. It fills the bill fields of a challan or the vendor bill form
 * before the form is saved; it never saves a bill. Its form carries the printed figures only: the
 * code adds them up (BillChecks), and nothing the model writes is a sum or a rate it worked out.
 *
 * A bill is money, so opening its read needs money.view and the right to enter bills.
 */
class BillReadKind implements ReadKind
{
    use FillsSlip;

    public const KEY = 'bill';

    public const INSTRUCTIONS = <<<'TEXT'
    The picture is a bill or invoice from a supplier of building material or services. Fill the form from it, copying each value exactly as printed.
    - doc_kind: "tax_invoice" for a bill that shows tax, "bill_of_supply" for a bill that shows no tax, "delivery_challan" for a challan or delivery note, "other" for any other paper, "unreadable" when nothing on the picture can be read.
    - supplier_name and supplier_gstin: the firm that issued the bill. buyer_gstin: the GSTIN of the firm the bill is made out to.
    - bill_no and bill_date: the bill or invoice number and the date printed on the bill, as printed.
    - lines: one entry for each item line, from top to bottom, with its description, HSN or SAC code, quantity, unit, rate and amount as printed. Leave the list empty when no item lines can be read.
    - taxable_total: the taxable value or sub-total before tax. cgst, sgst and igst: the tax amount printed under each head, not the rate. round_off: the round off as printed, with its sign. grand_total: the bill total payable. eway_bill_no: the e-way bill number.
    - Copy figures only. Do not add, multiply or work out any figure. A value that is not printed is absent.
    TEXT;

    public function __construct(private BillChecks $checks) {}

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $line = [
            'type' => 'object',
            'properties' => array_fill_keys(BillChecks::LINE_PARTS, Cell::SCHEMA),
            'required' => BillChecks::LINE_PARTS,
            'additionalProperties' => false,
        ];

        $properties = [
            'doc_kind' => ['type' => 'string', 'enum' => BillChecks::DOC_KINDS],
            'supplier_name' => Cell::SCHEMA,
            'supplier_gstin' => Cell::SCHEMA,
            'buyer_gstin' => Cell::SCHEMA,
            'bill_no' => Cell::SCHEMA,
            'bill_date' => Cell::SCHEMA,
            'lines' => ['type' => 'array', 'items' => $line],
            'taxable_total' => Cell::SCHEMA,
            'cgst' => Cell::SCHEMA,
            'sgst' => Cell::SCHEMA,
            'igst' => Cell::SCHEMA,
            'round_off' => Cell::SCHEMA,
            'grand_total' => Cell::SCHEMA,
            'eway_bill_no' => Cell::SCHEMA,
        ];

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function passes(): array
    {
        return ['main'];
    }

    public function limits(): array
    {
        return ['max_pages' => 4, 'min_short_side' => (int) config('modules.slip_reading.min_short_side', 480)];
    }

    public function request(DocumentReadPage $page, ?string $imageBase64, ?string $text): array
    {
        return $imageBase64 !== null
            ? ReaderRequest::image(self::KEY, $page, self::INSTRUCTIONS, self::schema(), $imageBase64)
            : ReaderRequest::text(self::KEY, $page, self::INSTRUCTIONS, self::schema(), (string) $text);
    }

    public function accept(array $output, DocumentReadPage $page): ?array
    {
        // The shape was checked already. Only lengths are held here; what the values mean is the checks' work, on the saved answer.
        $cut = fn (array $cell) => ['raw' => mb_substr((string) ($cell['raw'] ?? ''), 0, 200), 'state' => (string) ($cell['state'] ?? '')];

        foreach (array_keys(self::schema()['properties']) as $field) {
            if (! in_array($field, ['doc_kind', 'lines'], true)) {
                $output[$field] = $cut((array) $output[$field]);
            }
        }

        $output['lines'] = array_map(
            fn (array $line) => array_combine(BillChecks::LINE_PARTS, array_map(fn (string $part) => $cut((array) $line[$part]), BillChecks::LINE_PARTS)),
            array_slice(array_values((array) $output['lines']), 0, 40),
        );

        return $output;
    }

    public function nextPasses(DocumentReadPage $page): array
    {
        return [];
    }

    public function onCutOff(DocumentReadPage $page): string
    {
        return 'fail';
    }

    /** Money: money.view, and the right to enter a challan's bill or a vendor bill. */
    public function abilities(DocumentRead $read): array
    {
        return ['all' => ['money.view'], 'any' => [['material_control.bills', 'finance.manage']]];
    }

    public function purge(DocumentRead $read): void
    {
        // Supplier names, GSTINs and figures do not outlive the photo. Which fields were confirmed stays.
        SlipRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('document_read_id', $read->id)
            ->update(['fields' => null, 'lines' => null, 'warnings' => null, 'suggestions' => null]);
    }
}
