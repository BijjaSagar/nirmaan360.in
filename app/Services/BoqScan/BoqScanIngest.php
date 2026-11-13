<?php

namespace App\Services\BoqScan;

use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\Cell;
use App\Services\DocumentReading\PageStrip;
use App\Services\DocumentReading\PrintedDigits;
use App\Services\DocumentReading\PrintedNumber;
use App\Services\DocumentReading\ReaderRequest;
use App\Services\DocumentReading\ReadIntake;
use App\Services\DocumentReading\UnitText;
use App\Support\Dec;
use Illuminate\Support\Collection;

/**
 * Turns what the reader returned for a page into staging rows, by code:
 *
 * - every value as printed is kept beside the value code made of it;
 * - quantity, rate and amount are parsed by PrintedNumber, each on its own, and nothing is worked
 *   out from anything else (no quantity from an amount, no rate from a total);
 * - a blank unit stays blank, and an unknown one is kept as printed;
 * - the item code, the section (the nearest heading above with no item number) and an add-on's
 *   percentage are worked out again whenever rows change, except where a person typed them.
 *
 * It runs inside the transaction that marks a page as read and charges it, so it does database
 * work only. Nothing here runs with a signed-in person: every query names the company.
 */
class BoqScanIngest
{
    /** Rows of a half are sorted after the other half of their page. */
    private const BOTTOM_OFFSET = 50000;

    private const PAGE_STEP = 100000;

    private const ROW_STEP = 100;

    /** Stages one read page: its rows from the first reading, or its amounts from the second. */
    public function pageRead(DocumentReadPage $page): void
    {
        $read = $page->documentRead ?? DocumentRead::withoutGlobalScopes()->findOrFail($page->document_read_id);

        if ($page->pass === BoqReadKind::AMOUNTS) {
            $this->pairPage($read, (int) $page->page_no, (string) $page->part, $page);
            $this->derive($read);

            return;
        }

        $this->stageRows($read, $page);
    }

    /**
     * Works out again, for every row a person did not type over, its item code, its section and an
     * add-on's percentage, in the order the rows stand. A single letter under a numbered line takes
     * that line's number, also when that line is a heading with no figures of its own ("3 Earthwork
     * in excavation" over "(a)" and "(b)"); a heading with no item number names the section of the
     * rows below it and ends the lettered run.
     *
     * The code says which BOQ line a row becomes and the section is written with it, so a confirmed
     * row whose code or section changes here is no longer confirmed: a person confirms it again as
     * it now stands.
     */
    public function derive(DocumentRead $read): void
    {
        $rows = $this->rows($read)->orderBy('sort')->orderBy('id')->get();
        $section = null;
        $parent = null;

        foreach ($rows as $row) {
            if ($row->kind === BoqScanRow::EXCLUDED) {
                continue;
            }

            $changes = [];
            $code = $row->code;

            if (! $row->typedField('code')) {
                $derived = ItemNumber::code((string) $row->item_no_raw, $parent);
                $code = $derived === '' || mb_strlen($derived) > 32 ? null : $derived;
                $changes['code'] = $code;
            }

            if (! $row->typedField('section')) {
                $changes['section'] = $section;
            }

            if (! $row->typedField('percent')) {
                $changes['percent'] = $row->kind === BoqScanRow::ADDON ? self::percentIn((string) $row->description) : null;
            }

            if ($row->kind === BoqScanRow::HEADING) {
                if (trim((string) $row->item_no_raw) === '' && blank($code) && filled($row->description)) {
                    $section = mb_substr(trim((string) $row->description), 0, 120);
                }

                // A numbered head the lettered rows below belong to; any other heading ends the run.
                $parent = $code !== null && self::numbered($code) ? $code : null;
            } elseif ($code !== null && self::numbered($code) && ItemNumber::letters((string) $row->item_no_raw) === null) {
                $parent = $code;
            }

            $moved = (array_key_exists('code', $changes) && $changes['code'] !== $row->code)
                || (array_key_exists('section', $changes) && $changes['section'] !== $row->section);

            if ($moved && $row->confirmed_at !== null) {
                $changes += ['confirmed_by' => null, 'confirmed_at' => null, 'confirmed_mode' => null];
            }

            $this->saveChanged($row, $changes);
        }
    }

    /** The first percentage printed in a description, such as "Add 6 % for ...", as a plain decimal. */
    public static function percentIn(string $description): ?string
    {
        $text = PrintedDigits::plain($description)['text'] ?? '';

        if (preg_match('/(\d+(?:\.\d+)?)\s*%/u', $text, $match) !== 1) {
            return null;
        }

        $parsed = PrintedNumber::parse($match[1], 'percent');

        return $parsed->ok ? $parsed->value : null;
    }

