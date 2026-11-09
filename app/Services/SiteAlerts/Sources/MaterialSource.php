<?php

namespace App\Services\SiteAlerts\Sources;

use App\Models\Alert;
use App\Models\Project;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use App\Support\Dec;
use App\Support\Figure;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

/**
 * Material used beyond the BOQ for the work done, and material received but neither used nor in
 * stock: the 105 bags against 100, and the 5 bags that went missing. The figures are
 * material_control's three-way check, and a gap stops alerting once a reason is written for it.
 * Quantities only, so everyone on the site may see these.
 */
class MaterialSource implements AlertSource
{
    public const OVER_BOQ = 'material.over_boq';

    public const UNACCOUNTED = 'material.unaccounted';

    /** Wastage at or above this is critical. The discussion's target is 1% to 2%. */
    private const CRITICAL_WASTAGE_PCT = 10;

    public function __construct(private MaterialCheckService $check) {}

    public function types(): array
    {
        return [self::OVER_BOQ, self::UNACCOUNTED];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        $url = route('material_control.index', $project);

        foreach ($this->check->check($project)['rows'] as $row) {
            $flags = collect($row['flags']);
            $data = Arr::only($row, ['material_id', 'material', 'unit', 'estimate', 'received', 'used', 'extra_work', 'should_be_in_stock', 'stock_on_site', 'variance', 'unaccounted', 'wastage_pct']);
            $words = ['material' => $row['material'], 'unit' => $row['unit']];

            $over = $flags->firstWhere('type', MaterialCheckService::OVER_USED);

            if ($over && $over['open']) {
                $noEstimate = ! Dec::of($row['estimate'])->isPositive();

                yield new Finding(
                    type: self::OVER_BOQ,
                    title: 'Material used beyond the BOQ',
                    message: $noEstimate
                        ? ':material: :used :unit used with no BOQ estimate for the work done.'
                        : ':material: :used :unit used against :estimate :unit in the BOQ for the work done, :gap :unit over (:wastage% wastage).',
                    params: [...$words, 'used' => $this->qty($row['used']), 'estimate' => $this->qty($row['estimate']), 'gap' => $this->qty($over['gap']), 'wastage' => Figure::trim($row['wastage_pct'] ?? '0')],
                    reference: 'material:'.$row['material_id'],
                    severity: ! $noEstimate && Dec::of($row['wastage_pct'])->isGreaterThanOrEqualTo(self::CRITICAL_WASTAGE_PCT) ? Alert::CRITICAL : Alert::WARNING,
                    data: [...$data, 'gap' => $over['gap']],
                    url: $url,
                );
            }

            $unaccounted = $flags->firstWhere('type', MaterialCheckService::UNACCOUNTED);

            if ($unaccounted && $unaccounted['open'] && Dec::of($row['unaccounted'])->isPositive()) {
                yield new Finding(
                    type: self::UNACCOUNTED,
                    title: 'Material unaccounted',
                    message: ':material: :gap :unit received but neither used nor in stock (received :received, used :used, in stock :stock).',
                    params: [...$words, 'gap' => $this->qty($row['unaccounted']), 'received' => $this->qty($row['received']), 'used' => $this->qty($row['used']), 'stock' => $this->qty($row['stock_on_site'])],
                    reference: 'material:'.$row['material_id'],
                    severity: Alert::WARNING,
                    data: [...$data, 'gap' => $row['unaccounted']],
                    url: $url,
                );
            }
        }
    }

    private function qty(mixed $value): string
    {
        return Figure::trim(Dec::qty($value));
    }
}
