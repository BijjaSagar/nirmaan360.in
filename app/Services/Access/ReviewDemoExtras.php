<?php

namespace App\Services\Access;

use App\Exceptions\ReviewDemoRefused;
use App\Models\Approval;
use App\Models\BoqItem;
use App\Models\ChecklistTemplate;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ApprovalService;
use App\Services\Assets\AssetService;
use App\Services\Assets\PlantLogService;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\Procurement\MaterialCatalogService;
use App\Services\Qhs\ChecklistService;
use App\Services\RaBillService;
use App\Services\SampleDataService;
use App\Services\SiteProgress\FloorService;
use App\Services\SiteProgress\ProgressEntryService;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * What the sample data leaves out but the app's Business screens and the contractor portal read: an approved
 * subcontractor bill and a payment on the sample contract, checklists and an inspection, a machine and its
 * log, floors with progress, and a material delivery. All on the first sample project, all invented, each
 * entered through the service and the rules the website and the app use. A screen the plan does not include
 * is skipped.
 */
class ReviewDemoExtras
{
    private const FLOORS = ['Level 1', 'Level 2', 'Level 3', 'Level 4', 'Level 5'];

    public function __construct(
        private ApprovalService $approvals,
        private RaBillService $bills,
        private ContractorPaymentService $payments,
        private ChecklistService $checklists,
        private AssetService $assets,
        private PlantLogService $plantLogs,
        private FloorService $floors,
        private ProgressEntryService $progress,
        private MaterialCatalogService $catalog,
        private DeliveryChallanService $challans,
    ) {}

    /**
     * The steps for this company's plan, each named for a warning if it fails, with what to do by hand instead.
     * Run them signed in as the owner, each in its own transaction.
     *
     * @return list<array{what: string, instead: string, add: Closure(): string}>
     */
    public function steps(Organization $organization, User $owner): array
    {
        $steps = [
            ['features' => ['contractor_portal', 'site_control'], 'what' => 'adding the contractor\'s bill and payment', 'instead' => 'Raise and approve a subcontractor RA bill and a contractor payment on WO-'.SampleDataService::TOWER_CODE.'-001 on the website.', 'add' => fn () => $this->contractorBillAndPayment($organization, $owner)],
            ['features' => ['qhs'], 'what' => 'adding checklists and an inspection', 'instead' => 'Install the standard checklists under Quality on the website.', 'add' => fn () => $this->inspection($organization, $owner)],
            ['features' => ['assets'], 'what' => 'adding a machine and its log', 'instead' => 'Add a machine on Tower B under Assets on the website.', 'add' => fn () => $this->machine($organization, $owner)],
            ['features' => ['site_control'], 'what' => 'adding floors and progress', 'instead' => 'Add floors to Kalyani Tower B (Sample) under Progress on the website.', 'add' => fn () => $this->floorsAndProgress($organization, $owner)],
            ['features' => ['site_control'], 'what' => 'adding a material delivery', 'instead' => 'Record a delivery challan on Kalyani Tower B (Sample) under Material on the website.', 'add' => fn () => $this->materialDelivery($organization, $owner)],
        ];

        return collect($steps)
            ->filter(fn (array $step) => collect($step['features'])->every(fn (string $feature) => $organization->allowsFeature($feature)))
            ->map(fn (array $step) => ['what' => $step['what'], 'instead' => $step['instead'], 'add' => $step['add']])
            ->values()
            ->all();
    }

