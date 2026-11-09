<?php

use App\Services\Agents\ModelGateway;
use App\Services\Agents\OutputSchemas;

return [
    'name' => "Owner's Copilot",
    'description' => 'A daily brief for the owner: money to collect and pay, work ready to bill, delays, and the decisions that need you.',
    'permission' => 'agents.copilot',
    'model' => ModelGateway::DEFAULT_MODEL,
    'effort' => 'medium',
    'autonomy' => 'L0',
    'max_autonomy' => 'L0',
    'tools' => ['company_overview', 'projects_list', 'project_summary', 'boq_search', 'dprs_search', 'issues_search', 'measurements_search', 'ra_bills_search', 'work_orders_search'],
    'limits' => ['steps' => 10, 'seconds' => 180, 'credits' => '25'],
    'output_schema' => OutputSchemas::brief(),
];
