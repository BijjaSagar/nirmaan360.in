<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\Dpr;
use App\Models\Issue;
use App\Models\Organization;
use App\Models\Party;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Believable records for one company. The local demo uses it, and `sample:fill`
 * lets an owner see the product working on their own account.
 */
class SampleDataService
{
    public const TOWER_CODE = 'SMP-KT';

    public const BLOCK_CODE = 'SMP-HJ';

    private const SUFFIX = ' (Sample)';

    public function __construct(
        private ApprovalService $approvals,
        private MeasurementService $measurements,
        private PettyCashService $pettyCash,
        private RaBillService $bills,
    ) {}

    /**
     * Adds a worked project and a freshly planned one to an existing company.
     * All or nothing: a failure part-way leaves the company as it was.
     *
     * @return list<Project>
     */
    public function fillCompany(Organization $organization, User $owner): array
    {
        return DB::transaction(function () use ($organization, $owner) {
            if (! $organization->canAddProject()) {
                throw new RuntimeException('This company has reached its plan limit for projects.');
            }

            $organization->seedDefaults();
            $people = ['owner' => $owner, ...$this->sampleTeam($organization)];

            // Reports start yesterday so today's stays free for the owner to try, and the owner holds the wallet.
            $projects = [$this->addTowerProject($organization, $people, self::TOWER_CODE, self::SUFFIX, 1, $owner)];

            if ($organization->canAddProject()) {
                $projects[] = $this->addPlannedProject($organization, $owner);
            }

            return $projects;
        });
    }

    /**
     * A project with BOQ, work order, site records, petty cash, approvals and one RA bill.
     *
     * @param  array{owner:User,pm:User,qs:User,engineer:User,accounts:User}  $people
     */
    public function addTowerProject(
        Organization $organization,
        array $people,
        string $code,
        string $suffix = '',
        int $reportDaysAgo = 0,
        ?User $fieldUser = null,
    ): Project {
        $fieldUser ??= $people['engineer'];

        $client = Party::create(['organization_id' => $organization->id, 'type' => 'client', 'name' => 'Kalyani Developers LLP'.$suffix, 'contact_person' => 'V. Shinde']);
        $subcontractor = Party::create(['organization_id' => $organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork Works'.$suffix, 'contact_person' => 'Ramesh Jadhav']);

        $project = Project::create([
            'organization_id' => $organization->id,
            'code' => $code,
            'name' => 'Kalyani Tower B'.$suffix,
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
            'created_by' => $people['owner']->id,
        ]);

        $site = Site::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Tower B', 'address' => 'Survey 42, Baner']);

        $items = $this->addBoq($project, [
            'Substructure' => [
                ['1.1', 'Earthwork in excavation in all kinds of soil', 'cum', '1850.000', '185.00'],
                ['1.2', 'PCC M15 below foundations', 'cum', '140.000', '5650.00'],
            ],
            'Superstructure' => [
                ['2.1', 'RCC M25 in slabs and beams including shuttering', 'cum', '620.000', '7450.00'],
                ['2.2', 'TMT steel Fe500D reinforcement', 'MT', '96.000', '72500.00'],
                ['2.3', 'AAC block masonry 200 mm in CM 1:4', 'cum', '410.000', '6200.00'],
            ],
        ]);

        $workOrder = WorkOrder::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'party_id' => $subcontractor->id,
            'number' => 'WO-'.$code.'-001',
            'title' => 'Slab casting and shuttering',
            'status' => 'ACTIVE',
            'retention_pct' => '5',
            'tds_pct' => '1',
            'gst_pct' => '18',
            'created_by' => $people['pm']->id,
        ]);
        $workOrder->items()->create(['organization_id' => $organization->id, 'boq_item_id' => $items['2.1']->id, 'qty' => '620.000', 'rate' => '2150.00']);

        $this->addSiteRecords($organization, $project, $site, $items, $people, $reportDaysAgo, $fieldUser);
        $this->addPettyCash($organization, $project, $people, $fieldUser);
        $this->addMeasurementsAndBill($project, $items, $people, $workOrder);

        return $project;
    }

