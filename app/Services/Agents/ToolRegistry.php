<?php

namespace App\Services\Agents;

use App\Models\Organization;
use App\Models\User;
use App\Services\Agents\Tools\AttendanceSummary;
use App\Services\Agents\Tools\BoqSearch;
use App\Services\Agents\Tools\CompanyOverview;
use App\Services\Agents\Tools\DprCreateDraft;
use App\Services\Agents\Tools\DprsSearch;
use App\Services\Agents\Tools\DprSubmitForApproval;
use App\Services\Agents\Tools\IssuesSearch;
use App\Services\Agents\Tools\MeasurementCreateDraft;
use App\Services\Agents\Tools\MeasurementsSearch;
use App\Services\Agents\Tools\PettyCashSummary;
use App\Services\Agents\Tools\ProjectsList;
use App\Services\Agents\Tools\ProjectSummary;
use App\Services\Agents\Tools\RaBillCreateDraft;
use App\Services\Agents\Tools\RaBillsSearch;
use App\Services\Agents\Tools\RaBillSubmitForApproval;
use App\Services\Agents\Tools\Tool;
use App\Services\Agents\Tools\WorkOrdersSearch;

class ToolRegistry
{
    private const TOOLS = [
        ProjectsList::class,
        ProjectSummary::class,
        BoqSearch::class,
        DprsSearch::class,
        IssuesSearch::class,
        MeasurementsSearch::class,
        RaBillsSearch::class,
        WorkOrdersSearch::class,
        AttendanceSummary::class,
        PettyCashSummary::class,
        CompanyOverview::class,
        DprCreateDraft::class,
        MeasurementCreateDraft::class,
        RaBillCreateDraft::class,
        DprSubmitForApproval::class,
        RaBillSubmitForApproval::class,
    ];

    /** @var array<string,Tool>|null */
    private ?array $tools = null;

    /** @return array<string,Tool> */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];

            foreach (self::TOOLS as $class) {
                $tool = app($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    public function find(string $name): ?Tool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * What the model is offered: tools on the agent's list that this autonomy level, user and plan allow.
     * The runner checks all of this again, so a call the model makes up is still refused.
     *
     * @param  list<string>  $names
     * @return list<array<string,mixed>>
     */
    public function definitions(array $names, string $autonomy, User $user, Organization $organization): array
    {
        $definitions = [];

        foreach ($names as $name) {
            $tool = $this->find($name);

            if ($tool && $this->blockedBy($tool, $autonomy, $user, $organization) === null) {
                $definitions[] = $tool->definition();
            }
        }

        return $definitions;
    }

    /** @return array{0:string,1:string}|null A refusal code and message, or null when the call is allowed. */
    public function blockedBy(Tool $tool, string $autonomy, User $user, Organization $organization): ?array
    {
        if (! Autonomy::allows($autonomy, $tool->effect())) {
            return ['autonomy', "This agent runs at {$autonomy}, which does not allow {$tool->name()}."];
        }

        if (! $user->hasPermission($tool->permission())) {
            return ['permission', "The person using this agent does not have the {$tool->permission()} permission."];
        }

        if ($tool->feature() !== null && ! $organization->allowsFeature($tool->feature())) {
            return ['plan', "This company's plan does not include {$tool->feature()}."];
        }

        return null;
    }
}
