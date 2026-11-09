<?php

namespace App\Services\Agents\Tools;

use Illuminate\Database\Eloquent\Builder;

/**
 * Wraps one existing service or query for agents to call. Agents never reach the database
 * any other way, and the runner checks the agent's tool list, autonomy and the user's permission first.
 */
abstract class Tool
{
    /** Looks things up and changes nothing. */
    public const READ = 'read';

    /** Saves a DRAFT for a person to review. */
    public const DRAFT = 'draft';

    /** Happens only after a person approves it. */
    public const APPROVAL = 'approval';

    /** Letters, digits, underscore and dash only: the Messages API rejects anything else. */
    abstract public function name(): string;

    abstract public function description(): string;

    /** @return array<string,mixed> JSON Schema for the input. */
    abstract public function schema(): array;

    /** The module.action permission the acting user must hold. */
    abstract public function permission(): string;

    abstract public function run(array $input, ToolContext $context): ToolResult;

    public function effect(): string
    {
        return self::READ;
    }

    /** The plan feature this tool's module belongs to. */
    public function feature(): ?string
    {
        return null;
    }

    /** One line for the approval inbox, for tools that need a person. */
    public function actionSummary(array $input, ToolContext $context): string
    {
        return $this->name();
    }

    /** The project the approval belongs to, for tools that need a person. */
    public function actionProjectId(array $input, ToolContext $context): ?int
    {
        return $context->run->project_id;
    }

    /** @return array<string,mixed> The tool as the Messages API expects it. */
    final public function definition(): array
    {
        $schema = $this->schema();

        // An empty PHP array would be sent as a JSON list, and the API requires an object.
        if ($schema['properties'] === []) {
            $schema['properties'] = new \stdClass;
        }

        return ['name' => $this->name(), 'description' => $this->description(), 'input_schema' => $schema];
    }

    /**
     * @param  array<string,mixed>  $properties
     * @param  list<string>  $required
     */
    protected function objectSchema(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required];
    }

    protected function projectId(): array
    {
        return ['type' => 'integer', 'description' => 'The project id from projects_list.'];
    }

    protected function limit(): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many rows to return. Default 20.'];
    }

    protected function date(string $description): array
    {
        return ['type' => 'string', 'format' => 'date', 'description' => $description];
    }

    /** Case-insensitive search that treats % and _ in the term as ordinary characters. */
    protected function whereContains(Builder $query, array $columns, string $term): Builder
    {
        $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

        return $query->where(function (Builder $query) use ($columns, $needle) {
            foreach ($columns as $column) {
                $query->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", [$needle]);
            }
        });
    }
}