    /** A project at the start of its life: terms and BOQ, nothing executed yet. */
    private function addPlannedProject(Organization $organization, User $owner): Project
    {
        $client = Party::create(['organization_id' => $organization->id, 'type' => 'client', 'name' => 'Greenfield Realty'.self::SUFFIX, 'contact_person' => 'A. Kulkarni']);

        $project = Project::create([
            'organization_id' => $organization->id,
            'code' => self::BLOCK_CODE,
            'name' => 'Hinjewadi Commercial Block'.self::SUFFIX,
            'client_id' => $client->id,
            'location' => 'Hinjewadi Phase 2, Pune',
            'state' => 'Maharashtra',
            'contract_value' => '18500000.00',
            'start_date' => now()->subWeeks(2),
            'end_date' => now()->addMonths(10),
            'status' => 'active',
            'retention_pct' => '5',
            'tds_pct' => '2',
            'gst_pct' => '18',
            'advance_amount' => '0',
            'advance_recovery_pct' => '0',
            'created_by' => $owner->id,
        ]);

        Site::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Block A', 'address' => 'Plot 18, Phase 2']);

        $this->addBoq($project, [
            'Foundation' => [
                ['1.1', 'Excavation for isolated footings', 'cum', '640.000', '210.00'],
                ['1.2', 'RCC M25 in footings', 'cum', '185.000', '6900.00'],
            ],
            'Frame' => [
                ['2.1', 'RCC M30 in columns', 'cum', '120.000', '8200.00'],
            ],
        ]);

        return $project;
    }

    /**
     * Sign-in is switched off, so these people exist only as names on the records.
     *
     * @return array{pm:User,qs:User,engineer:User,accounts:User}
     */
    private function sampleTeam(Organization $organization): array
    {
        $team = [];

        foreach (['pm' => 'Rahul Patil', 'qs' => 'Meera Joshi', 'engineer' => 'Sachin More', 'accounts' => 'Neha Deshpande'] as $role => $name) {
            if (! $organization->canAddUser()) {
                throw new RuntimeException('This company has no room on its plan for the sample team.');
            }

            $team[$role] = User::create([
                'organization_id' => $organization->id,
                'name' => $name,
                'email' => "sample-{$role}-{$organization->id}@example.invalid",
                'role' => $role,
                'is_active' => false,
                'password' => Str::random(64),
            ]);
        }

        return $team;
    }

    /**
     * @param  array<string, list<array{string,string,string,string,string}>>  $sections
     * @return array<string, BoqItem>
     */
    private function addBoq(Project $project, array $sections): array
    {
        $items = [];
        $sort = 0;

        foreach ($sections as $sectionName => $rows) {
            $section = BoqSection::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => $sectionName]);

            foreach ($rows as [$code, $description, $unit, $qty, $rate]) {
                $items[$code] = BoqItem::create([
                    'organization_id' => $project->organization_id,
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

        return $items;
    }

    private function addSiteRecords(Organization $organization, Project $project, Site $site, array $items, array $people, int $reportDaysAgo, User $fieldUser): void
    {
        foreach ([
            ['Dattu Pawar', 'Mason', '950'], ['Ganesh Kale', 'Bar bender', '900'], ['Sunil Gaikwad', 'Carpenter', '950'],
            ['Anita Bhosale', 'Helper', '650'], ['Prakash Shinde', 'Mason', '950'], ['Vinod Jagtap', 'Electrician', '1100'],
            ['Imran Shaikh', 'Plumber', '1050'], ['Lata Mane', 'Helper', '650'], ['Mahesh Thorat', 'Operator', '1200'],
        ] as $index => [$name, $trade, $wage]) {
            $worker = Worker::create(['organization_id' => $organization->id, 'name' => $name, 'trade' => $trade, 'daily_wage' => $wage]);

            // Only the first few are marked, so today's register opens part-done.
            if ($index < 4) {
                Attendance::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'worker_id' => $worker->id, 'attendance_date' => now()->toDateString(), 'status' => 'present', 'marked_by' => $people['engineer']->id]);
            }
        }

        $this->addDpr($project, $site, $items, $people, $reportDaysAgo, 'Level 4 slab casting completed for panels S1 and S2.');

        // Waiting at the PM.
        $this->approvals->submit($this->addDpr($project, $site, $items, $people, $reportDaysAgo + 1, 'Level 4 beam reinforcement tied and checked.'), $people['engineer']);

        // Sent back to the engineer.
        $approval = $this->approvals->submit($this->addDpr($project, $site, $items, $people, $reportDaysAgo + 2, 'Level 4 shuttering erected for panels S1 to S3.'), $people['engineer']);
        $this->approvals->act($approval, $people['pm'], 'return', 'Add the crane hours and the hindrance from the late steel delivery.');

        foreach ([
            ['Honeycombing at column C7, Level 3', 'high', 'open', 'Grid C7', 3],
            ['Seepage at lift pit retaining wall', 'medium', 'in_progress', 'Lift pit, basement', 7],
            ['Shuttering plates short for Level 5', 'critical', 'open', 'Store yard', 1],
        ] as [$title, $priority, $status, $location, $dueInDays]) {
            Issue::create([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'title' => $title,
                'priority' => $priority,
                'status' => $status,
                'location_note' => $location,
                'assigned_to' => $fieldUser->id,
                'raised_by' => $people['pm']->id,
                'due_date' => now()->addDays($dueInDays),
            ]);
        }
    }

