<?php

namespace App\Services\FieldOps;

use App\Models\Project;
use App\Support\Idempotent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/** Helpers every field_ops service shares: idempotent creates, numbering and tenant-safe rules. */
trait CreatesOnce
{
    /**
     * Create a row, or return the one an earlier request with the same client_uuid already made.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  callable(): TModel  $create
     * @return TModel
     */
    protected function createOnce(string $model, int $organizationId, ?string $uuid, callable $create): Model
    {
        $find = fn () => filled($uuid)
            ? $model::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first()
            : null;

        if ($existing = $find()) {
            return $existing;
        }

        return Idempotent::create($create, $find);
    }

    /**
     * The next number in a sequence kept per project or per organisation. Call it inside the
     * create transaction after locking the parent row, so two requests cannot take the same number.
     *
     * @param  class-string<Model>  $model
     */
    protected function nextSequence(string $model, string $column, int $id): int
    {
        return (int) $model::withoutGlobalScopes()->where($column, $id)->max('seq') + 1;
    }

    /** Take a row lock so numbering is serial on PostgreSQL. Other databases ignore the lock. */
    protected function lockProject(Project $project): void
    {
        Project::withoutGlobalScopes()->whereKey($project->id)->lockForUpdate()->first();
    }

    protected function inOrganization(string $table, int $organizationId): Exists
    {
        return Rule::exists($table, 'id')->where('organization_id', $organizationId);
    }

    /** A project of this company that has not been deleted. */
    protected function projectRule(int $organizationId): Exists
    {
        return Rule::exists('projects', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at');
    }

    /** A site of the given project. */
    protected function siteOfProjectRule(int $projectId): Exists
    {
        return Rule::exists('sites', 'id')->where('project_id', $projectId)->whereNull('deleted_at');
    }
}
