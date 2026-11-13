<?php

namespace App\Services\SlipReading;

use App\Models\MaterialUnit;
use App\Models\Party;
use App\Services\DocumentReading\Cell;
use App\Services\DocumentReading\PrintedDate;
use App\Services\DocumentReading\PrintedNumber;
use App\Services\DocumentReading\UnitText;
use App\Services\Finance\Gst;
use App\Support\Dec;
use Illuminate\Support\Carbon;

/**
 * How a value the reader copied from a photo becomes a cell the form is offered, for the challan
 * checks and the bill checks alike. Every value is parsed by code and nothing is guessed: a value
 * that is not one plain number, one date or a known unit is not offered, and the person types it.
 *
 * A cell is the text as printed ("raw"), what the code read it as ("parsed", which stays on the
 * server), what the form is offered ("value", null when nothing is), the reader's own remark
 * ("state") and warning codes.
 */
trait ReadsCells
{
    private const RAW_LENGTH = 200;

    /** @return array<string, mixed> */
    protected function cell(array $cell): array
    {
        $state = Cell::state($cell);
        $raw = mb_substr(Cell::raw($cell), 0, self::RAW_LENGTH);

        return ['raw' => $raw, 'parsed' => null, 'value' => null, 'state' => $state, 'warnings' => $state === Cell::UNCLEAR ? ['unclear'] : []];
    }

