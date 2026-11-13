<?php

namespace App\Services\SlipReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\SlipRead;
use App\Services\DocumentReading\Cell;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\DocumentReading\ReaderRequest;

/**
 * The reader for a photo of a delivery challan or a weighbridge slip, under the "slip" figures in
 * config/modules/document_reading.php. It fills the challan form before the form is saved; it
 * never saves a challan. Its form has no rate, amount, driver or phone field, so a challan reading
 * never carries a money figure.
 *
 * A second reading with the stronger model is the same page read again (ReadDesk::retry with the
 * stronger model), offered only after an arithmetic check failed. The first reading is kept on the
 * slip row, and a value the two readings disagree on is not offered.
 */
class SlipReadKind implements ReadKind
{
    use FillsSlip;

    public const KEY = 'slip';

    public const INSTRUCTIONS = <<<'TEXT'
    The picture is a paper that came with a delivery of building material to a site: a delivery challan, a weighbridge slip or a similar paper. Fill the form from it.
    - doc_kind: "delivery_challan", "weighbridge_slip", "tax_invoice" for a bill that shows tax, "other" for any other paper, "unreadable" when nothing on the picture can be read.
    - supplier_name and supplier_gstin: the firm that sent the goods, as printed on the paper. Not the buyer and not the transporter.
    - challan_no: the challan or slip number. challan_date: the date printed on the paper, as printed.
    - vehicle_no: the vehicle or truck number.
    - lines: one entry for each material line, from top to bottom, with its description, quantity and unit as printed. Leave the list empty when the paper lists no material.
    - gross_weight, tare_weight, net_weight and weight_unit: only where the paper prints weights; otherwise absent.
    - The form has no place for rates, amounts, totals, drivers or phone numbers. Leave them out.
    TEXT;

    public function __construct(private ChallanChecks $checks) {}

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $line = [
            'type' => 'object',
            'properties' => ['description' => Cell::SCHEMA, 'qty' => Cell::SCHEMA, 'unit' => Cell::SCHEMA],
            'required' => ['description', 'qty', 'unit'],
            'additionalProperties' => false,
        ];

        $properties = [
            'doc_kind' => ['type' => 'string', 'enum' => ChallanChecks::DOC_KINDS],
            'supplier_name' => Cell::SCHEMA,
            'supplier_gstin' => Cell::SCHEMA,
            'challan_no' => Cell::SCHEMA,
            'challan_date' => Cell::SCHEMA,
            'vehicle_no' => Cell::SCHEMA,
            'lines' => ['type' => 'array', 'items' => $line],
            'gross_weight' => Cell::SCHEMA,
            'tare_weight' => Cell::SCHEMA,
            'net_weight' => Cell::SCHEMA,
            'weight_unit' => Cell::SCHEMA,
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

        foreach (['supplier_name', 'supplier_gstin', 'challan_no', 'challan_date', 'vehicle_no', 'gross_weight', 'tare_weight', 'net_weight', 'weight_unit'] as $field) {
            $output[$field] = $cut((array) $output[$field]);
        }

        $output['lines'] = array_map(fn (array $line) => [
            'description' => $cut((array) $line['description']),
            'qty' => $cut((array) $line['qty']),
            'unit' => $cut((array) $line['unit']),
        ], array_slice(array_values((array) $output['lines']), 0, 20));

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

    public function abilities(DocumentRead $read): array
    {
        return ['all' => ['material_control.challan'], 'any' => []];
    }

    public function purge(DocumentRead $read): void
    {
        // Supplier names, GSTINs and vehicle numbers do not outlive the photo. Which fields were confirmed stays.
        SlipRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('document_read_id', $read->id)
            ->update(['fields' => null, 'lines' => null, 'warnings' => null, 'suggestions' => null]);
    }
}