    /**
     * As the bills page does it: a draft from the approved, unbilled measurement on the sample contract, sent
     * for approval and approved by the owner. Then a payment against the contract, approved the same way. The
     * portal lists only approved bills and approved payments.
     */
    private function contractorBillAndPayment(Organization $organization, User $owner): string
    {
        $tower = $this->tower($organization);
        $workOrder = WorkOrder::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('project_id', $tower->id)
            ->where('status', 'ACTIVE')
            ->orderBy('id')
            ->first() ?? throw new ReviewDemoRefused('the sample contract is missing');
        $party = Party::withoutGlobalScopes()->where('organization_id', $organization->id)->findOrFail($workOrder->party_id);
        $qs = $this->member($organization, 'qs', $owner);

        $bill = $this->bills->generate($tower, 'subcontractor', now()->startOfMonth()->subMonth()->toDateString(), now()->toDateString(), $qs, $workOrder);
        $this->approve($this->approvals->submit($bill, $qs), $owner);

        $data = Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'paid_on' => now()->subDays(2)->toDateString(),
            'amount' => '60000.00',
            'mode' => 'bank',
            'notes' => "Part payment against {$bill->number}.",
        ], $this->payments->rules($workOrder))->validate();

        $payment = $this->payments->raise($workOrder, $data, null, $this->member($organization, 'accounts', $owner));
        $this->approve($payment->currentApproval()->first(), $owner);

        return "bill {$bill->number} ({$bill->refresh()->status}) and payment {$payment->number} ({$payment->refresh()->status}) for {$party->name} on {$workOrder->number}, so the contractor portal's Bills and Payments are not empty";
    }

    /** The standard checklists, and one finished inspection from the first of them. */
    private function inspection(Organization $organization, User $owner): string
    {
        $tower = $this->tower($organization);
        $installed = $this->checklists->installStandard($owner);
        $template = ChecklistTemplate::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first() ?? throw new ReviewDemoRefused('no checklist was installed');

        $data = Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'checklist_template_id' => $template->id,
            'site_id' => $this->site($tower)?->id,
            'location' => 'Level 4, panel S1',
            'inspected_on' => now()->subDay()->toDateString(),
            'results' => collect(array_values($template->items))->keys()->map(fn (int $index) => ['position' => $index + 1, 'result' => 'pass'])->all(),
            'complete' => true,
        ], $this->checklists->startRules($tower, $owner))->validate();

        $inspection = $this->checklists->start($tower, $data, $this->member($organization, 'engineer', $owner));

        return "{$installed} standard checklists, and a {$inspection->refresh()->status} \"{$inspection->title}\" inspection on {$tower->name}";
    }

    /** One owned machine on Tower B, with yesterday's hours. */
    private function machine(Organization $organization, User $owner): string
    {
        $tower = $this->tower($organization);

        $asset = $this->assets->create(Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'name' => 'Backhoe loader',
            'category' => 'backhoe',
            'ownership' => 'owned',
            'meter_unit' => 'hours',
            'project_id' => $tower->id,
            'site_id' => $this->site($tower)?->id,
        ], $this->assets->rules($owner))->validate(), $owner);

        $log = $this->plantLogs->record(Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'asset_id' => $asset->id,
            'project_id' => $tower->id,
            'log_date' => now()->subDay()->toDateString(),
            'hours' => '7.5',
            'operator' => 'Mahesh Thorat',
            'remarks' => 'Excavation for the lift pit sump.',
        ], $this->plantLogs->logRules($owner))->validate(), $this->member($organization, 'engineer', $owner));

        return "machine {$asset->code} {$asset->name} on {$tower->name}, with {$log->hours} hours logged for {$log->log_date->toDateString()}";
    }

    /** Five floors sharing the slab line, and yesterday's work on Level 4. */
    private function floorsAndProgress(Organization $organization, User $owner): string
    {
        $tower = $this->tower($organization);
        $item = $this->slabLine($tower);

        $floors = collect(self::FLOORS)->map(fn (string $name) => $this->floors->create($tower, Validator::make(['name' => $name], $this->floors->rules($tower))->validate()));
        $this->floors->splitEqually($tower, [$item->id], $floors->pluck('id')->all());

        $entry = $this->progress->record($tower, Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'boq_item_id' => $item->id,
            'project_floor_id' => $floors->firstWhere('name', 'Level 4')->id,
            'work_date' => now()->subDay()->toDateString(),
            'qty_done' => '50',
            'remark' => 'Slab panels S1 and S2 cast.',
        ], $this->progress->rules($tower))->validate(), $this->member($organization, 'engineer', $owner));

        return 'floors '.self::FLOORS[0].' to '.self::FLOORS[array_key_last(self::FLOORS)]." on {$tower->name} sharing BOQ {$item->code}, and {$entry->qty_done} {$item->unit} done on Level 4";
    }

    /** A cement delivery taken on site, as the app's Material screen records one. */
    private function materialDelivery(Organization $organization, User $owner): string
    {
        $tower = $this->tower($organization);
        $bag = $this->catalog->units($organization->id)->firstWhere('code', 'bag') ?? throw new ReviewDemoRefused('the bag unit is missing');

        $material = $this->catalog->createMaterial(Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'code' => 'CEM-OPC53',
            'name' => 'OPC 53 grade cement',
            'category' => 'Cement',
            'base_unit_id' => $bag->id,
            'gst_pct' => '28',
        ], $this->catalog->materialRules())->validate());

        $challan = $this->challans->create($tower, Validator::make([
            'client_uuid' => (string) Str::uuid(),
            'supplier_name' => 'Pune Cement Traders (Sample)',
            'material_id' => $material->id,
            'qty' => '200',
            'challan_qty' => '200',
            'challan_date' => now()->subDay()->toDateString(),
            'challan_no' => 'PCT-1042',
            'remarks' => 'Unloaded at the site store.',
        ], $this->challans->rules($tower))->validate(), $this->member($organization, 'engineer', $owner));

        return "delivery {$challan->number} of {$challan->qty} bag {$material->name} on {$tower->name}";
    }

    /** Approves every remaining level as the owner, who may act at any level. Nothing to do when it went straight through. */
    private function approve(?Approval $approval, User $owner): void
    {
        while ($approval !== null && $approval->status === 'IN_REVIEW') {
            $approval = $this->approvals->act($approval, $owner, 'approve');
        }
    }

    private function tower(Organization $organization): Project
    {
        return Project::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', SampleDataService::TOWER_CODE)
            ->whereNull('deleted_at')
            ->first() ?? throw new ReviewDemoRefused('the first sample project is missing');
    }

    private function site(Project $project): ?Site
    {
        return Site::withoutGlobalScopes()->where('project_id', $project->id)->whereNull('deleted_at')->orderBy('id')->first();
    }

    private function slabLine(Project $project): BoqItem
    {
        return BoqItem::withoutGlobalScopes()->where('project_id', $project->id)->where('code', '2.1')->whereNull('deleted_at')->first()
            ?? throw new ReviewDemoRefused('the slab line of the first sample project is missing');
    }

    /** The sample's own person in that role, who signs in to nothing. The owner when there is none. */
    private function member(Organization $organization, string $role, User $owner): User
    {
        return User::where('organization_id', $organization->id)->where('role', $role)->whereNull('anonymised_at')->orderBy('id')->first() ?? $owner;
    }
}
