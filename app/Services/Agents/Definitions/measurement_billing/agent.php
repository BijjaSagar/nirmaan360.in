<?php

use App\Services\Agents\ModelGateway;
use App\Services\Agents\OutputSchemas;

return [
    'name' => 'Measurement and Billing',
    'description' => 'Drafts measurement entries and RA bills from approved work, and points out deviations before a person reviews them.',
    'permission' => 'ra_bill.generate',
    'model' => ModelGateway::HARD_MODEL,
    'effort' => 'high',
    'autonomy' => 'L1',
    'max_autonomy' => 'L2',
    'tools' => ['projects_list', 'project_summary', 'boq_search', 'measurements_search', 'ra_bills_search', 'work_orders_search', 'measurement_create_draft', 'ra_bill_create_draft', 'ra_bill_submit_for_approval'],
    'limits' => ['steps' => 10, 'seconds' => 240, 'credits' => '40'],
    'output_schema' => OutputSchemas::answer(),
];
