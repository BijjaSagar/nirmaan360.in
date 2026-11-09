<?php

namespace App\Services\Drawings;

use App\Models\BbsBar;
use App\Models\BbsSchedule;
use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BbsService
{
    public function __construct(private BbsCalculator $calculator) {}

    /** @return array<string, list<mixed>> */
    public function scheduleRules(Project $project): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'drawing_id' => ['bail', 'nullable', 'integer', Rule::exists('drawings', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function barRules(): array
    {
        return [
            'location' => ['nullable', 'string', 'max:120'],
            'mark' => ['required', 'string', 'max:32'],
            'steel_type' => ['required', Rule::in(array_keys(BbsCalculator::steelTypes()))],
            'dia_mm' => ['required', 'integer', Rule::in(BbsCalculator::SIZES)],
            'sets' => ['required', 'integer', 'min:1', 'max:100000'],
            'per_set' => ['required', 'integer', 'min:1', 'max:100000'],
            'shape_code' => ['required', Rule::in(array_keys(BbsCalculator::shapes()))],
            'dims' => ['required', 'array'],
            'dims.*' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Start a numbered schedule. A repeated client_uuid returns the original. */
    public function createSchedule(Project $project, array $data, User $user): BbsSchedule
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = $this->findSchedule($project->organization_id, $uuid))) {
                return $existing;
            }

            // Deleted schedules are counted too, so a number is never reused.
            $next = BbsSchedule::withTrashed()->where('project_id', $project->id)->count() + 1;

            return BbsSchedule::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'drawing_id' => $data['drawing_id'] ?? null,
                'number' => 'BBS-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT),
                'title' => $data['title'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
                'client_uuid' => $uuid,
            ]);
        });
    }

    public function updateSchedule(BbsSchedule $schedule, array $data): BbsSchedule
    {
        $schedule->update(collect($data)->only(['title', 'notes', 'drawing_id'])->all());

        return $schedule;
    }

    public function deleteSchedule(BbsSchedule $schedule): void
    {
        $schedule->delete();
    }

    /** Add a line, worked out here. A repeated client_uuid returns the original. */
    public function addBar(BbsSchedule $schedule, array $data): BbsBar
    {
        return DB::transaction(function () use ($schedule, $data) {
            $schedule = BbsSchedule::lockForUpdate()->findOrFail($schedule->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = $this->findBar($schedule->organization_id, $uuid))) {
                return $existing;
            }

            return BbsBar::create([
                ...$this->barValues($data),
                'organization_id' => $schedule->organization_id,
                'bbs_schedule_id' => $schedule->id,
                'sort' => (int) BbsBar::where('bbs_schedule_id', $schedule->id)->max('sort') + 1,
                'client_uuid' => $uuid,
            ]);
        });
    }

    public function updateBar(BbsBar $bar, array $data): BbsBar
    {
        $bar->update($this->barValues($data));

        return $bar;
    }

    public function deleteBar(BbsBar $bar): void
    {
        $bar->delete();
    }

    /**
     * Length and weight for each bar size, plus the total weight.
     *
     * @return array{sizes:list<array{dia:int,bars:int,length_m:string,weight_kg:string}>, total_weight_kg:string, total_bars:int}
     */
    public function summary(BbsSchedule $schedule): array
    {
        $sizes = [];
        $total = BigDecimal::zero();
        $bars = 0;

        foreach ($schedule->bars()->get() as $bar) {
            $size = $sizes[$bar->dia_mm] ?? ['dia' => $bar->dia_mm, 'bars' => 0, 'length' => BigDecimal::zero(), 'weight' => BigDecimal::zero()];
            $size['bars'] += $bar->total_bars;
            $size['length'] = $size['length']->plus(Dec::of($bar->total_length_m));
            $size['weight'] = $size['weight']->plus(Dec::of($bar->total_weight_kg));
            $sizes[$bar->dia_mm] = $size;
            $total = $total->plus(Dec::of($bar->total_weight_kg));
            $bars += $bar->total_bars;
        }

        ksort($sizes);

        return [
            'sizes' => array_values(array_map(fn (array $size) => [
                'dia' => $size['dia'],
                'bars' => $size['bars'],
                'length_m' => Dec::qty($size['length']),
                'weight_kg' => Dec::qty($size['weight']),
            ], $sizes)),
            'total_weight_kg' => Dec::qty($total),
            'total_bars' => $bars,
        ];
    }

    /** One bar's dimensions as text, such as "A=600, E=2400". */
    public static function describeDims(array $dims): string
    {
        return collect($dims)->map(fn ($value, $name) => $name.'='.$value)->implode(', ');
    }

    private function barValues(array $data): array
    {
        $result = $this->calculator->calculate(
            $data['shape_code'],
            $data['steel_type'],
            (int) $data['dia_mm'],
            $data['dims'],
            (int) $data['sets'],
            (int) $data['per_set'],
        );

        return [
            'location' => $data['location'] ?? null,
            'mark' => $data['mark'],
            'steel_type' => $data['steel_type'],
            'dia_mm' => (int) $data['dia_mm'],
            'sets' => (int) $data['sets'],
            'per_set' => (int) $data['per_set'],
            'shape_code' => $data['shape_code'],
            ...$result,
        ];
    }

    private function findSchedule(int $organizationId, string $uuid): ?BbsSchedule
    {
        return BbsSchedule::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    private function findBar(int $organizationId, string $uuid): ?BbsBar
    {
        return BbsBar::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