    /**
     * Where a row read on a picture lies on the whole page, in parts per thousand. A half page is put
     * back in its place on the page.
     *
     * @return array{0: int, 1: int}
     */
    public static function onPage(DocumentReadPage $page, int $yTop, int $yBottom): array
    {
        $height = (int) $page->image_height;

        if ($height < 1) {
            return [0, 0];
        }

        $strip = PageStrip::perThousand($yTop, $yBottom, $height);

        if ($page->part === ReadIntake::FULL || $page->part_height === null) {
            return [$strip['top'], $strip['bottom']];
        }

        $top = (int) $page->part_top;
        $share = (int) $page->part_height;

        return [
            min(1000, $top + intdiv($strip['top'] * $share, 1000)),
            min(1000, $top + intdiv($strip['bottom'] * $share + 999, 1000)),
        ];
    }

    /** The first reading of a page: its rows replace the rows read from that picture before. Rows added by hand stay. */
    private function stageRows(DocumentRead $read, DocumentReadPage $page): void
    {
        $result = is_array($page->result) ? $page->result : [];
        $part = (string) $page->part;
        $number = (int) $page->page_no;

        $this->rows($read)->where('page_no', $number)->where('part', $part)->whereNotNull('kind_read')->delete();

        $offset = $number * self::PAGE_STEP + ($part === ReadIntake::BOTTOM ? self::BOTTOM_OFFSET : 0);

        foreach (array_values((array) ($result['rows'] ?? [])) as $index => $row) {
            [$top, $bottom] = self::onPage($page, (int) ($row['y_top'] ?? 0), (int) ($row['y_bottom'] ?? 0));

            $raw = fn (string $cell) => Cell::raw((array) ($row[$cell] ?? []));
            $state = fn (string $cell) => Cell::state((array) ($row[$cell] ?? []));
            $qty = PrintedNumber::parse($raw('qty'), 'qty');
            $rate = PrintedNumber::parse($raw('rate'), 'rate');
            $description = $raw('description');
            $unit = UnitText::normalise($raw('unit'))['unit'];
            $kind = in_array($row['kind'] ?? null, BoqScanRow::KINDS, true) ? $row['kind'] : BoqScanRow::NOTE;

            BoqScanRow::withoutGlobalScopes()->create([
                'organization_id' => $read->organization_id,
                'project_id' => $read->project_id,
                'document_read_id' => $read->id,
                'page_no' => $number,
                'part' => $part,
                'row_no' => min(65535, max(0, (int) ($row['row_no'] ?? $index + 1))),
                'sort' => $offset + ($index + 1) * self::ROW_STEP,
                'kind' => $kind,
                'kind_read' => $kind,
                'item_no_raw' => mb_substr($raw('item_no'), 0, 64),
                'description_raw' => $description,
                'unit_raw' => mb_substr($raw('unit'), 0, 32),
                'qty_raw' => mb_substr($raw('qty'), 0, 64),
                'rate_raw' => mb_substr($raw('rate'), 0, 64),
                'amount_raw' => null,
                'states' => [
                    'item_no' => $state('item_no'),
                    'description' => $state('description'),
                    'unit' => $state('unit'),
                    'qty' => $state('qty'),
                    'rate' => $state('rate'),
                    'amount' => null,
                ],
                'scripts' => ['qty' => $qty->script, 'rate' => $rate->script, 'amount' => null],
                'y_top' => $top,
                'y_bottom' => $bottom,
                'description' => $description === '' ? null : $description,
                'unit' => $unit === '' ? null : $unit,
                'qty' => $qty->value,
                'rate' => $rate->value,
                'initial' => [
                    'qty' => $qty->value,
                    'rate' => $rate->value,
                    'amount' => null,
                    // Text that reads like an instruction to the reader. It was not followed; the screen points at it.
                    'instruction_like' => ReaderRequest::flags([$raw('item_no'), $description, $raw('unit')]),
                ],
                'typed' => [],
            ]);
        }

        $this->review($read, $number, function (array $info) use ($part, $result, $page) {
            $info['parts'][$part] = [
                'page_kind' => (string) ($result['page_kind'] ?? BoqReadKind::PAGE_OTHER),
                'column_order' => array_values((array) ($result['column_order'] ?? [])),
                'instruction_like' => array_values((array) ($page->injection_flags ?? [])),
            ];

            return $info;
        });

        $amounts = DocumentReadPage::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)->where('document_read_id', $read->id)
            ->where('page_no', $number)->where('pass', BoqReadKind::AMOUNTS)->where('part', $part)
            ->where('status', DocumentReadPage::READ)->first();

