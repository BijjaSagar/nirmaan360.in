<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'paid_until' => 'datetime',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class);
    }

    /**
     * False for a plan that is no longer offered, such as the removed offline plan. A company can still hold one
     * if a checkout for it was paid after the plan went, so every screen treats it as the free plan instead.
     */
    public function hasOfferedPlan(): bool
    {
        return array_key_exists((string) $this->plan, config('buildos.plans', []));
    }

    /** A trial of a plan that is no longer offered is no trial. */
    public function isOnTrial(): bool
    {
        return $this->plan_status === 'trial' && $this->trial_ends_at?->isFuture() && $this->hasOfferedPlan();
    }

    /** A paid period that ended more than the grace period ago. */
    public function hasLapsed(): bool
    {
        return $this->paid_until !== null
            && $this->paid_until->copy()->addDays(config('buildos.payment_grace_days'))->isPast();
    }

    /** An expired trial, a lapsed paid plan or a plan that is no longer offered falls back to the free plan. */
    public function effectivePlan(): string
    {
        if (! $this->hasOfferedPlan() || ($this->plan_status === 'trial' && ! $this->isOnTrial()) || $this->hasLapsed()) {
            return 'free';
        }

        return $this->plan;
    }

    public function planConfig(): array
    {
        return config('buildos.plans.'.$this->effectivePlan(), config('buildos.plans.free'));
    }

    public function allowsFeature(string $feature): bool
    {
        return in_array($feature, $this->planConfig()['features'], true);
    }

    public function canAddProject(): bool
    {
        $max = $this->planConfig()['max_projects'];

        return $max === null || Project::withoutGlobalScopes()->where('organization_id', $this->id)->whereNull('deleted_at')->count() < $max;
    }

    /** Contractor logins reach only the contractor portal, so they never take a seat on the plan. Nor does a deleted account. */
    public function canAddUser(): bool
    {
        $max = $this->planConfig()['max_users'];

        return $max === null || $this->users()->whereNot('role', User::CONTRACTOR_ROLE)->whereNull('anonymised_at')->count() < $max;
    }

    /** Copy the default approval chains so they can be edited per organisation. */
    public function seedDefaults(): void
    {
        foreach (config('buildos.approval_defaults') as $docType => $levels) {
            ApprovalRule::withoutGlobalScopes()->firstOrCreate(
                ['organization_id' => $this->id, 'doc_type' => $docType],
                ['levels' => $levels, 'active' => true],
            );
        }
    }
}
