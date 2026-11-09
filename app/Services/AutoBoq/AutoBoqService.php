<?php

namespace App\Services\AutoBoq;

use App\Models\AutoBoqInput;
use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Models\RateLibraryItem;
use App\Models\TakeoffLayer;
use App\Models\User;
use App\Services\Drawings\TakeoffService;
use App\Services\ProjectSetup\BoqRevisions;
use App\Support\Dec;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves a project's dimensions, previews the BOQ they give, and applies the lines the engineer
 * keeps. The preview writes nothing. Applying goes through BoqRevisions, so the BOQ history keeps
 * every change, matches lines by their stable code so a re-run updates rather than duplicates,
 * and never takes a quantity below what has already been approved. Rates stay at zero unless the
 * engineer picks one from the rate library.
 */
class AutoBoqService
{
    /** Scalar fields a takeoff layer can fill, with the unit the layer's measurements must have. */
    public const SCALAR_LAYERS = ['plinth_area' => 'sqm', 'stair_area' => 'sqm', 'chajja_length' => 'rmt'];

    /** Row fields a takeoff layer can fill, per element group. */
    public const ROW_LAYERS = [
        'footings' => ['nos' => 'nos'],
        'columns' => ['nos' => 'nos'],
        'beams' => ['length' => 'rmt'],
        'slabs' => ['area' => 'sqm', 'openings' => 'sqm'],
        'walls' => ['length' => 'rmt', 'openings' => 'sqm'],
    ];

    /** The figures of a row in each element group, with the largest value each takes. */
    public const ROW_FIELDS = [
        'footings' => ['length' => '100', 'breadth' => '100', 'depth' => '20', 'nos' => '9999'],
        'columns' => ['length' => '10', 'breadth' => '10', 'height' => '30', 'nos' => '9999', 'floors' => '60'],
        'beams' => ['length' => '99999', 'width' => '10', 'depth' => '10', 'floors' => '60'],
        'slabs' => ['area' => '999999', 'openings' => '999999', 'thickness' => '2', 'count' => '60'],
        'walls' => ['length' => '99999', 'height' => '30', 'thickness' => '2', 'openings' => '999999', 'floors' => '60'],
    ];

    private const WHOLE = ['nos', 'floors', 'count'];

    private const MAX_ROWS = 40;

    public function __construct(
        private AutoBoqCalculator $calculator,
        private Assumptions $assumptions,
        private TakeoffService $takeoff,
    ) {}

    /** @return array<string, list<mixed>> */
    public function rules(Project $project): array
    {
        $layer = ['nullable', 'integer', Rule::exists('takeoff_layers', 'id')->where('project_id', $project->id)];
        $figure = fn (string $max) => ['nullable', 'numeric', 'min:0', 'max:'.$max];
        $whole = fn (string $max) => ['nullable', 'integer', 'min:0', 'max:'.$max];

        $rules = [
            'floors' => ['required', 'integer', 'min:1', 'max:60'],
            'floor_height' => ['required', 'numeric', 'gt:0', 'max:30'],
            'plinth_area' => $figure('999999'),
            'plinth_height' => $figure('10'),
            'foundation_depth' => $figure('50'),
            'stair_area' => $figure('9999'),
            'chajja_length' => $figure('99999'),
            'chajja_width' => $figure('10'),
            'chajja_thickness' => $figure('2'),
        ];

        foreach (array_keys(self::SCALAR_LAYERS) as $field) {
            $rules[$field.'_layer'] = $layer;
        }

        foreach (self::ROW_FIELDS as $group => $fields) {
            $rules[$group] = ['nullable', 'array', 'max:'.self::MAX_ROWS];
            $rules["{$group}.*.label"] = ['nullable', 'string', 'max:40'];

            foreach ($fields as $field => $max) {
                $rules["{$group}.*.{$field}"] = in_array($field, self::WHOLE, true) ? $whole($max) : $figure($max);
            }

            foreach (array_keys(self::ROW_LAYERS[$group]) as $field) {
                $rules["{$group}.*.{$field}_layer"] = $layer;
            }
        }

        $rules['walls.*.kind'] = ['nullable', Rule::in(['external', 'internal'])];

        return $rules;
    }

    /**
     * Save the dimensions. A figure with a takeoff layer chosen is taken from that layer's total
     * on the latest drawings, so saving again after re-measuring refreshes it.
     */
    public function save(Project $project, array $data, User $user): AutoBoqInput
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $totals = $this->takeoffTotals($project);

