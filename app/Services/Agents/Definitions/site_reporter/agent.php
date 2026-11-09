<?php

use App\Services\Agents\ModelGateway;
use App\Services\Agents\OutputSchemas;

return [
    'name' => 'Site Reporter',
    'description' => 'Turns a site message in Marathi, Hindi or English into a draft daily report, and asks when something is unclear.',
    'permission' => 'dpr.create',
    'model' => ModelGateway::DEFAULT_MODEL,
    'effort' => 'medium',
    'autonomy' => 'L1',
    'max_autonomy' => 'L2',
    'tools' => ['projects_list', 'project_summary', 'boq_search', 'dprs_search', 'dpr_create_draft', 'dpr_submit_for_approval'],
    'limits' => ['steps' => 8, 'seconds' => 150, 'credits' => '15'],
    'output_schema' => OutputSchemas::answer(),
];
