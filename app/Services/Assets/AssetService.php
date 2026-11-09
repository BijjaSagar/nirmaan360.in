<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\Site;
use App\Models\User;
use App\Services\FieldOps\CreatesOnce;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The asset register: adding plant, editing it and moving it between sites. */
class AssetService
{
    use CreatesOnce;

    private const FIELDS = [
        'name', 'code', 'category', 'make', 'model', 'registration_no', 'serial_no', 'ownership',
        'purchase_date', 'purchase_cost', 'vendor_id', 'hire_basis', 'hire_rate', 'min_hours_per_month',
        'hire_type', 'hire_from', 'hire_to', 'meter_unit', 'location_note', 'notes',
    ];

    /** One rule set for the web form and any API. */
    public function rules(User $user, ?Asset $asset = null): array
    {
        $org = $user->organization_id;
        $hired = 'required_if:ownership,hired';

        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('assets', 'code')->where('organization_id', $org)->ignore($asset?->id)],
            'category' => ['required', Rule::in(array_keys(config('modules.assets.categories')))],
            'make' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:80'],
            'registration_no' => ['nullable', 'string', 'max:40'],
            'serial_no' => ['nullable', 'string', 'max:60'],
            'ownership' => ['required', Rule::in(array_keys(config('modules.assets.ownerships')))],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'vendor_id' => ['bail', 'nullable', 'integer', Rule::exists('parties', 'id')->where('organization_id', $org)->whereIn('type', ['vendor', 'subcontractor'])->whereNull('deleted_at')],
            'hire_basis' => [$hired, 'nullable', Rule::in(array_keys(config('modules.assets.hire_bases')))],
            'hire_rate' => [$hired, 'nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'min_hours_per_month' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'hire_type' => ['nullable', Rule::in(array_keys(config('modules.assets.hire_types')))],
            'hire_from' => ['nullable', 'date'],
            'hire_to' => ['nullable', 'date', 'after_or_equal:hire_from'],
            'meter_unit' => ['nullable', Rule::in(array_keys(config('modules.assets.meter_units')))],
            'project_id' => ['bail', 'nullable', 'integer', $this->projectRule($org)],
            'site_id' => ['bail', 'nullable', 'integer', Rule::exists('sites', 'id')->where('organization_id', $org)->whereNull('deleted_at')],
            'location_note' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['active', 'retired'])],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function transferRules(User $user): array
    {
        return [
            'to_project_id' => ['bail', 'nullable', 'integer', 'required_without:to_location', $this->projectRule($user->organization_id)],
            'to_site_id' => ['bail', 'nullable', 'integer', Rule::exists('sites', 'id')->where('organization_id', $user->organization_id)->whereNull('deleted_at')],
            'to_location' => ['nullable', 'string', 'max:160', 'required_without:to_project_id'],
            'moved_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Register an asset. A repeated client_uuid returns the original row. */
    public function create(array $data, User $user): Asset
    {
        $org = $user->organization_id;

        return $this->createOnce(Asset::class, $org, $data['client_uuid'] ?? null, function () use ($data, $org, $user) {
            $this->assertSiteMatchesProject($data['site_id'] ?? null, $data['project_id'] ?? null);

            $asset = Asset::create([
                ...$this->attributes($data),
                'organization_id' => $org,
                'code' => filled($data['code'] ?? null) ? $data['code'] : $this->nextCode($org),
                'tag' => $this->newTag(),
                'project_id' => $data['project_id'] ?? null,
                'site_id' => $data['site_id'] ?? null,
                'status' => 'active',
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            if ($asset->project_id || $asset->location_note) {
                AssetMovement::create([
                    'organization_id' => $org,
                    'asset_id' => $asset->id,
                    'to_project_id' => $asset->project_id,
                    'to_site_id' => $asset->site_id,
                    'to_location' => $asset->location_note,
                    'moved_on' => today(),
                    'moved_by' => $user->id,
                ]);
            }

            return $asset;
        });
    }

    /** Edit the details. Where it is now changes only through a transfer. */
    public function update(Asset $asset, array $data): Asset
    {
        $changes = $this->attributes($data, $asset);
        unset($changes['location_note']); // only a transfer moves an asset, so the history stays complete

        if (filled($data['code'] ?? null)) {
            $changes['code'] = $data['code'];
        }

        if (isset($data['status'])) {
            $changes['status'] = $data['status'] === 'active' && $asset->breakdowns()->where('status', 'open')->exists()
                ? 'under_repair'
                : $data['status'];
        }

        $asset->update($changes);

        return $asset;
    }

    /** Move an asset. A repeated client_uuid returns the original move and changes nothing. */
    public function transfer(Asset $asset, array $data, User $user): AssetMovement
    {
        return $this->createOnce(AssetMovement::class, $asset->organization_id, $data['client_uuid'] ?? null, function () use ($asset, $data, $user) {
            if ($asset->isRetired()) {
                throw ValidationException::withMessages(['asset_id' => __('A retired asset cannot be moved.')]);
            }

            $toProject = $data['to_project_id'] ?? null;
            $this->assertSiteMatchesProject($data['to_site_id'] ?? null, $toProject);

            $movement = AssetMovement::create([
                'organization_id' => $asset->organization_id,
                'asset_id' => $asset->id,
                'from_project_id' => $asset->project_id,
                'from_site_id' => $asset->site_id,
                'from_location' => $asset->location_note,
                'to_project_id' => $toProject,
                'to_site_id' => $data['to_site_id'] ?? null,
                'to_location' => $data['to_location'] ?? null,
                'moved_on' => $data['moved_on'],
                'reason' => $data['reason'] ?? null,
                'moved_by' => $user->id,
                'client_uuid' => $data['client_uuid'] ?? null,
            ]);

            $asset->update([
                'project_id' => $toProject,
                'site_id' => $data['to_site_id'] ?? null,
                'location_note' => $data['to_location'] ?? null,
            ]);

            return $movement;
        });
    }

    /**
     * What an asset has done and cost so far.
     *
     * @return array{hours:string,km:string,fuel_litres:string,fuel_cost:string,repair_cost:string,consumption:?string,consumption_unit:?string}
     */
    public function summary(Asset $asset): array
    {
        $hours = Dec::qty($asset->logs()->sum('hours'));
        $km = Dec::qty($asset->logs()->sum('km'));
        $litres = Dec::qty($asset->fuelIssues()->sum('litres'));
        $repairs = Dec::of($asset->serviceRecords()->sum('cost'))->plus(Dec::of($asset->breakdowns()->sum('repair_cost')));

        $consumption = null;
        $unit = null;

        if ($asset->meter_unit === 'hours' && Dec::of($hours)->isPositive() && Dec::of($litres)->isPositive()) {
            $consumption = (string) Dec::of($litres)->dividedBy($hours, 2, RoundingMode::HalfUp);
            $unit = 'L/h';
        } elseif ($asset->meter_unit === 'km' && Dec::of($litres)->isPositive() && Dec::of($km)->isPositive()) {
            $consumption = (string) Dec::of($km)->dividedBy($litres, 2, RoundingMode::HalfUp);
            $unit = 'km/L';
        }

        return [
            'hours' => $hours,
            'km' => $km,
            'fuel_litres' => $litres,
            'fuel_cost' => Dec::money($asset->fuelIssues()->sum('amount')),
            'repair_cost' => Dec::money($repairs),
            'consumption' => $consumption,
            'consumption_unit' => $unit,
        ];
    }

    private function attributes(array $data, ?Asset $existing = null): array
    {
        $attributes = Arr::only($data, self::FIELDS);
        unset($attributes['code']);

        $ownership = $attributes['ownership'] ?? $existing?->ownership ?? 'owned';

        if ($ownership === 'hired') {
            $attributes['purchase_date'] = null;
            $attributes['purchase_cost'] = null;
            $attributes['hire_type'] = $attributes['hire_type'] ?? $existing?->hire_type ?? 'dry';
        } else {
            foreach (['vendor_id', 'hire_basis', 'hire_rate', 'min_hours_per_month', 'hire_from', 'hire_to'] as $key) {
                $attributes[$key] = null;
            }

            $attributes['hire_type'] = 'dry';
        }

        if (($attributes['hire_basis'] ?? null) !== 'hour') {
            $attributes['min_hours_per_month'] = null;
        }

        foreach (['purchase_cost', 'hire_rate'] as $key) {
            if (filled($attributes[$key] ?? null)) {
                $attributes[$key] = Dec::money($attributes[$key]);
            }
        }

        if (filled($attributes['min_hours_per_month'] ?? null)) {
            $attributes['min_hours_per_month'] = Dec::qty($attributes['min_hours_per_month']);
        }

        return $attributes;
    }

    private function assertSiteMatchesProject(?int $siteId, ?int $projectId): void
    {
        if ($siteId === null) {
            return;
        }

        if ($projectId === null || ! Site::where('id', $siteId)->where('project_id', $projectId)->exists()) {
            throw ValidationException::withMessages(['site_id' => __('That site is not part of the chosen project.')]);
        }
    }

    private function nextCode(int $organizationId): string
    {
        $number = Asset::withoutGlobalScopes()->where('organization_id', $organizationId)->count() + 1;

        do {
            $code = sprintf('AST-%04d', $number++);
        } while (Asset::withoutGlobalScopes()->where('organization_id', $organizationId)->where('code', $code)->exists());

        return $code;
    }

    private function newTag(): string
    {
        do {
            $tag = Str::lower(Str::random(12));
        } while (Asset::withoutGlobalScopes()->where('tag', $tag)->exists());

        return $tag;
    }
}
