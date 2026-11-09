<?php

namespace App\Services\Agents\Tools;

use App\Models\Project;

class ProjectsList extends Tool
{
    public function name(): string
    {
        return 'projects_list';
    }

    public function description(): string
    {
        return 'Lists the company\'s projects with their sites. Use it to find a project id or site id.';
    }

    public function schema(): array
    {
        return $this->objectSchema([
            'search' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Part of the project name or code.'],
            'limit' => $this->limit(),
        ]);
    }

    public function permission(): string
    {
        return 'project.view';
    }

    public function run(array $input, ToolContext $context): ToolResult
    {
        $limit = (int) ($input['limit'] ?? 20);

        $query = Project::with('sites')
            ->where('organization_id', $context->organizationId())
            ->when($context->run->project_id, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name');

        if (filled($input['search'] ?? null)) {
            $this->whereContains($query, ['name', 'code'], $input['search']);
        }

        $projects = $query->limit($limit + 1)->get();
        $shown = $projects->take($limit);

        return new ToolResult(
            [
                'projects' => $shown->map(fn (Project $project) => [
                    'id' => $project->id,
                    'code' => $project->code,
                    'name' => $project->name,
                    'status' => $project->status,
                    'location' => $project->location,
                    'sites' => $project->sites->map(fn ($site) => ['id' => $site->id, 'name' => $site->name])->all(),
                ])->all(),
                'more_exist' => $projects->count() > $limit,
            ],
            $shown->map(fn (Project $project) => ToolResult::source('project', $project->id, $project->name, route('projects.show', $project, false)))->all(),
        );
    }
}
