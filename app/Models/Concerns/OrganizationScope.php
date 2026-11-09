<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Limits every query to the signed-in user's organisation.
 * Without a signed-in user (console) the scope does not apply, so those callers
 * must filter by organisation themselves. Client sign-off links are authorised by
 * their signature, so they work even if the viewer is signed in to another company.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check() && ! request()->routeIs('client.*')) {
            $builder->where($model->qualifyColumn('organization_id'), Auth::user()->organization_id);
        }
    }
}
