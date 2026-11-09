<?php

namespace Database\Seeders;

use App\Models\Attendance;
use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\Dpr;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Services\ApprovalService;
use App\Services\MeasurementService;
use App\Services\RaBillService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/** Sample company for local development. Every demo user's password is "password". */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data can only be added in a local environment.');
        }

        $organization = Organization::create([
            'name' => 'Sahyadri Infra Pvt Ltd',
            'slug' => 'sahyadri-infra-demo',
            'plan' => 'business',
            'plan_status' => 'active',
            'state' => 'Maharashtra',
            'address' => 'Kothrud, Pune 411038',
        ]);
        $organization->seedDefaults();

        $users = [];
        foreach (['owner' => 'Asha Kulkarni', 'pm' => 'Rahul Patil', 'qs' => 'Meera Joshi', 'engineer' => 'Sachin More', 'accounts' => 'Neha Deshpande'] as $role => $name) {
            $users[$role] = User::create([
                'organization_id' => $organization->id,
                'name' => $name,
                'email' => $role.'@demo.test',
                'role' => $role,
                'password' => 'password',
            ]);
        }

        // Tenant scoping and audit logs both read the signed-in user.
        Auth::setUser($users['owner']);

        $client = Party::create(['organization_id' => $organization->id, 'type' => 'client', 'name' => 'Kalyani Developers LLP', 'contact_person' => 'V. Shinde']);
        $subcontractor = Party::create(['organization_id' => $organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork Works', 'contact_person' => 'Ramesh Jadhav']);

        $project = Project::create([
            'organization_id' => $organization->id,
            'code' => 'KT-B',
            'name' => 'Kalyani Tower B',
            'client_id' => $client->id,
            'location' => 'Baner, Pune',
            'state' => 'Maharashtra',
            'contract_value' => '48500000.00',
            'start_date' => now()->subMonths(4),
            'end_date' => now()->addMonths(14),
            'status' => 'active',
            'retention_pct' => '5',
            'tds_pct' => '2',
            'gst_pct' => '18',
            'advance_amount' => '2000000',
            'advance_recovery_pct' => '10',
            'created_by' => $users['owner']->id,
        ]);

        $site = Site::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Tower B', 'address' => 'Survey 42, Baner']);

        $items = [];
        $sort = 0;
        foreach ([
            'Substructure' => [
                ['1.1', 'Earthwork in excavation in all kinds of soil', 'cum', '1850.000', '185.00'],
                ['1.2', 'PCC M15 below foundations', 'cum', '140.000', '5650.00'],
            ],
            'Superstructure' => [
                ['2.1', 'RCC M25 in slabs and beams including shuttering', 'cum', '620.000', '7450.00'],
                ['2.2', 'TMT steel Fe500D reinforcement', 'MT', '96.000', '72500.00'],
                ['2.3', 'AAC block masonry 200 mm in CM 1:4', 'cum', '410.000', '6200.00'],
            ],
        ] as $sectionName => $rows) {
            $section = BoqSection::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => $sectionName]);

            foreach ($rows as [$code, $description, $unit, $qty, $rate]) {
                $items[$code] = BoqItem::create([
                    'organization_id' => $organization->id,
                    'project_id' => $project->id,
                    'boq_section_id' => $section->id,
                    'code' => $code,
                    'description' => $description,
                    'unit' => $unit,
                    'qty' => $qty,
                    'rate' => $rate,
                    'sort' => ++$sort,
                ]);
            }
        }

        $workOrder = WorkOrder::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'party_id' => $subcontractor->id,
            'number' => 'WO-KT-B-001',
            'title' => 'Slab casting and shuttering',
            'status' => 'ACTIVE',
            'retention_pct' => '5',
            'tds_pct' => '1',
            'gst_pct' => '18',
            'created_by' => $users['pm']->id,
        ]);
        $workOrder->items()->create(['organization_id' => $organization->id, 'boq_item_id' => $items['2.1']->id, 'qty' => '620.000', 'rate' => '2150.00']);

        $this->seedSiteRecords($organization, $project, $site, $items, $users);
        $this->seedMeasurementsAndBill($project, $items, $users, $workOrder);
    }

    private function seedSiteRecords(Organization $organization, Project $project, Site $site, array $items, array $users): void
    {
        foreach ([['Dattu Pawar', 'Mason', '950'], ['Ganesh Kale', 'Bar bender', '900'], ['Sunil Gaikwad', 'Carpenter', '950'], ['Anita Bhosale', 'Helper', '650']] as [$name, $trade, $wage]) {
            $worker = Worker::create(['organization_id' => $organization->id, 'name' => $name, 'trade' => $trade, 'daily_wage' => $wage]);
            Attendance::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'worker_id' => $worker->id, 'attendance_date' => now()->toDateString(), 'status' => 'present', 'marked_by' => $users['engineer']->id]);
        }

        $dpr = Dpr::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'site_id' => $site->id,
            'report_date' => now()->toDateString(),
            'weather' => 'Clear',
            'summary' => 'Level 4 slab casting completed for panels S1 and S2.',
            'status' => 'DRAFT',
            'created_by' => $users['engineer']->id,
        ]);
        $dpr->manpower()->createMany([
            ['organization_id' => $organization->id, 'trade' => 'Mason', 'count' => 12],
            ['organization_id' => $organization->id, 'trade' => 'Helper', 'count' => 30],
        ]);
        $dpr->workItems()->create(['organization_id' => $organization->id, 'boq_item_id' => $items['2.1']->id, 'qty' => '68.000']);

        Issue::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'title' => 'Honeycombing at column C7, Level 3',
            'priority' => 'high',
            'status' => 'open',
            'location_note' => 'Grid C7',
            'assigned_to' => $users['engineer']->id,
            'raised_by' => $users['pm']->id,
            'due_date' => now()->addDays(3),
        ]);
    }

    private function seedMeasurementsAndBill(Project $project, array $items, array $users, WorkOrder $workOrder): void
    {
        $measurements = app(MeasurementService::class);
        $approvals = app(ApprovalService::class);

        $entry = $measurements->create($project, [
            'boq_item_id' => $items['2.1']->id,
            'work_order_id' => $workOrder->id,
            'measured_on' => now()->subDays(6)->toDateString(),
            'location' => 'Level 4, panel S1',
            'nos' => '2',
            'length' => '10',
            'breadth' => '5',
            'depth' => '0.5',
        ], $users['engineer']);

        $approval = $measurements->submit($entry, $users['engineer']);

        while ($approval->status === 'IN_REVIEW') {
            $approval = $approvals->act($approval, $users['owner'], 'approve');
        }

        // One entry left waiting at the QS, so the inbox has something in it.
        $pending = $measurements->create($project, [
            'boq_item_id' => $items['2.1']->id,
            'measured_on' => now()->subDays(1)->toDateString(),
            'location' => 'Level 4, panel S2',
            'nos' => '1',
            'length' => '6',
            'breadth' => '5',
            'depth' => '0.5',
        ], $users['engineer']);
        $measurements->submit($pending, $users['engineer']);

        app(RaBillService::class)->generate($project, 'client', now()->startOfMonth()->subMonth()->toDateString(), now()->toDateString(), $users['qs']);
    }
}