    private function addDpr(Project $project, Site $site, array $items, array $people, int $daysAgo, string $summary): Dpr
    {
        $dpr = Dpr::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'site_id' => $site->id,
            'report_date' => now()->subDays($daysAgo)->toDateString(),
            'weather' => 'Clear',
            'summary' => $summary,
            'status' => 'DRAFT',
            'created_by' => $people['engineer']->id,
        ]);
        $dpr->manpower()->createMany([
            ['organization_id' => $project->organization_id, 'trade' => 'Mason', 'count' => 12],
            ['organization_id' => $project->organization_id, 'trade' => 'Helper', 'count' => 30],
        ]);
        $dpr->workItems()->create(['organization_id' => $project->organization_id, 'boq_item_id' => $items['2.1']->id, 'qty' => '68.000']);

        return $dpr;
    }

    private function addPettyCash(Organization $organization, Project $project, array $people, User $fieldUser): void
    {
        $wallet = PettyCashWallet::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $fieldUser->id,
            'name' => 'Site imprest, Tower B',
        ]);

        foreach ([
            ['topup', '25000', null, 'Imprest for the month', 5, $people['accounts']],
            ['expense', '450', 'Fuel', 'Diesel for the site generator', 3, $fieldUser],
            ['expense', '1200', 'Food', 'Tea and lunch for the slab pour crew', 1, $fieldUser],
        ] as [$type, $amount, $category, $description, $daysAgo, $by]) {
            $this->pettyCash->addEntry($wallet, [
                'type' => $type,
                'amount' => $amount,
                'category' => $category,
                'description' => $description,
                'entry_date' => now()->subDays($daysAgo)->toDateString(),
            ], $by);
        }
    }

    private function addMeasurementsAndBill(Project $project, array $items, array $people, WorkOrder $workOrder): void
    {
        $entry = $this->measurements->create($project, [
            'boq_item_id' => $items['2.1']->id,
            'work_order_id' => $workOrder->id,
            'measured_on' => now()->subDays(6)->toDateString(),
            'location' => 'Level 4, panel S1',
            'nos' => '2',
            'length' => '10',
            'breadth' => '5',
            'depth' => '0.5',
        ], $people['engineer']);

        $approval = $this->measurements->submit($entry, $people['engineer']);

        while ($approval->status === 'IN_REVIEW') {
            $approval = $this->approvals->act($approval, $people['owner'], 'approve');
        }

        // One entry left waiting at the QS, so the inbox has something in it.
        $pending = $this->measurements->create($project, [
            'boq_item_id' => $items['2.1']->id,
            'measured_on' => now()->subDays(1)->toDateString(),
            'location' => 'Level 4, panel S2',
            'nos' => '1',
            'length' => '6',
            'breadth' => '5',
            'depth' => '0.5',
        ], $people['engineer']);
        $this->measurements->submit($pending, $people['engineer']);

        // One sent back to the engineer with a remark.
        $returned = $this->measurements->create($project, [
            'boq_item_id' => $items['2.3']->id,
            'measured_on' => now()->subDays(2)->toDateString(),
            'location' => 'Level 3, grid B',
            'nos' => '4',
            'length' => '3.2',
            'breadth' => '0.2',
            'depth' => '3',
        ], $people['engineer']);
        $this->approvals->act($this->measurements->submit($returned, $people['engineer']), $people['qs'], 'return', 'Deduct the door openings and resubmit.');

        $this->bills->generate($project, 'client', now()->startOfMonth()->subMonth()->toDateString(), now()->toDateString(), $people['qs']);
    }
}
