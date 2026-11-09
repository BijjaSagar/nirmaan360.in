<?php

use App\Services\Agents\ModelGateway;
use App\Services\Agents\OutputSchemas;

return [
    'name' => 'Project Brain',
    'description' => 'Answers questions about your projects from your own records, with links to each record it used.',
    'permission' => 'project.view',
    'model' => ModelGateway::DEFAULT_MODEL,
    'effort' => 'medium',
    'autonomy' => 'L0',
    'max_autonomy' => 'L0',
    'tools' => ['projects_list', 'project_summary', 'boq_search', 'dprs_search', 'issues_search', 'measurements_search', 'ra_bills_search', 'work_orders_search', 'attendance_summary', 'petty_cash_summary'],
    'limits' => ['steps' => 8, 'seconds' => 150, 'credits' => '15'],
    'output_schema' => OutputSchemas::answer(),
];
