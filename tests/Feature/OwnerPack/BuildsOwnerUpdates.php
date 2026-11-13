<?php

namespace Tests\Feature\OwnerPack;

use App\Models\BoqItem;
use App\Models\ClientQuotation;
use App\Models\Dpr;
use App\Models\DprManpower;
use App\Models\DprPhoto;
use App\Models\DprWorkItem;
use App\Models\OwnerUpdate;
use App\Models\Project;
use App\Models\User;
use App\Services\OwnerPack\OwnerUpdateDocument;
use App\Services\OwnerPack\OwnerUpdateService;
use App\Services\OwnerPack\OwnerUpdateShareService;
use App\Services\OwnerPack\ShownText;
use App\Services\Wording\Fact;
use App\Services\Wording\FactSheet;
use App\Services\Wording\FactSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * The invented house of BuildsOwnerPack with a site engineer, a week of daily reports and the short
 * steps the weekly-update tests repeat: prepare a draft, tick everything, approve, share.
 *
 * The clock stands on Saturday 14 November 2026, so "this week" is Monday the 9th to Sunday the
 * 15th whatever day the tests run on. Every name and figure is made up.
 */
trait BuildsOwnerUpdates
{
    use BuildsOwnerPack;

    protected User $engineer;

    /** The first day of the week the tests prepare updates for, and the last. */
    protected const WEEK = '2026-11-09';

    protected const WEEK_END = '2026-11-15';

    /** The house, a site engineer, a fake disk for photos, and the clock on the Saturday of the week. */
    protected function site(string $plan = 'contractor'): void
    {
        $this->travelTo(Carbon::parse('2026-11-14 11:00:00', config('app.timezone')));
        Storage::fake();
        $this->house($plan);
        $this->engineer = $this->member($this->organization, 'engineer');
    }

