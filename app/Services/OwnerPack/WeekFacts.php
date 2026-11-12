<?php

namespace App\Services\OwnerPack;

use App\Models\BoqItem;
use App\Models\ClientPaymentStage;
use App\Models\ClientQuotation;
use App\Models\Concerns\OrganizationScope;
use App\Models\Concerns\ProjectAccessScope;
use App\Models\Dpr;
use App\Models\DprManpower;
use App\Models\DprPhoto;
use App\Models\DprWorkItem;
use App\Models\OwnerUpdate;
use App\Models\Project;
use App\Services\SiteControl\WorkProgress;
use App\Services\Wording\Fact;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Reads everything a weekly update states from the records, as they stand now.
 *
 * Only approved daily reports of the week count: their number, the work rows added up per BOQ
 * line, and the daily worker counts added up. Photos are the ones a person picked, and only
 * while they belong to such a report. The share of work measured and approved comes from
 * WorkProgress and from nowhere else. The payment position is read from the quotation the update
 * names and the stages marked as reached on it; "received so far" is what a person typed on the
 * update, and nothing is assumed when it was left empty.
 *
 * Summaries, hindrance notes and anything else a report holds in free text are never read.
 *
 * Every query names the company and the project itself, so the sheet is the same whoever builds
 * it, and it is always built in the same order: its fingerprint is what approving and sharing compare.
 */
final class WeekFacts
{
    public function __construct(private WorkProgress $progress) {}

    public function sheet(OwnerUpdate $update): FactSheet
    {
        $own = fn (string $field) => FactSource::record('owner_update', $update->id, $field);
        $reports = $this->approvedReports($update->organization_id, $update->project_id, $update->week_start->toDateString(), $update->week_end->toDateString());

        $facts = [
            new Fact('week_start', Fact::DATE, $update->week_start, 'First day of the week', $own('week_start')),
            new Fact('week_end', Fact::DATE, $update->week_end, 'Last day of the week', $own('week_end')),
            new Fact('as_on', Fact::DATE, $update->prepared_on ?? today(), 'Date the figures were read', $own('prepared_on')),
            new Fact('report_count', Fact::COUNT, $reports->count(), 'Approved daily reports of the week', FactSource::computed('report_count')),
        ];

        array_push($facts, ...$this->work($update, $reports));

        $workers = (int) $this->open(DprManpower::class)
            ->where('organization_id', $update->organization_id)
            ->whereIn('dpr_id', $reports->keys())
            ->sum('count');

        if ($workers > 0) {
            $facts[] = new Fact('worker_days', Fact::COUNT, $workers, 'Daily worker counts added up', FactSource::computed('worker_days'));
        }

        $project = $this->open(Project::class)->where('organization_id', $update->organization_id)->whereKey($update->project_id)->first();
        $measured = $project === null ? null : $this->progress->measuredPercent($project);

        if ($measured !== null) {
            $facts[] = new Fact('measured_pct', Fact::PERCENT, $measured, 'Work measured and approved so far', FactSource::computed('measured_pct'));
        }

        array_push($facts, ...$this->photos($update, $reports));

        if ($update->show_money) {
            array_push($facts, ...$this->payments($update));
        }

        return new FactSheet($facts);
    }

    /**
     * The approved daily reports of a project that fall inside a week.
     *
     * @return Collection<int, Dpr> Keyed by id, oldest first.
     */
    public function approvedReports(int $organizationId, int $projectId, string $from, string $to): Collection
    {
        return $this->open(Dpr::class)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('status', 'APPROVED')
            ->whereDate('report_date', '>=', $from)
            ->whereDate('report_date', '<=', $to)
            ->orderBy('report_date')->orderBy('id')
            ->get(['id', 'report_date'])
            ->keyBy('id');
    }

    /**
     * The photos of those reports. An update may carry these and no others.
     *
     * @param  Collection<int, Dpr>  $reports
     * @return Collection<int, DprPhoto> Keyed by id, in the order of the reports.
     */
    public function photosOf(int $organizationId, Collection $reports): Collection
    {
        return $this->open(DprPhoto::class)
            ->where('organization_id', $organizationId)
            ->whereIn('dpr_id', $reports->keys())
            ->orderBy('id')
            ->get()
            ->sortBy(fn (DprPhoto $photo) => [$reports[$photo->dpr_id]->report_date->toDateString(), $photo->id])
            ->keyBy('id');
    }