    /** @return array<string, mixed> */
    protected function text(array $cell, int $length): array
    {
        $out = $this->cell($cell);
        $value = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $out['raw']));

        if ($value !== '') {
            $out['parsed'] = $out['value'] = mb_substr($value, 0, $length);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    protected function gstin(array $cell): array
    {
        $out = $this->cell($cell);
        $value = Gstin::normalise($out['raw']);

        if ($value !== '') {
            $out['parsed'] = $out['value'] = mb_substr($value, 0, 15);

            if (Gstin::problems($value) !== []) {
                $out['warnings'][] = 'gstin_check_failed';
            }
        }

        return $out;
    }

    /**
     * A printed date. One after today is not offered, because the forms refuse it; an old one or
     * one of another financial year is marked.
     *
     * @return array<string, mixed>
     */
    protected function date(array $cell): array
    {
        $out = $this->cell($cell);

        if ($out['raw'] === '') {
            return $out;
        }

        $date = PrintedDate::parse($out['raw']);

        if ($date === null) {
            $out['warnings'][] = 'date_unreadable';

            return $out;
        }

        $out['parsed'] = $date;
        $day = Carbon::parse($date);
        $today = today();

        if ($day->greaterThan($today)) {
            $out['warnings'][] = 'date_in_future';

            return $out;
        }

        $out['value'] = $date;

        if ($day->lessThan($today->copy()->subDays((int) config('modules.slip_reading.old_after_days', 30)))) {
            $out['warnings'][] = 'date_old';
        }

        if (Gst::financialYear($day) !== Gst::financialYear($today)) {
            $out['warnings'][] = 'date_other_year';
        }

        return $out;
    }

    /**
     * One printed number (PrintedNumber's strict forms). A quantity is kept to three decimals and a
     * money figure to two. Zero is offered only where a printed zero means something.
     *
     * @param  string  $kind  qty, rate or amount
     * @return array<string, mixed>
     */
    protected function number(array $cell, string $kind = 'qty', bool $zero = false): array
    {
        $out = $this->cell($cell);
        $out['had_comma'] = false;
        $out['script'] = null;

        if ($out['raw'] === '') {
            return $out;
        }

        $result = PrintedNumber::parse($out['raw'], $kind);
        $out['had_comma'] = $result->hadComma;
        $out['script'] = $result->script;
        $usable = $result->ok && $result->value !== null && ($zero ? ! Dec::of($result->value)->isNegative() : Dec::of($result->value)->isPositive());

        if (! $usable) {
            $out['warnings'][] = 'not_a_number';
            $out['reason'] = $result->reason ?? 'not_positive';

            return $out;
        }

        $out['parsed'] = $out['value'] = $kind === 'qty' ? Dec::qty($result->value) : Dec::money($result->value);

        return $out;
    }

    /**
     * A unit as printed, as the app writes it, and the company's unit of that name.
     *
     * @param  array<string, int>  $units
     * @return array<string, mixed>
     */
    protected function unit(array $cell, array $units): array
    {
        $out = $this->cell($cell);
        $out['known'] = false;
        $out['unit_id'] = null;

        if ($out['raw'] === '') {
            return $out;
        }

        $unit = UnitText::normalise($out['raw']);
        $out['known'] = $unit['known'];
        $id = $units[UnitText::key($unit['unit'])] ?? null;

        if ($unit['unit'] !== '') {
            $out['parsed'] = $unit['unit'];
        }

        if ($id === null) {
            $out['warnings'][] = 'unit_unknown';

            return $out;
        }

        $out['value'] = $unit['unit'];
        $out['unit_id'] = $id;

        return $out;
    }

    /** @return array<string, int> The company's units, by the form UnitText::key gives their code. */
    protected function units(int $organizationId): array
    {
        return MaterialUnit::withoutGlobalScopes()->where('organization_id', $organizationId)->orderBy('id')->get(['id', 'code'])
            ->reduce(function (array $units, MaterialUnit $unit) {
                $units[UnitText::key((string) $unit->code)] ??= (int) $unit->id;

                return $units;
            }, []);
    }

    /**
     * The one party of the company, of these types, whose GSTIN is exactly the one read, when that
     * GSTIN passes the checks. False when it fails them against that party's PAN, so the person picks
     * or types the supplier. Names are never matched.
     *
     * @param  list<string>  $types
     * @return array{id:int, name:string, by:string}|false|null
     */
    protected function party(int $organizationId, array $gstin, array $types = ['vendor']): array|false|null
    {
        $value = $gstin['value'];

        // A GSTIN that fails its own checks carries the warning on its field already.
        if ($value === null || Gstin::problems($value) !== []) {
            return null;
        }

        $matches = Party::withoutGlobalScopes()->where('organization_id', $organizationId)->whereIn('type', $types)
            ->whereNull('deleted_at')->whereNotNull('gstin')->get(['id', 'name', 'gstin', 'pan'])
            ->filter(fn (Party $party) => Gstin::normalise((string) $party->gstin) === $value)->values();

        if ($matches->count() !== 1) {
            return null;
        }

        $party = $matches->first();

        if (Gstin::problems($value, $party->pan) !== []) {
            return false;
        }

        return ['id' => (int) $party->id, 'name' => (string) $party->name, 'by' => 'gstin'];
    }

    /**
     * A second reading by the stronger model: a value the two readings do not agree on is not offered at all.
     *
     * @param  list<string>  $parts  The cells of a line.
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>, 2: bool}
     */
    protected function compare(array $fields, array $lines, array $earlier, array $parts): array
    {
        $disagree = false;

        foreach ($fields as $name => $cell) {
            if (($earlier['fields'][$name]['parsed'] ?? null) !== $cell['parsed']) {
                $fields[$name] = $this->disagreed($cell);
                $disagree = true;
            }
        }

        $before = $earlier['lines'] ?? [];

        foreach ($lines as $index => $line) {
            foreach ($parts as $part) {
                if (count($before) !== count($lines) || ($before[$index][$part]['parsed'] ?? null) !== $line[$part]['parsed']) {
                    $lines[$index][$part] = $this->disagreed($line[$part]);
                    $disagree = true;
                }
            }
        }

        return [$fields, $lines, $disagree || count($before) !== count($lines)];
    }

    /** @return array<string, mixed> */
    private function disagreed(array $cell): array
    {
        $cell['value'] = null;
        $cell['warnings'][] = 'reads_disagree';

        if (array_key_exists('unit_id', $cell)) {
            $cell['unit_id'] = null;
        }

        return $cell;
    }
}
