<?php

namespace App\Models\Concerns;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Limits a person who is restricted to chosen projects, on every tenant model.
 * Any table with a project_id column is covered without further work, so a new
 * module is safe by default. A child table with no project_id of its own declares
 * the relation that leads to one, with PROJECT_VIA or PROJECT_VIA_MORPH.
 * A null project_id is a company-level row and stays visible.
 */
class ProjectAccessScope implements Scope
{
    /** @var array<string, bool> */
    private static array $hasProjectColumn = [];

    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // Client sign-off links are authorised by their signature, as in OrganizationScope.
        if (! $user instanceof User || ! $user->isProjectLimited() || request()->routeIs('client.*')) {
            return;
        }

        if ($model instanceof Project) {
            $builder->whereIn($model->qualifyColumn('id'), $user->assignedProjectIds());
        } elseif (defined($model::class.'::PROJECT_VIA')) {
            $builder->whereHas($model::PROJECT_VIA);
        } elseif (defined($model::class.'::PROJECT_VIA_MORPH')) {
            $builder->whereHasMorph($model::PROJECT_VIA_MORPH, '*');
        } elseif ($this->hasProjectColumn($model)) {
            $column = $model->qualifyColumn('project_id');
            $builder->where(fn ($query) => $query->whereIn($column, $user->assignedProjectIds())->orWhereNull($column));
        }
    }

    private function hasProjectColumn(Model $model): bool
    {
        $key = $model->getConnectionName().'.'.$model->getTable();

        return self::$hasProjectColumn[$key] ??= $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'project_id');
    }
}