    /**
     * The work rows of the week, one line per BOQ item with its quantities added up. A row that
     * names no BOQ item is listed under its own description.
     *
     * @param  Collection<int, Dpr>  $reports
     * @return list<Fact>
     */
    private function work(OwnerUpdate $update, Collection $reports): array
    {
        $rows = $this->open(DprWorkItem::class)
            ->where('organization_id', $update->organization_id)
            ->whereIn('dpr_id', $reports->keys())
            ->orderBy('id')
            ->get(['id', 'boq_item_id', 'description', 'qty']);

        if ($rows->isEmpty()) {
            return [];
        }

        // A line taken out of the BOQ since is still named: the work was reported against it.
        $items = $this->open(BoqItem::class)
            ->withTrashed()
            ->where('organization_id', $update->organization_id)
            ->where('project_id', $update->project_id)
            ->whereIn('id', $rows->pluck('boq_item_id')->filter())
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'code', 'description', 'unit']);

        $byItem = [];
        $loose = [];

        foreach ($rows as $row) {
            if ($row->boq_item_id !== null && $items->contains('id', $row->boq_item_id)) {
                $byItem[$row->boq_item_id] = ($byItem[$row->boq_item_id] ?? BigDecimal::zero())->plus(Dec::of($row->qty));
            } elseif (($name = trim((string) $row->description)) !== '') {
                $loose[$name] = ($loose[$name] ?? BigDecimal::zero())->plus(Dec::of($row->qty));
            }
        }

        $lines = [];

        foreach ($items as $item) {
            $lines[] = [
                'name' => trim($item->code.' '.$item->description),
                'qty' => $byItem[$item->id],
                'unit' => trim((string) $item->unit),
                'source' => fn (string $field) => FactSource::record('boq_item', $item->id, $field),
            ];
        }

        ksort($loose, SORT_STRING);

        foreach ($loose as $name => $qty) {
            $lines[] = ['name' => (string) $name, 'qty' => $qty, 'unit' => '', 'source' => fn (string $field) => FactSource::computed($field)];
        }

        $lines = array_values(array_filter($lines, fn (array $line) => $line['name'] !== ''));
        $listed = array_slice($lines, 0, max(1, (int) config('modules.owner_pack.max_work_lines')));

        if ($listed === []) {
            return [];
        }

        $facts = [new Fact('work_count', Fact::COUNT, count($listed), 'Lines of work listed', FactSource::computed('work_count'))];

        foreach ($listed as $index => $line) {
            $n = $index + 1;
            $facts[] = new Fact("work_{$n}_name", Fact::TEXT, $line['name'], "Work {$n}: what was done", $line['source']('description'));

            // A row with no BOQ item has no unit, so a quantity of nothing is not printed for it.
            if ($line['unit'] !== '' || $line['qty']->isPositive()) {
                $facts[] = new Fact("work_{$n}_qty", Fact::QTY, Dec::qty($line['qty']), "Work {$n}: quantity", FactSource::computed("work_{$n}_qty"));
            }

            if ($line['unit'] !== '') {
                $facts[] = new Fact("work_{$n}_unit", Fact::TEXT, $line['unit'], "Work {$n}: unit", $line['source']('unit'));
            }
        }

        if (count($lines) > count($listed)) {
            $facts[] = new Fact('work_more', Fact::COUNT, count($lines) - count($listed), 'Lines of work not listed', FactSource::computed('work_more'));
        }

        return $facts;
    }

    /**
     * The picked photos that still belong to an approved report of the week, each with the date of
     * its report and the caption staff typed.
     *
     * @param  Collection<int, Dpr>  $reports
     * @return list<Fact>
     */
    private function photos(OwnerUpdate $update, Collection $reports): array
    {
        $picked = $update->photoIds();

        if ($picked === [] || $reports->isEmpty()) {
            return [];
        }

        $rows = $this->open(DprPhoto::class)
            ->where('organization_id', $update->organization_id)
            ->whereIn('id', $picked)
            ->whereIn('dpr_id', $reports->keys())
            ->get(['id', 'dpr_id', 'caption'])
            ->keyBy('id');

        $facts = [];
        $n = 0;

        foreach ($picked as $id) {
            if (! isset($rows[$id])) {
                continue;
            }

            $n++;
            $photo = $rows[$id];
            $facts[] = new Fact("photo_{$n}_date", Fact::DATE, $reports[$photo->dpr_id]->report_date, "Photo {$n}: date of its report", FactSource::record('dpr_photo', $photo->id, 'report_date'));

            if (trim((string) $photo->caption) !== '') {
                $facts[] = new Fact("photo_{$n}_caption", Fact::TEXT, $photo->caption, "Photo {$n}: caption", FactSource::record('dpr_photo', $photo->id, 'caption'));
            }
        }

        return $n === 0 ? [] : [new Fact('photo_count', Fact::COUNT, $n, 'Photos with the update', FactSource::computed('photo_count')), ...$facts];
    }

    /**
     * The payment position on the quotation the update names: its total, each stage marked as
     * reached, what they add up to, and what is due once the typed "received so far" is taken off.
     *
     * @return list<Fact>
     */
    private function payments(OwnerUpdate $update): array
    {
        $quotation = $update->quotation_id === null ? null : $this->within(ClientQuotation::class, $update)
            ->where('status', ClientQuotation::ISSUED)
            ->whereKey($update->quotation_id)
            ->first();

        if ($quotation === null) {
            return [];
        }

        $header = fn (string $field) => FactSource::record('client_quotation', $quotation->id, $field);
        $facts = [
            new Fact('pay_number', Fact::REFERENCE, $quotation->number, 'Quotation number', $header('number')),
            new Fact('pay_revision', Fact::COUNT, (int) $quotation->revision, 'Revision', $header('revision')),
            new Fact('pay_total', Fact::MONEY, (string) $quotation->total, 'Quotation total', $header('total')),
        ];

        $stages = $this->within(ClientPaymentStage::class, $update)
            ->where('quotation_id', $quotation->id)
            ->whereNotNull('reached_on')
            ->orderBy('sort')->orderBy('id')
            ->get();

        $reached = BigDecimal::zero();
        $lines = [];

        foreach ($stages->values() as $index => $stage) {
            $n = $index + 1;
            $row = fn (string $field) => FactSource::record('client_payment_stage', $stage->id, $field);
            $lines[] = new Fact("pay_stage_{$n}_title", Fact::TEXT, $stage->title, "Stage reached {$n}: name", $row('title'));
            $lines[] = new Fact("pay_stage_{$n}_amount", Fact::MONEY, (string) $stage->amount, "Stage reached {$n}: amount", $row('amount'));
            $lines[] = new Fact("pay_stage_{$n}_date", Fact::DATE, $stage->reached_on, "Stage reached {$n}: date", $row('reached_on'));
            $reached = $reached->plus(Dec::of($stage->amount));
        }

        $facts[] = new Fact('pay_reached_count', Fact::COUNT, $stages->count(), 'Payment stages reached', FactSource::computed('pay_reached_count'));
        array_push($facts, ...$lines);

        if ($stages->isNotEmpty()) {
            $facts[] = new Fact('pay_reached', Fact::MONEY, Dec::money($reached), 'Total of the stages reached', FactSource::computed('pay_reached'));
        }

        if ($update->received_amount !== null) {
            $received = Dec::of($update->received_amount);
            $due = $reached->minus($received);

            // Typed on this update by the contractor. The pack keeps no receipts of its own.
            $facts[] = new Fact('pay_received', Fact::MONEY, Dec::money($received), 'Received so far, as entered by the contractor', FactSource::entered('received_amount'));

            if ($due->isPositive()) {
                $facts[] = new Fact('pay_due', Fact::MONEY, Dec::money($due), 'Due on the stages reached', FactSource::computed('pay_due'));
            } elseif ($due->isNegative()) {
                $facts[] = new Fact('pay_ahead', Fact::MONEY, Dec::money($due->negated()), 'Received beyond the stages reached', FactSource::computed('pay_ahead'));
            }
        }

        return $facts;
    }

    /**
     * A query on a tenant model without the two scopes that follow the signed-in person. Soft deletes still apply.
     *
     * @param  class-string<Model>  $model
     */
    private function open(string $model): Builder
    {
        return $model::withoutGlobalScopes([OrganizationScope::class, ProjectAccessScope::class]);
    }

    /** @param  class-string<Model>  $model */
    private function within(string $model, OwnerUpdate $update): Builder
    {
        return $this->open($model)->where('organization_id', $update->organization_id)->where('project_id', $update->project_id);
    }
}