    /**
     * A daily report as it stands after approval, written straight to the tables.
     *
     * @param  list<array{0: ?BoqItem, 1: string, 2?: ?string}>  $work  BOQ line (or null), quantity, own description.
     * @param  array<string, int>  $manpower  Trade => count.
     */
    protected function report(string $date, string $status = 'APPROVED', array $work = [], array $manpower = [], ?Project $project = null): Dpr
    {
        $project ??= $this->project;
        $dpr = Dpr::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'report_date' => $date,
            'weather' => 'Clear',
            'summary' => 'Internal remark: the mixer broke down twice',
            'hindrances' => 'Internal remark: steel came late',
            'status' => $status,
            'created_by' => $this->engineer->id,
        ]);

        foreach ($work as $row) {
            DprWorkItem::create(['organization_id' => $project->organization_id, 'dpr_id' => $dpr->id, 'boq_item_id' => $row[0]?->id, 'description' => $row[2] ?? null, 'qty' => $row[1]]);
        }

        foreach ($manpower as $trade => $count) {
            DprManpower::create(['organization_id' => $project->organization_id, 'dpr_id' => $dpr->id, 'trade' => $trade, 'count' => $count]);
        }

        return $dpr;
    }

    /** A photo of a report, stored as a real small picture. */
    protected function photo(Dpr $dpr, ?string $caption = null, ?string $bytes = null): DprPhoto
    {
        $path = 'org-'.$dpr->organization_id.'/dprs/'.$dpr->id.'/'.bin2hex(random_bytes(6)).'.jpg';
        Storage::put($path, $bytes ?? $this->picture());

        return DprPhoto::create(['organization_id' => $dpr->organization_id, 'dpr_id' => $dpr->id, 'path' => $path, 'caption' => $caption]);
    }

    /** A plain JPEG made in code: wider than tall, light grey. */
    protected function picture(int $width = 120, int $height = 80): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, 0xDDDDDD);
        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * Three approved reports in the week with work on two BOQ lines, one still in review, one draft, and
     * one approved report of the week before. Two photos on the first report.
     *
     * @return array{reports: list<Dpr>, photos: list<DprPhoto>}
     */
    protected function week(): array
    {
        [$excavation, $masonry] = $this->lines;

        $monday = $this->report('2026-11-09', 'APPROVED', [[$excavation, '4.250'], [$masonry, '12.000']], ['Mason' => 4, 'Helper' => 6]);
        $tuesday = $this->report('2026-11-10', 'APPROVED', [[$excavation, '3.125'], [null, '0.000', 'Curing of plinth beam']], ['Mason' => 5, 'Helper' => 5]);
        $thursday = $this->report('2026-11-12', 'APPROVED', [[$masonry, '10.500']], ['Mason' => 3]);

        // Not approved, and outside the week: neither may reach an update for this week.
        $this->report('2026-11-13', 'IN_REVIEW', [[$masonry, '55.555']], ['Mason' => 40]);
        $this->report('2026-11-11', 'DRAFT', [[$excavation, '44.444']], ['Helper' => 30]);
        $this->report('2026-11-06', 'APPROVED', [[$masonry, '77.777']], ['Mason' => 20]);

        return [
            'reports' => [$monday, $tuesday, $thursday],
            'photos' => [$this->photo($monday, 'Footing F2 before concreting'), $this->photo($monday), $this->photo($thursday, 'Brickwork on the east wall')],
        ];
    }

    protected function updates(): OwnerUpdateService
    {
        return app(OwnerUpdateService::class);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function draftUpdate(array $overrides = [], ?User $by = null): OwnerUpdate
    {
        return $this->updates()->create($this->project, [
            'week_start' => self::WEEK,
            'language' => 'en',
            ...$overrides,
        ], $by ?? $this->manager);
    }

    /** @return array{fact_fingerprint: string, text_fingerprint: string, number_ticks: list<string>} What a person sends after ticking every figure, row and photo on the page. */
    protected function updateTicks(OwnerUpdate $update): array
    {
        [$sheet, $note] = $this->updates()->review($update->fresh());

        return ['fact_fingerprint' => $note->fingerprint, 'text_fingerprint' => ShownText::fingerprint($note), 'number_ticks' => app(OwnerUpdateDocument::class)->ticksNeeded($note, $sheet)];
    }

    protected function approvedUpdate(?OwnerUpdate $update = null): OwnerUpdate
    {
        $update ??= $this->draftUpdate();

        return $this->updates()->approve($update, $this->updateTicks($update), $this->manager)->fresh();
    }

    /** @return array{0: OwnerUpdate, 1: string} An approved update and the address of its open link. */
    protected function sharedUpdate(?OwnerUpdate $update = null): array
    {
        $shares = app(OwnerUpdateShareService::class);
        $update = $shares->share($update ?? $this->approvedUpdate(), $this->manager)->fresh();

        return [$update, $shares->url($update)];
    }

    /** An issued quotation with its first stage marked as reached on the Tuesday of the week. */
    protected function quotationWithAStageReached(): ClientQuotation
    {
        $quotation = $this->issued();
        $this->quotations()->markReached($quotation->stages()->first(), ['reached' => true, 'reached_on' => '2026-11-10'], $this->manager);

        return $quotation->fresh();
    }

    /**
     * A fact sheet written by hand, for the sentences of the update that depend on what the week held.
     *
     * @param  array<string, mixed>  $with  reports, work, more, workers, percent, photos, stages, received, due, ahead; money (bool).
     */
    protected function handSheet(array $with = []): FactSheet
    {
        $computed = fn (string $key) => FactSource::computed($key);
        $facts = [
            new Fact('week_start', Fact::DATE, self::WEEK, 'First day of the week', FactSource::record('owner_update', 1, 'week_start')),
            new Fact('week_end', Fact::DATE, self::WEEK_END, 'Last day of the week', FactSource::record('owner_update', 1, 'week_end')),
            new Fact('as_on', Fact::DATE, '2026-11-14', 'Date the figures were read', FactSource::record('owner_update', 1, 'prepared_on')),
            new Fact('report_count', Fact::COUNT, $with['reports'] ?? 0, 'Approved daily reports of the week', $computed('report_count')),
        ];

        for ($n = 1; $n <= ($with['work'] ?? 0); $n++) {
            if ($n === 1) {
                $facts[] = new Fact('work_count', Fact::COUNT, $with['work'], 'Lines of work listed', $computed('work_count'));
            }

            $facts[] = new Fact("work_{$n}_name", Fact::TEXT, "Invented work {$n}", "Work {$n}: what was done", $computed("work_{$n}_name"));
            $facts[] = new Fact("work_{$n}_qty", Fact::QTY, '2.500', "Work {$n}: quantity", $computed("work_{$n}_qty"));
            $facts[] = new Fact("work_{$n}_unit", Fact::TEXT, 'cum', "Work {$n}: unit", $computed("work_{$n}_unit"));
        }

        foreach (['more' => ['work_more', Fact::COUNT, 'Lines of work not listed'], 'workers' => ['worker_days', Fact::COUNT, 'Daily worker counts added up'], 'percent' => ['measured_pct', Fact::PERCENT, 'Work measured and approved so far']] as $given => [$key, $kind, $label]) {
            if (isset($with[$given])) {
                $facts[] = new Fact($key, $kind, $with[$given], $label, $computed($key));
            }
        }

        for ($n = 1; $n <= ($with['photos'] ?? 0); $n++) {
            if ($n === 1) {
                $facts[] = new Fact('photo_count', Fact::COUNT, $with['photos'], 'Photos with the update', $computed('photo_count'));
            }

            $facts[] = new Fact("photo_{$n}_date", Fact::DATE, self::WEEK, "Photo {$n}: date of its report", FactSource::record('dpr_photo', $n, 'report_date'));
            $facts[] = new Fact("photo_{$n}_caption", Fact::TEXT, "Invented caption {$n}", "Photo {$n}: caption", FactSource::record('dpr_photo', $n, 'caption'));
        }

        if ($with['money'] ?? false) {
            $facts[] = new Fact('pay_number', Fact::REFERENCE, 'CQ-2026-27-0001', 'Quotation number', $computed('pay_number'));
            $facts[] = new Fact('pay_revision', Fact::COUNT, 1, 'Revision', $computed('pay_revision'));
            $facts[] = new Fact('pay_total', Fact::MONEY, '500000.00', 'Quotation total', $computed('pay_total'));
            $facts[] = new Fact('pay_reached_count', Fact::COUNT, $with['stages'] ?? 0, 'Payment stages reached', $computed('pay_reached_count'));

            for ($n = 1; $n <= ($with['stages'] ?? 0); $n++) {
                $facts[] = new Fact("pay_stage_{$n}_title", Fact::TEXT, "Invented stage {$n}", "Stage reached {$n}: name", $computed("pay_stage_{$n}_title"));
                $facts[] = new Fact("pay_stage_{$n}_amount", Fact::MONEY, '100000.00', "Stage reached {$n}: amount", $computed("pay_stage_{$n}_amount"));
                $facts[] = new Fact("pay_stage_{$n}_date", Fact::DATE, '2026-11-10', "Stage reached {$n}: date", $computed("pay_stage_{$n}_date"));
            }

            foreach (['reached' => 'pay_reached', 'received' => 'pay_received', 'due' => 'pay_due', 'ahead' => 'pay_ahead'] as $given => $key) {
                if (isset($with[$given])) {
                    $facts[] = new Fact($key, Fact::MONEY, $with[$given], 'Invented label', $computed($key));
                }
            }
        }

        return new FactSheet($facts);
    }

    /**
     * Sheets that between them call up every sentence an update can have.
     *
     * @return array<string, FactSheet>
     */
    protected function everySentenceSheets(): array
    {
        return [
            'full' => $this->handSheet(['reports' => 3, 'work' => 2, 'more' => 4, 'workers' => 23, 'percent' => '25.88', 'photos' => 2, 'money' => true, 'stages' => 2, 'reached' => '200000.00', 'received' => '150000.00', 'due' => '50000.00']),
            'bare' => $this->handSheet(),
            'none reached' => $this->handSheet(['money' => true, 'received' => '20000.00', 'ahead' => '20000.00']),
            'settled' => $this->handSheet(['reports' => 1, 'money' => true, 'stages' => 1, 'reached' => '100000.00', 'received' => '100000.00']),
            'nothing typed' => $this->handSheet(['reports' => 1, 'money' => true, 'stages' => 1, 'reached' => '100000.00']),
        ];
    }
}