        // The rows were read again after their amounts: the amounts are put back on the new rows.
        $this->pairPage($read, $number, $part, $amounts);
        $this->derive($read);
    }

    /**
     * Puts the second reading's amounts on the rows of the first, for one picture. An amount a person
     * typed stays. Amounts with no row are kept on the read for a person to place.
     */
    private function pairPage(DocumentRead $read, int $number, string $part, ?DocumentReadPage $amountsPage): void
    {
        /** @var Collection<int, BoqScanRow> $rows */
        $rows = $this->rows($read)->where('page_no', $number)->where('part', $part)->whereNotNull('kind_read')->orderBy('sort')->orderBy('id')->get()->values();

        $read2 = $amountsPage !== null && is_array($amountsPage->result) ? (array) ($amountsPage->result['rows'] ?? []) : [];
        $amounts = [];

        foreach ($read2 as $row) {
            $raw = Cell::raw((array) ($row['amount'] ?? []));

            // Only rows that print something in the amount column count.
            if ($raw === '') {
                continue;
            }

            [$top, $bottom] = self::onPage($amountsPage, (int) ($row['y_top'] ?? 0), (int) ($row['y_bottom'] ?? 0));
            $amounts[] = [
                'item_no' => Cell::raw((array) ($row['item_no'] ?? [])),
                'raw' => mb_substr($raw, 0, 64),
                'state' => Cell::state((array) ($row['amount'] ?? [])),
                'y_top' => $top,
                'y_bottom' => $bottom,
            ];
        }

        $pairing = AmountPairing::pair(
            $rows->map(fn (BoqScanRow $row) => ['key' => ItemNumber::key((string) $row->item_no_raw), 'y' => intdiv((int) $row->y_top + (int) $row->y_bottom, 2)])->all(),
            array_map(fn (array $amount) => ['key' => ItemNumber::key($amount['item_no']), 'y' => intdiv($amount['y_top'] + $amount['y_bottom'], 2)], $amounts),
            (int) config('modules.boq_scan.pair_within', 15),
        );

        foreach ($rows as $index => $row) {
            $amount = isset($pairing['pairs'][$index]) ? $amounts[$pairing['pairs'][$index]] : null;
            $parsed = $amount === null ? null : PrintedNumber::parse($amount['raw'], 'amount');

            $states = $row->states ?? [];
            $states['amount'] = $amount['state'] ?? null;
            $scripts = $row->scripts ?? [];
            $scripts['amount'] = $parsed?->script;
            $initial = $row->initial ?? [];
            $initial['amount'] = $parsed?->value;

            $changes = ['amount_raw' => $amount['raw'] ?? null, 'states' => $states, 'scripts' => $scripts, 'initial' => $initial];

            if (! $row->typedField('amount')) {
                $changes['amount'] = $parsed?->value;
            }

            $this->saveChanged($row, $changes);
        }

        $this->review($read, $number, function (array $info) use ($part, $pairing, $amounts) {
            $kept = array_values(array_filter((array) ($info['extra_amount_rows'] ?? []), fn ($extra) => ($extra['part'] ?? null) !== $part));

            foreach ($pairing['extra'] as $position => $index) {
                $kept[] = ['id' => $part.'-'.($position + 1), 'part' => $part, ...$amounts[$index]];
            }

            $info['extra_amount_rows'] = $kept;

            return $info;
        });
    }

    /**
     * Changes the read's review state for one page, under the read's lock. The page reader already
     * holds that lock while a page is staged.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     */
    private function review(DocumentRead $read, int $number, callable $change): void
    {
        $locked = DocumentRead::withoutGlobalScopes()->where('organization_id', $read->organization_id)->lockForUpdate()->findOrFail($read->id);
        $review = $locked->review ?? [];
        $review['pages'][(string) $number] = $change((array) ($review['pages'][(string) $number] ?? []));

        $locked->forceFill(['review' => $review])->save();
        $read->setAttribute('review', $review);
        $read->syncOriginalAttribute('review');
    }

    /** @param  array<string, mixed>  $changes */
    private function saveChanged(BoqScanRow $row, array $changes): void
    {
        foreach ($changes as $column => $value) {
            $current = $row->getAttribute($column);

            if (in_array($column, ['qty', 'rate', 'amount', 'percent'], true)) {
                $same = ($current === null && $value === null)
                    || ($current !== null && $value !== null && Dec::of($current)->isEqualTo(Dec::of($value)));
            } else {
                $same = $current === $value;
            }

            if (! $same) {
                $row->setAttribute($column, $value);
            }
        }

        if ($row->isDirty()) {
            $row->save();
        }
    }

    /** A line number a lettered row may hang from: digits, with dots or dashes between them. */
    private static function numbered(string $code): bool
    {
        return preg_match('/^\d+(?:[.\-\/]\d+)*$/', $code) === 1;
    }

    private function rows(DocumentRead $read)
    {
        return BoqScanRow::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id);
    }
}
