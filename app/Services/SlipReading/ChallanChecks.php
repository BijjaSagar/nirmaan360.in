<?php

namespace App\Services\SlipReading;

use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\SlipRead;
use App\Models\User;
use App\Services\Notify\Audience;
use App\Support\Dec;
use Normalizer;

/**
 * Turns the reader's answer for a challan or weighbridge slip into what the challan form is offered,
 * by code. Every value is parsed here and nothing is worked out from anything else: a quantity that
 * does not read as one number, a date that is not one date, or weights that do not add up are not
 * offered, and the person types them.
 *
 * Each value is a cell: the text as printed ("raw"), what the code read it as ("parsed"), what the
 * form is offered ("value", null when nothing is), the reader's own remark ("state") and warning
 * codes. The counted quantity is never part of this: it is what was counted on site.
 *
 * Runs inside the transaction that saves the page, with nobody signed in, so every query names the
 * company.
 */
class ChallanChecks
{
    use ReadsCells;

    public const DOC_KINDS = ['delivery_challan', 'weighbridge_slip', 'tax_invoice', 'other', 'unreadable'];

    /** Fields of the form, with the most characters each may have. */
    private const TEXT_FIELDS = ['challan_no' => 60, 'supplier_name' => 160];

    private const WEIGHTS = ['gross_weight', 'tare_weight', 'net_weight'];

    /** A failed check of this kind is arithmetic, so a person may ask the stronger model to read the photo again. */
    public const ARITHMETIC = ['weights_do_not_add_up'];

    /** Warnings a person ticks before the form can be saved with this reading. */
    public const TICKED = ['possible_duplicate', 'same_delivery', 'same_photo'];

    /** At most this many lines are kept from one photo. */
    private const MOST_LINES = 20;

    /**
     * @param  array<string, mixed>  $answer  The reader's accepted answer.
     * @param  ?array{fields: array<string, mixed>, lines: list<array<string, mixed>>}  $earlier  The first reading, when this is the stronger model's second one.
     * @return array{doc_kind: string, fields: array<string, array<string, mixed>>, lines: list<array<string, mixed>>, warnings: list<array<string, mixed>>, suggestions: array<string, mixed>}
     */
    public function build(array $answer, SlipRead $slip, ?array $earlier = null): array
    {
        $organizationId = (int) $slip->organization_id;
        $units = $this->units($organizationId);
        $warnings = [];

        $fields = [
            'challan_no' => $this->text($answer['challan_no'] ?? [], self::TEXT_FIELDS['challan_no']),
            'challan_date' => $this->date($answer['challan_date'] ?? []),
            'vehicle_no' => $this->vehicle($answer['vehicle_no'] ?? []),
            'supplier_name' => $this->text($answer['supplier_name'] ?? [], self::TEXT_FIELDS['supplier_name']),
            'supplier_gstin' => $this->gstin($answer['supplier_gstin'] ?? []),
            'gross_weight' => $this->number($answer['gross_weight'] ?? []),
            'tare_weight' => $this->number($answer['tare_weight'] ?? []),
            'net_weight' => $this->number($answer['net_weight'] ?? []),
            'weight_unit' => $this->unit($answer['weight_unit'] ?? [], $units),
        ];

        $lines = [];

        foreach (array_slice(is_array($answer['lines'] ?? null) ? $answer['lines'] : [], 0, self::MOST_LINES) as $line) {
            $lines[] = [
                'description' => $this->text($line['description'] ?? [], 200),
                'qty' => $this->number($line['qty'] ?? []),
                'unit' => $this->unit($line['unit'] ?? [], $units),
            ];
        }

        if ($earlier !== null) {
            [$fields, $lines, $disagree] = $this->compare($fields, $lines, $earlier, ['description', 'qty', 'unit']);

            if ($disagree) {
                $warnings[] = ['code' => 'reads_disagree', 'params' => [], 'tick' => false];
            }
        }

        // None of the three weights is offered, and so neither is their unit.
        if ($this->weightsDisagree($fields)) {
            foreach ([...self::WEIGHTS, 'weight_unit'] as $weight) {
                $fields[$weight]['value'] = null;
                $fields[$weight]['warnings'][] = 'weights_do_not_add_up';
            }

            $fields['weight_unit']['unit_id'] = null;

            $warnings[] = ['code' => 'weights_do_not_add_up', 'params' => [], 'tick' => false];
        }

        $docKind = in_array($answer['doc_kind'] ?? null, self::DOC_KINDS, true) ? $answer['doc_kind'] : 'other';

        if ($docKind === 'unreadable') {
            $warnings[] = ['code' => 'nothing_read', 'params' => [], 'tick' => false];
        } elseif (! in_array($docKind, ['delivery_challan', 'weighbridge_slip'], true)) {
            $warnings[] = ['code' => 'not_a_challan', 'params' => ['doc_kind' => $docKind], 'tick' => false];
        }

        $party = $this->party($organizationId, $fields['supplier_gstin']);

        if ($party === false) {
            $fields['supplier_gstin']['warnings'][] = 'gstin_check_failed';
            $party = null;
        }

        $warnings = [...$warnings, ...$this->duplicates($slip, $fields, $lines, $party)];

        return [
            'doc_kind' => $docKind,
            'fields' => $fields,
            'lines' => $lines,
            'warnings' => $warnings,
            'suggestions' => ['party' => $party, 'materials' => $this->materials($organizationId, $lines)],
        ];
    }

