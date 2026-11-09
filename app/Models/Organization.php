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
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isOnTrial(): bool
    {
        return $this->plan_status === 'trial' && $this->trial_ends_at?->isFuture();
    }

    /** An expired trial falls back to the free plan. */
    public function effectivePlan(): string
    {
        if ($this->plan_status === 'trial' && ! $this->isOnTrial()) {
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

    public function canAddUser(): bool
    {
        $max = $this->planConfig()['max_users'];

        return $max === null || $this->users()->count() < $max;
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