            $input = AutoBoqInput::where('project_id', $project->id)->first()
                ?? new AutoBoqInput(['organization_id' => $project->organization_id, 'project_id' => $project->id]);

            $values = ['floors' => (int) $data['floors'], 'updated_by' => $user->id, 'layers' => []];

            foreach (AutoBoqInput::SCALARS as $field) {
                $layerId = $data[$field.'_layer'] ?? null;

                if (filled($layerId) && isset(self::SCALAR_LAYERS[$field])) {
                    $values[$field] = $this->fromLayer($totals, (int) $layerId, self::SCALAR_LAYERS[$field], $field.'_layer');
                    $values['layers'][$field] = (int) $layerId;
                } else {
                    $values[$field] = Dec::qty($this->clean($data[$field] ?? null));
                }
            }

            foreach (array_keys(self::ROW_FIELDS) as $group) {
                $values[$group] = $this->rows($group, (array) ($data[$group] ?? []), $totals);
            }

            $input->fill($values)->save();

            return $input;
        });
    }

    /** Everything the project screen shows. */
    public function screen(Project $project, User $user): array
    {
        $input = AutoBoqInput::where('project_id', $project->id)->first();
        $totals = $this->takeoffTotals($project);
        $problem = null;
        $preview = null;

        if ($input !== null) {
            try {
                $preview = $this->preview($project, $input);
            } catch (ValidationException $exception) {
                $problem = collect($exception->errors())->flatten()->first();
            }
        }

        return [
            'project' => $project,
            'input' => $input,
            'form' => $this->formValues($input),
            'preview' => $preview,
            'problem' => $problem,
            'assumptions' => $this->assumptions->forOrganization($project->organization_id),
            'takeoffTotals' => $totals->mapWithKeys(fn (array $row, string $key) => [$key => $row['qty']])->all(),
            'takeoffByUnit' => $totals->values()->groupBy('unit')->map(fn (Collection $rows) => $rows->values()->all())->all(),
            'rateChoices' => Money::visible($user) ? $this->rateChoices() : null,
        ];
    }

    /**
     * The lines the saved dimensions give, each against the BOQ as it stands. Nothing is written.
     *
     * @return Collection<string, array{line: AutoBoqLine, existing: ?BoqItem, approved: string, below: bool, unit_differs: bool, status: string, selected: bool}>
     */
    public function preview(Project $project, ?AutoBoqInput $input = null): Collection
    {
        $input ??= AutoBoqInput::where('project_id', $project->id)->first();

        if ($input === null) {
            throw ValidationException::withMessages(['dimensions' => __('Save the dimensions first.')]);
        }

        $lines = $this->calculator->lines($input->dimensions(), $this->assumptions->forOrganization($project->organization_id));
        $existing = BoqItem::where('project_id', $project->id)->whereIn('code', array_keys($lines))->orderBy('id')->get()->unique('code')->keyBy('code');
        $approved = MeasurementEntry::where('project_id', $project->id)->where('status', 'APPROVED')
            ->whereIn('boq_item_id', $existing->pluck('id'))
            ->selectRaw('boq_item_id, sum(qty) as total')->groupBy('boq_item_id')->pluck('total', 'boq_item_id');
        $excluded = $input->excluded ?? [];

        return collect($lines)->map(function (AutoBoqLine $line) use ($existing, $approved, $excluded) {
            $item = $existing->get($line->code);
            $approvedQty = Dec::qty($item ? ($approved->get($item->id) ?? 0) : 0);
            $below = Dec::of($approvedQty)->isGreaterThan($line->exact());
            $unitDiffers = $item !== null && $this->unitKey($item->unit) !== $this->unitKey($line->unit);

            $status = match (true) {
                $item === null => 'new',
                Dec::of($item->qty)->isEqualTo($line->exact()) => 'same',
                default => 'update',
            };

            return [
                'line' => $line,
                'existing' => $item,
                'approved' => $approvedQty,
                'below' => $below,
                'unit_differs' => $unitDiffers,
                'status' => $status,
                'selected' => ! $below && ! $unitDiffers && ! in_array($line->code, $excluded, true),
            ];
        });
    }

    /** @return array<string, list<mixed>> */
    public function applyRules(Project $project, User $user): array
    {
        $rules = [
            'codes' => ['required', 'array', 'min:1'],
            'codes.*' => ['required', 'string', Rule::in(AutoBoqCalculator::codes())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];

        if (Money::visible($user)) {
            $rules['rates'] = ['nullable', 'array'];
            $rules['rates.*'] = ['nullable', 'integer', Rule::exists('rate_library_items', 'id')->where('organization_id', $project->organization_id)->whereNull('deleted_at')];
        }

        return $rules;
    }

    /**
     * Write the chosen lines into the BOQ, in one batch of the BOQ history. A line that already
     * exists with the same code gets the new quantity and working; its description, unit and rate
     * are left as the engineer has them, unless a rate is picked. Nothing is written when any
     * chosen line would go below its approved quantity or is kept in another unit.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function apply(Project $project, array $data, User $user): array
    {
        return DB::transaction(function () use ($project, $data, $user) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $input = AutoBoqInput::where('project_id', $project->id)->first();
            $rows = $this->preview($project, $input);
            $codes = array_values(array_unique($data['codes']));
            $chosen = $rows->only($codes);

            $blocked = $chosen->filter(fn (array $row) => $row['below'])->keys();

            if ($blocked->isNotEmpty()) {
                throw ValidationException::withMessages(['codes' => __('These lines would go below their approved quantity, so nothing was applied: :codes. Drop them and apply again.', ['codes' => $blocked->implode(', ')])]);
            }

            $blocked = $chosen->filter(fn (array $row) => $row['unit_differs'])->keys();

            if ($blocked->isNotEmpty()) {
                throw ValidationException::withMessages(['codes' => __('These lines are kept in another unit in the BOQ, so nothing was applied: :codes. Drop them or change the unit.', ['codes' => $blocked->implode(', ')])]);
            }

            $rates = Money::visible($user) && ! empty($data['rates'])
                ? RateLibraryItem::whereIn('id', array_filter(array_values($data['rates'])))->get()->keyBy('id')
                : collect();
            $picked = collect($data['rates'] ?? [])->filter();

            $sort = (int) BoqItem::where('project_id', $project->id)->max('sort');
            $created = $updated = $unchanged = 0;

            BoqRevisions::with(filled($data['reason'] ?? null) ? $data['reason'] : __('Worked out from the building dimensions'), 'auto_boq', function () use ($chosen, $project, $rates, $picked, &$sort, &$created, &$updated, &$unchanged) {
                foreach ($chosen as $code => $row) {
                    /** @var AutoBoqLine $line */
                    $line = $row['line'];
                    $values = ['qty' => $line->qty, 'details' => $line->details()];

                    if ($picked->has($code) && ($rate = $rates->get((int) $picked->get($code)))) {
                        $values['rate'] = Dec::money($rate->rate);
                    }

                    if ($item = $row['existing']) {
                        if ($item->category === null) {
                            $values['category'] = $line->category;
                        }

                        $item->update($values);
                        $item->wasChanged() ? $updated++ : $unchanged++;

                        continue;
                    }

                    BoqItem::create([
                        ...$values,
                        'organization_id' => $project->organization_id,
                        'project_id' => $project->id,
                        'code' => $code,
                        'description' => $line->description,
                        'unit' => $line->unit,
                        'category' => $line->category,
                        'rate' => $values['rate'] ?? '0.00',
                        'sort' => ++$sort,
                    ]);
                    $created++;
                }
            }, batch: true);

            $input->update([
                'excluded' => array_values(array_diff($rows->keys()->all(), $codes)),
                'applied_at' => now(),
                'applied_by' => $user->id,
            ]);

            return ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged];
        });
    }

    /**
     * Totals of the takeoff layers on the latest drawings, keyed "layer id|unit".
     *
     * @return Collection<string, array{id: int, layer: string, unit: string, qty: string, count: int}>
     */
    public function takeoffTotals(Project $project): Collection
    {
        return $this->takeoff->layerTotals($project)->mapWithKeys(fn (array $row) => [
            $row['layer']->id.'|'.$row['unit'] => ['id' => $row['layer']->id, 'layer' => $row['layer']->name, 'unit' => $row['unit'], 'qty' => $row['qty'], 'count' => $row['count']],
        ]);
    }

    /** The saved dimensions as the form shows them, or a blank form. */
    public function formValues(?AutoBoqInput $input): array
    {
        $form = ['floors' => $input?->floors ?? 1];

        foreach (AutoBoqInput::SCALARS as $field) {
            $form[$field] = $input ? $this->figure($input->{$field}) : '';
            $form[$field.'_layer'] = $input?->layers[$field] ?? '';
        }

        foreach (self::ROW_FIELDS as $group => $fields) {
            $form[$group] = array_map(function (array $row) use ($group, $fields) {
                $clean = ['label' => $row['label'] ?? ''];

                if ($group === 'walls') {
                    $clean['kind'] = $row['kind'] ?? 'external';
                }

                foreach (array_keys($fields) as $field) {
                    $clean[$field] = $this->figure($row[$field] ?? null);
                }

                foreach (array_keys(self::ROW_LAYERS[$group]) as $field) {
                    $clean[$field.'_layer'] = $row[$field.'_layer'] ?? '';
                }

                return $clean;
            }, array_values($input?->{$group} ?? []));
        }

        return $form;
    }

    /** An empty row of each group, for the "add" button. @return array<string, array<string, string>> */
    public function blankRows(): array
    {
        $blank = [];

        foreach (self::ROW_FIELDS as $group => $fields) {
            $row = ['label' => ''];

            if ($group === 'walls') {
                $row['kind'] = 'external';
            }

            foreach (array_keys($fields) as $field) {
                $row[$field] = '';
            }

            foreach (array_keys(self::ROW_LAYERS[$group]) as $field) {
                $row[$field.'_layer'] = '';
            }

            $blank[$group] = $row;
        }

        return $blank;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $group, array $posted, Collection $totals): array
    {
        $rows = [];

        foreach (array_values($posted) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = ['label' => filled($row['label'] ?? null) ? mb_substr(trim((string) $row['label']), 0, 40) : null];

            if ($group === 'walls') {
                $clean['kind'] = ($row['kind'] ?? 'external') === 'internal' ? 'internal' : 'external';
            }

            $blank = true;

            foreach (array_keys(self::ROW_FIELDS[$group]) as $field) {
                $layerId = $row[$field.'_layer'] ?? null;

                if (filled($layerId) && isset(self::ROW_LAYERS[$group][$field])) {
                    $clean[$field] = $this->fromLayer($totals, (int) $layerId, self::ROW_LAYERS[$group][$field], "{$group}.{$field}_layer");
                    $clean[$field.'_layer'] = (int) $layerId;
                    $blank = false;

                    continue;
                }

                $value = $this->clean($row[$field] ?? null);
                $clean[$field] = $value === null || $value === '' ? null : (in_array($field, self::WHOLE, true) ? (string) (int) $value : Dec::qty($value));
                $blank = $blank && $clean[$field] === null;
            }

            if (! $blank) {
                $rows[] = $clean;
            }
        }

        return $rows;
    }

    /** The total of a layer in the unit a field needs, or a validation error naming the layer. */
    private function fromLayer(Collection $totals, int $layerId, string $unit, string $field): string
    {
        $total = $totals->get($layerId.'|'.$unit);

        if ($total === null) {
            $name = TakeoffLayer::whereKey($layerId)->value('name') ?? (string) $layerId;

            throw ValidationException::withMessages([$field => __('Layer :layer has no :unit measurements on the latest drawings.', ['layer' => $name, 'unit' => $unit])]);
        }

        return Dec::qty($total['qty']);
    }

    /** Company rates grouped by unit, for the rate picker. @return array<string, list<RateLibraryItem>> */
    private function rateChoices(): array
    {
        return RateLibraryItem::orderBy('item')->orderBy('id')->get()
            ->groupBy(fn (RateLibraryItem $item) => $this->unitKey($item->unit))
            ->map(fn (Collection $items) => $items->values()->all())
            ->all();
    }

    private function unitKey(string $unit): string
    {
        return TakeoffService::dimensionOfUnit($unit) ?? strtolower(trim($unit));
    }

    /** A stored figure as the form shows it: blank when nothing was entered, otherwise with trailing zeros dropped. */
    private function figure(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $fixed = (string) BigDecimal::of((string) $value)->toScale(3, RoundingMode::HalfUp);

        return str_contains($fixed, '.') ? rtrim(rtrim($fixed, '0'), '.') : $fixed;
    }

    private function clean(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}
