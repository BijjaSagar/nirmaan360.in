<?php

namespace App\Services\Drawings;

use App\Models\BoqItem;
use App\Models\DrawingSheet;
use App\Models\Project;
use App\Models\TakeoffLayer;
use App\Models\TakeoffMeasurement;
use App\Models\User;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TakeoffService
{
    public const UNITS = ['area' => 'sqm', 'length' => 'rmt', 'count' => 'nos', 'volume' => 'cum'];

    private const MINIMUM_POINTS = ['area' => 3, 'length' => 2, 'count' => 1, 'volume' => 3];

    private const MAX_POINTS = 1000;

    private const MAX_QTY = '99999999999.999';

    /** @return array<string, list<mixed>> */
    public function createRules(DrawingSheet $sheet): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(self::UNITS))],
            'takeoff_layer_id' => ['bail', 'required', 'integer', $this->layerExists($sheet->project_id)],
            'boq_item_id' => ['bail', 'nullable', 'integer', $this->boqExists($sheet->project_id)],
            'label' => ['nullable', 'string', 'max:160'],
            'depth' => ['nullable', 'required_if:type,volume', 'numeric', 'gt:0', 'max:1000'],
            'points' => ['required', 'array', 'max:'.self::MAX_POINTS],
            'points.*' => ['required', 'array', 'size:2'],
            'points.*.0' => ['required', 'numeric'],
            'points.*.1' => ['required', 'numeric'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function updateRules(TakeoffMeasurement $measurement): array
    {
        return [
            'takeoff_layer_id' => ['bail', 'sometimes', 'required', 'integer', $this->layerExists($measurement->project_id)],
            'boq_item_id' => ['bail', 'sometimes', 'nullable', 'integer', $this->boqExists($measurement->project_id)],
            'label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'depth' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:1000'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function calibrationRules(): array
    {
        return [
            'points' => ['required', 'array', 'size:2'],
            'points.*' => ['required', 'array', 'size:2'],
            'points.*.0' => ['required', 'numeric'],
            'points.*.1' => ['required', 'numeric'],
            'length_m' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function layerRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'colour' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /**
     * Save a measurement with its quantity worked out here, whatever the browser showed.
     * A repeated client_uuid returns the original row.
     */
    public function create(DrawingSheet $sheet, array $data, User $user): TakeoffMeasurement
    {
        return DB::transaction(function () use ($sheet, $data, $user) {
            $sheet = DrawingSheet::lockForUpdate()->findOrFail($sheet->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = $this->findByClientUuid($sheet->organization_id, $uuid))) {
                return $existing;
            }

            $type = $data['type'];
            $points = $this->normalisePoints($data['points'], $sheet, $type !== 'count');
            $this->assertDrawable($type, $points);
            $depth = $type === 'volume' ? Dec::qty($this->clean($data['depth'])) : null;
            $boqItemId = $data['boq_item_id'] ?? null;

            if ($boqItemId) {
                $this->assertUnitMatches($type, (int) $boqItemId);
            }

            return TakeoffMeasurement::create([
                'organization_id' => $sheet->organization_id,
                'project_id' => $sheet->project_id,
                'drawing_sheet_id' => $sheet->id,
                'takeoff_layer_id' => $data['takeoff_layer_id'],
                'boq_item_id' => $boqItemId,
                'type' => $type,
                'label' => $data['label'] ?? null,
                'points' => $points,
                'depth' => $depth,
                'qty' => $this->quantity($type, $points, $this->scale($sheet, $type), $depth),
                'unit' => self::UNITS[$type],
                'created_by' => $user->id,
                'client_uuid' => $uuid,
            ]);
        });
    }

    /** Change a measurement's layer, BOQ link, label or depth. Its outline is fixed: redraw to change it. */
    public function update(TakeoffMeasurement $measurement, array $data): TakeoffMeasurement
    {
        return DB::transaction(function () use ($measurement, $data) {
            $measurement = TakeoffMeasurement::lockForUpdate()->findOrFail($measurement->id);

            if (! empty($data['boq_item_id'])) {
                $this->assertUnitMatches($measurement->type, (int) $data['boq_item_id']);
            }

            if ($measurement->type === 'volume' && array_key_exists('depth', $data)) {
                $data['depth'] = Dec::qty($this->clean($data['depth']));
                $sheet = DrawingSheet::findOrFail($measurement->drawing_sheet_id);
                $data['qty'] = $this->quantity('volume', $measurement->points, $this->scale($sheet, 'volume'), $data['depth']);
            } else {
                unset($data['depth']);
            }

            $measurement->update($data);

            return $measurement;
        });
    }

    /**
     * Set a sheet's scale from two points and the real distance between them.
     * Every length, area and volume already on the sheet is worked out again, in the same transaction.
     */
    public function calibrate(DrawingSheet $sheet, array $points, mixed $lengthMetres, User $user): DrawingSheet
    {
        return DB::transaction(function () use ($sheet, $points, $lengthMetres, $user) {
            $sheet = DrawingSheet::lockForUpdate()->findOrFail($sheet->id);
            $points = $this->normalisePoints($points, $sheet, false);
            $units = TakeoffGeometry::distance($points[0], $points[1]);

            if ($units->isLessThan(1)) {
                throw ValidationException::withMessages(['points' => __('The two points are too close together. Pick points further apart.')]);
            }

            $length = Dec::of($this->clean($lengthMetres))->toScale(3, RoundingMode::HalfUp);

            if (! $length->isPositive()) {
                throw ValidationException::withMessages(['length_m' => __('Enter the real distance between the two points.')]);
            }

            $sheet->update([
                'calib_points' => $points,
                'calib_length_m' => (string) $length,
                'metres_per_unit' => (string) $length->dividedBy($units, 12, RoundingMode::HalfUp),
                'calibrated_by' => $user->id,
                'calibrated_at' => now(),
            ]);

            foreach ($sheet->measurements()->where('type', '!=', 'count')->get() as $measurement) {
                $measurement->update(['qty' => $this->quantity(
                    $measurement->type,
                    $measurement->points,
                    $this->scale($sheet, $measurement->type),
                    $measurement->depth,
                )]);
            }

            return $sheet;
        });
    }

    /** The quantity for a set of points, rounded to three decimals only at the end. */
    public function quantity(string $type, array $points, ?BigDecimal $metresPerUnit, mixed $depth = null): string
    {
        $quantity = match ($type) {
            'count' => Dec::of(count($points)),
            'length' => TakeoffGeometry::polylineLength($points)->multipliedBy($metresPerUnit),
            'area' => TakeoffGeometry::polygonArea($points)->multipliedBy($metresPerUnit)->multipliedBy($metresPerUnit),
            'volume' => TakeoffGeometry::polygonArea($points)->multipliedBy($metresPerUnit)->multipliedBy($metresPerUnit)->multipliedBy(Dec::of($depth)),
        };

        if ($quantity->isGreaterThan(self::MAX_QTY)) {
            throw ValidationException::withMessages(['points' => __('This quantity is too large to save. Check the sheet scale.')]);
        }

        return Dec::qty($quantity);
    }

    public function createLayer(Project $project, array $data): TakeoffLayer
    {
        return DB::transaction(function () use ($project, $data) {
            Project::lockForUpdate()->findOrFail($project->id);

            return TakeoffLayer::firstOrCreate(
                ['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => trim($data['name'])],
                ['colour' => strtolower($data['colour'])],
            );
        });
    }

    public function updateLayer(TakeoffLayer $layer, array $data): TakeoffLayer
    {
        $name = trim($data['name']);

        if (TakeoffLayer::where('project_id', $layer->project_id)->where('name', $name)->whereKeyNot($layer->id)->exists()) {
            throw ValidationException::withMessages(['name' => __('Another layer already has this name.')]);
        }

        $layer->update(['name' => $name, 'colour' => strtolower($data['colour'])]);

        return $layer;
    }

    public function deleteLayer(TakeoffLayer $layer): void
    {
        if ($layer->measurements()->exists()) {
            throw ValidationException::withMessages(['layer' => __('This layer has measurements and cannot be removed.')]);
        }

        $layer->delete();
    }

    /**
     * Measurements on the latest revision of each drawing. Older revisions stay on their sheets
     * but are left out of every total, so a re-measured revision is not counted twice.
     */
    public function current(Project $project): Builder
    {
        return TakeoffMeasurement::where('project_id', $project->id)
            ->whereHas('sheet.version', fn (Builder $version) => $version->where('is_current', true)->whereHas('drawing'));
    }

    /** How many saved measurements sit on an old revision of a drawing still in the register. */
    public function supersededCount(Project $project): int
    {
        return TakeoffMeasurement::where('project_id', $project->id)
            ->whereHas('sheet.version', fn (Builder $version) => $version->where('is_current', false)->whereHas('drawing'))
            ->count();
    }

    /** @return Collection<int, array{layer:TakeoffLayer, unit:string, qty:string, count:int}> */
    public function layerTotals(Project $project): Collection
    {
        $layers = TakeoffLayer::where('project_id', $project->id)->orderBy('name')->get()->keyBy('id');

        return $this->current($project)->get(['takeoff_layer_id', 'unit', 'qty'])
            ->groupBy(fn (TakeoffMeasurement $row) => $row->takeoff_layer_id.'|'.$row->unit)
            ->map(fn (Collection $rows) => [
                'layer' => $layers[$rows->first()->takeoff_layer_id],
                'unit' => $rows->first()->unit,
                'qty' => $this->sum($rows),
                'count' => $rows->count(),
            ])
            ->sortBy(fn (array $row) => $row['layer']->name)
            ->values();
    }

    /** @return Collection<int, array{item:BoqItem, takeoff:string, boq:string, difference:string, count:int}> */
    public function boqTotals(Project $project): Collection
    {
        $rows = $this->current($project)->whereNotNull('boq_item_id')->get(['boq_item_id', 'qty'])->groupBy('boq_item_id');
        $items = BoqItem::where('project_id', $project->id)->whereIn('id', $rows->keys())->orderBy('sort')->orderBy('id')->get();

        return $items->map(function (BoqItem $item) use ($rows) {
            $takeoff = $this->sum($rows[$item->id]);

            return [
                'item' => $item,
                'takeoff' => $takeoff,
                'boq' => Dec::qty($item->qty),
                'difference' => Dec::qty(Dec::of($takeoff)->minus(Dec::of($item->qty))),
                'count' => $rows[$item->id]->count(),
            ];
        })->values();
    }

    /** Copy the takeoff total into the BOQ line. As with a manual edit, it cannot drop below the approved quantity. */
    public function applyToBoq(BoqItem $item): BoqItem
    {
        return DB::transaction(function () use ($item) {
            $item = BoqItem::lockForUpdate()->findOrFail($item->id);
            $project = Project::findOrFail($item->project_id);
            $rows = $this->current($project)->where('boq_item_id', $item->id)->get(['qty']);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['boq' => __('This BOQ item has no takeoff on the latest drawings.')]);
            }

            $total = $this->sum($rows);
            $approved = $item->measurements()->where('status', 'APPROVED')->pluck('qty')
                ->reduce(fn (BigDecimal $sum, $qty) => $sum->plus(Dec::of($qty)), BigDecimal::zero());

            if ($approved->isGreaterThan(Dec::of($total))) {
                throw ValidationException::withMessages(['boq' => __('Quantity cannot be lower than the approved quantity (:qty).', ['qty' => fmt_qty($approved)])]);
            }

            $item->update(['qty' => $total]);

            return $item;
        });
    }

    /** The shape the viewer needs for one measurement. */
    public function present(TakeoffMeasurement $measurement): array
    {
        return [
            'id' => $measurement->id,
            'type' => $measurement->type,
            'layer_id' => $measurement->takeoff_layer_id,
            'boq_item_id' => $measurement->boq_item_id,
            'label' => $measurement->label,
            'points' => $measurement->points,
            'depth' => $measurement->depth,
            'qty' => $measurement->qty,
            'unit' => $measurement->unit,
        ];
    }

    /** Which kind of measurement a BOQ unit can take. Null when the unit is not a length, area, volume or count. */
    public static function dimensionOfUnit(string $unit): ?string
    {
        $unit = strtolower(str_replace([' ', '.', '²', '³'], ['', '', '2', '3'], trim($unit)));

        return match (true) {
            in_array($unit, ['rmt', 'm', 'mtr', 'mtrs', 'rm', 'lm', 'metre', 'meter', 'metres', 'meters'], true) => 'length',
            in_array($unit, ['sqm', 'sqmt', 'sqmtr', 'm2'], true) => 'area',
            in_array($unit, ['cum', 'cumt', 'cumtr', 'm3'], true) => 'volume',
            in_array($unit, ['nos', 'no', 'each', 'ea', 'number', 'numbers'], true) => 'count',
            default => null,
        };
    }

    private function sum(Collection $rows): string
    {
        return Dec::qty($rows->reduce(fn (BigDecimal $sum, TakeoffMeasurement $row) => $sum->plus(Dec::of($row->qty)), BigDecimal::zero()));
    }

    private function scale(DrawingSheet $sheet, string $type): ?BigDecimal
    {
        if ($type === 'count') {
            return null;
        }

        if ($sheet->metres_per_unit === null) {
            throw ValidationException::withMessages(['points' => __('Set the scale of this sheet before measuring lengths, areas or volumes.')]);
        }

        return Dec::of($sheet->metres_per_unit);
    }

    /** Fix each point to three decimals and keep it on the sheet. */
    private function normalisePoints(array $points, DrawingSheet $sheet, bool $dropRepeats): array
    {
        $width = Dec::of($sheet->width)->plus(1);
        $height = Dec::of($sheet->height)->plus(1);
        $clean = [];

        foreach (array_values($points) as $point) {
            $x = Dec::of($this->clean($point[0]))->toScale(3, RoundingMode::HalfUp);
            $y = Dec::of($this->clean($point[1]))->toScale(3, RoundingMode::HalfUp);

            if ($x->isNegative() || $y->isNegative() || $x->isGreaterThan($width) || $y->isGreaterThan($height)) {
                throw ValidationException::withMessages(['points' => __('A point is outside the sheet.')]);
            }

            $pair = [(string) $x, (string) $y];

            if (! $dropRepeats || $clean === [] || end($clean) !== $pair) {
                $clean[] = $pair;
            }
        }

        // A closing click on the first corner adds nothing.
        if ($dropRepeats && count($clean) > 1 && $clean[0] === end($clean)) {
            array_pop($clean);
        }

        return $clean;
    }

    private function assertDrawable(string $type, array $points): void
    {
        if (count($points) < self::MINIMUM_POINTS[$type]) {
            throw ValidationException::withMessages(['points' => __('This measurement needs at least :count points.', ['count' => self::MINIMUM_POINTS[$type]])]);
        }

        if (! in_array($type, ['area', 'volume'], true)) {
            return;
        }

        if (TakeoffGeometry::selfIntersects($points)) {
            throw ValidationException::withMessages(['points' => __('The outline crosses itself, so its area cannot be measured. Please redraw it.')]);
        }

        if (TakeoffGeometry::polygonArea($points)->isZero()) {
            throw ValidationException::withMessages(['points' => __('The outline encloses no area. Please redraw it.')]);
        }
    }

    private function assertUnitMatches(string $type, int $boqItemId): void
    {
        $unit = BoqItem::whereKey($boqItemId)->value('unit');

        if (self::dimensionOfUnit((string) $unit) !== $type) {
            throw ValidationException::withMessages(['boq_item_id' => __('This BOQ item is in :unit, but this measurement gives :measured.', ['unit' => $unit, 'measured' => self::UNITS[$type]])]);
        }
    }

    private function layerExists(int $projectId)
    {
        return Rule::exists('takeoff_layers', 'id')->where('project_id', $projectId);
    }

    private function boqExists(int $projectId)
    {
        return Rule::exists('boq_items', 'id')->where('project_id', $projectId)->whereNull('deleted_at');
    }

    private function clean(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    /** Trashed rows count: the unique key still holds their client_uuid. */
    private function findByClientUuid(int $organizationId, string $uuid): ?TakeoffMeasurement
    {
        return TakeoffMeasurement::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