    /** The vehicle number as it is compared and saved: upper case, without spaces or hyphens. */
    public static function vehicleNumber(?string $raw): string
    {
        return mb_substr((string) preg_replace('/[\s\p{Z}\-\x{2010}-\x{2015}]+/u', '', mb_strtoupper(trim((string) $raw))), 0, 30);
    }

    /** Text as it is compared: trimmed, single spaces, upper case. */
    public static function plainText(?string $raw): string
    {
        return mb_strtoupper(trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', (string) $raw)));
    }

    /** @return array<string, mixed> */
    private function vehicle(array $cell): array
    {
        $out = $this->cell($cell);
        $value = self::vehicleNumber($out['raw']);

        if ($value !== '') {
            $out['parsed'] = $out['value'] = $value;
        }

        return $out;
    }

    /** Gross less tare is not exactly net, where all three were read. Nothing is worked out when one is missing. */
    private function weightsDisagree(array $fields): bool
    {
        [$gross, $tare, $net] = array_map(fn (string $weight) => $fields[$weight]['parsed'], self::WEIGHTS);

        if ($gross === null || $tare === null || $net === null) {
            return false;
        }

        return ! Dec::of($gross)->minus(Dec::of($tare))->isEqualTo(Dec::of($net));
    }

    /**
     * Saved records this photo may duplicate. Each is a warning the person ticks; none stops anything.
     *
     * The same supplier and number is looked for in the whole company, but the other challan's
     * number is named only when the person reading sees its project.
     *
     * @param  ?array{id:int}  $party
     * @return list<array<string, mixed>>
     */
    private function duplicates(SlipRead $slip, array $fields, array $lines, ?array $party): array
    {
        $warnings = [];
        $posted = fn () => DeliveryChallan::withoutGlobalScopes()->where('organization_id', $slip->organization_id)->where('status', 'POSTED');
        $number = $fields['challan_no']['value'];

        if ($party !== null && $number !== null) {
            $same = $posted()->where('party_id', $party['id'])->whereNotNull('challan_no')->get(['id', 'project_id', 'number', 'challan_no'])
                ->first(fn (DeliveryChallan $challan) => self::plainText($challan->challan_no) === self::plainText($number));

            if ($same !== null) {
                $reader = User::query()->where('organization_id', $slip->organization_id)->find($slip->user_id);
                $seen = $reader !== null && Audience::seesProject($reader, (int) $same->project_id);
                $warnings[] = ['code' => 'possible_duplicate', 'params' => $seen ? ['number' => (string) $same->number] : [], 'tick' => true];
            }
        }

        $date = $fields['challan_date']['value'];
        $vehicle = $fields['vehicle_no']['value'];
        $quantities = collect($lines)->pluck('qty.value')->push($fields['net_weight']['value'])->filter()->values();

        if ($date !== null && $vehicle !== null && $quantities->isNotEmpty()) {
            $same = $posted()->where('project_id', $slip->project_id)->whereDate('challan_date', $date)->whereNotNull('challan_qty')
                ->get(['id', 'number', 'vehicle_no', 'challan_qty'])
                ->first(fn (DeliveryChallan $challan) => self::vehicleNumber($challan->vehicle_no) === $vehicle
                    && $quantities->contains(fn (string $qty) => Dec::of($qty)->isEqualTo(Dec::of($challan->challan_qty))));

            if ($same !== null) {
                $warnings[] = ['code' => 'same_delivery', 'params' => ['number' => (string) $same->number], 'tick' => true];
            }
        }

        if (filled($slip->image_sha256) && SlipRead::withoutGlobalScopes()->where('organization_id', $slip->organization_id)
            ->where('image_sha256', $slip->image_sha256)->where('status', SlipRead::USED)->whereKeyNot($slip->id)->exists()) {
            $warnings[] = ['code' => 'same_photo', 'params' => [], 'tick' => true];
        }

        return $warnings;
    }

    /**
     * Up to five materials of the catalogue whose name or code shares words with a line. Code only:
     * the person picks one, or none.
     *
     * @return list<array{id:int, name:string, line:int}>
     */
    private function materials(int $organizationId, array $lines): array
    {
        $wanted = [];

        foreach ($lines as $index => $line) {
            $wanted[$index] = $this->tokens((string) ($line['description']['value'] ?? ''));
        }

        if (array_filter($wanted) === []) {
            return [];
        }

        $scored = [];

        foreach (Material::withoutGlobalScopes()->where('organization_id', $organizationId)->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']) as $material) {
            $tokens = $this->tokens($material->name.' '.$material->code);
            $best = 0;
            $line = 0;

            foreach ($wanted as $index => $words) {
                $score = count(array_intersect($words, $tokens));

                if ($score > $best) {
                    [$best, $line] = [$score, $index];
                }
            }

            if ($best > 0) {
                $scored[] = ['id' => (int) $material->id, 'name' => (string) $material->name, 'line' => $line, 'score' => $best];
            }
        }

        usort($scored, fn (array $a, array $b) => [$b['score'], $a['name'], $a['id']] <=> [$a['score'], $b['name'], $b['id']]);

        return array_map(fn (array $row) => ['id' => $row['id'], 'name' => $row['name'], 'line' => $row['line']], array_slice($scored, 0, (int) config('modules.slip_reading.material_suggestions', 5)));
    }

    /** @return list<string> Words of two characters or more, in lower case. */
    private function tokens(string $text): array
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC);
        $words = preg_split('/[^\p{L}\p{M}\p{N}]+/u', mb_strtolower(is_string($text) ? $text : ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $word) => mb_strlen($word) >= 2)));
    }
}
