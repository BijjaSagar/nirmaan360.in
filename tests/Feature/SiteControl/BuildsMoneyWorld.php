<?php

namespace Tests\Feature\SiteControl;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\AgentRun;
use App\Models\Asset;
use App\Models\AssetBreakdown;
use App\Models\AssetHireBill;
use App\Models\AssetMaintenance;
use App\Models\AssetServiceRecord;
use App\Models\Attendance;
use App\Models\BbsSchedule;
use App\Models\BillNote;
use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\ChecklistTemplate;
use App\Models\CrmLead;
use App\Models\CrmTender;
use App\Models\CustomRole;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\DprWorkItem;
use App\Models\Drawing;
use App\Models\DrawingSheet;
use App\Models\DrawingTransmittal;
use App\Models\DrawingTransmittalItem;
use App\Models\DrawingVersion;
use App\Models\ExpenseClaim;
use App\Models\FieldAttachment;
use App\Models\FuelIssue;
use App\Models\Grn;
use App\Models\GrnItem;
use App\Models\GstInvoice;
use App\Models\Indent;
use App\Models\IndentItem;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\Issue;
use App\Models\IssuePhoto;
use App\Models\MachineLog;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialIssueItem;
use App\Models\MaterialReturn;
use App\Models\MaterialReturnItem;
use App\Models\MaterialStore;
use App\Models\MaterialUnit;
use App\Models\MeasurementAttachment;
use App\Models\MeasurementEntry;
use App\Models\Ncr;
use App\Models\Organization;
use App\Models\Party;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\PlanActivity;
use App\Models\PlanBoqLink;
use App\Models\PlanBudgetLine;
use App\Models\PlanCostEntry;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\QualityTest;
use App\Models\RaBill;
use App\Models\RaBillDeduction;
use App\Models\RaBillItem;
use App\Models\Rfi;
use App\Models\Rfq;
use App\Models\RfqItem;
use App\Models\RfqQuotation;
use App\Models\RfqQuotationItem;
use App\Models\SafetyIncident;
use App\Models\Site;
use App\Models\StaffLeave;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\TakeoffLayer;
use App\Models\ToolboxTalk;
use App\Models\User;
use App\Models\VendorBill;
use App\Models\WageRun;
use App\Models\WageRunLine;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Services\Agents\AgentRegistry;
use App\Services\ApprovalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\BuildsTenant;

/**
 * One company holding every kind of record a site role can reach, with an unmistakable rupee figure
 * in every money column. Each figure has eight digits that appear nowhere else in the fixtures, so a
 * page, file or payload that carries one of them has leaked money.
 */
trait BuildsMoneyWorld
{
    use BuildsTenant;

    /** Money column => figure. The two *_own figures belong to the engineer and may reach them. */
    public const MONEY = [
        'contract_value' => '83614729.00',
        'advance_amount' => '83614730.00',
        'boq_rate' => '83614731.00',
        'wo_rate' => '83614732.00',
        'wo_advance' => '83614733.00',
        'bill_rate' => '83614734.00',
        'bill_amount' => '83614735.00',
        'bill_gross' => '83614736.00',
        'bill_deduction' => '83614737.00',
        'bill_gst' => '83614738.00',
        'bill_net' => '83614739.00',
        'note_taxable' => '83614740.00',
        'note_total' => '83614741.00',
        'wage' => '83614742.00',
        'other_wallet' => '83614743.00',
        'own_wallet' => '83614744.00',
        'hire_rate' => '83614745.00',
        'purchase_cost' => '83614746.00',
        'fuel_rate' => '83614747.00',
        'fuel_amount' => '83614748.00',
        'repair_cost' => '83614749.00',
        'service_cost' => '83614750.00',
        'po_rate' => '83614751.00',
        'po_amount' => '83614752.00',
        'po_subtotal' => '83614753.00',
        'po_gst' => '83614754.00',
        'po_total' => '83614755.00',
        'quote_rate' => '83614756.00',
        'quote_amount' => '83614757.00',
        'quote_total' => '83614758.00',
        'budget' => '83614759.00',
        'cost_entry' => '83614760.00',
        'rework' => '83614761.00',
        'other_claim' => '83614762.00',
        'own_claim' => '83614763.00',
        'hire_bill_net' => '83614764.00',
        'vendor_bill' => '83614765.00',
        'invoice_value' => '83614766.00',
        'wage_net' => '83614767.00',
        'tender_value' => '83614768.00',
        'lead_value' => '83614769.00',
    ];

    protected Organization $organization;

    /** @var array<string, User> role => member */
    protected array $users = [];

    protected Project $project;

    /** @var array<class-string, Model> The row a route parameter of that model resolves to. */
    protected array $rows = [];

    /** @var array<string, string> Route parameters that are plain values rather than models. */
    protected array $params = [];

    protected function buildMoneyWorld(): void
    {
        $money = self::MONEY;
        $this->organization = $this->tenant('Sentinel Infra', 'business');
        $org = $this->organization->id;

        // Photos, receipts and drawings are real (empty) files, so the routes that serve them answer rather than fail.
        Storage::fake();

        foreach (['none/measurement.jpg', 'none/photo.jpg', 'none/issue.jpg', 'none/bill.jpg', 'none/ncr.jpg', 'none/A-101.pdf'] as $path) {
            Storage::put($path, 'stored file');
        }

        foreach (['owner', 'pm', 'qs', 'accounts', 'engineer', 'store', 'viewer'] as $role) {
            $this->users[$role] = $this->member($this->organization, $role);
        }

        $owner = $this->users['owner'];
        $engineer = $this->users['engineer'];
        $this->actingAs($owner);

        $client = Party::create(['organization_id' => $org, 'type' => 'client', 'name' => 'Kalyani Developers']);
        $subcontractor = Party::create(['organization_id' => $org, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $vendor = Party::create(['organization_id' => $org, 'type' => 'vendor', 'name' => 'Birla Traders']);

        $project = $this->project = $this->makeProject($this->organization, [
            'client_id' => $client->id,
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'contract_value' => $money['contract_value'],
            'advance_amount' => $money['advance_amount'],
            'retention_pct' => '5',
            'tds_pct' => '2',
            'gst_pct' => '18',
            'advance_recovery_pct' => '10',
        ]);
        $site = Site::create(['organization_id' => $org, 'project_id' => $project->id, 'name' => 'Tower A']);

        // BOQ, measurements, work order, bill and note
        $section = BoqSection::create(['organization_id' => $org, 'project_id' => $project->id, 'name' => 'Superstructure']);
        $item = $this->makeBoqItem($project, '100.000', $money['boq_rate']);
        $item->update(['boq_section_id' => $section->id]);

        $this->approveEntry($this->draftEntry($project, $item, $engineer, ['nos' => '1', 'length' => '10', 'breadth' => '1', 'depth' => null]), $engineer, $owner);
        $draft = $this->draftEntry($project, $item, $engineer, ['nos' => '2', 'length' => '5', 'breadth' => null, 'depth' => null]);
        MeasurementAttachment::create(['organization_id' => $org, 'measurement_entry_id' => $draft->id, 'kind' => 'photo', 'path' => 'none/measurement.jpg']);

        $workOrder = WorkOrder::create([
            'organization_id' => $org, 'project_id' => $project->id, 'party_id' => $subcontractor->id,
            'number' => 'WO-001', 'title' => 'Slab formwork', 'status' => 'ACTIVE', 'rate_type' => 'item_rate',
            'retention_pct' => '10', 'tds_pct' => '1', 'gst_pct' => '0',
            'advance_amount' => $money['wo_advance'], 'advance_recovery_pct' => '10', 'deduction_basis' => 'gross',
        ]);
        $workOrder->items()->create(['organization_id' => $org, 'boq_item_id' => $item->id, 'qty' => '10.000', 'rate' => $money['wo_rate']]);

        $bill = RaBill::create([
            'organization_id' => $org, 'project_id' => $project->id, 'party_id' => $client->id, 'type' => 'client',
            'bill_no' => 1, 'number' => $project->code.'/RA/1', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'status' => 'DRAFT',
            'gross_amount' => $money['bill_gross'], 'deduction_total' => $money['bill_deduction'],
            'gst_amount' => $money['bill_gst'], 'net_payable' => $money['bill_net'],
            'terms' => ['gst_pct' => '18', 'rate_type' => 'item_rate', 'retention_pct' => '5'],
        ]);
        RaBillItem::create([
            'organization_id' => $org, 'ra_bill_id' => $bill->id, 'boq_item_id' => $item->id,
            'code' => $item->code, 'description' => $item->description, 'unit' => $item->unit, 'rate' => $money['bill_rate'],
            'prev_qty' => '0.000', 'current_qty' => '10.000', 'cumulative_qty' => '10.000',
            'prev_amount' => '0.00', 'current_amount' => $money['bill_amount'], 'cumulative_amount' => $money['bill_amount'],
        ]);
        RaBillDeduction::create(['organization_id' => $org, 'ra_bill_id' => $bill->id, 'type' => 'retention', 'label' => 'Retention', 'pct' => '5.00', 'amount' => $money['bill_deduction']]);
        app(ApprovalService::class)->submit($bill, $owner);

        $note = BillNote::create([
            'organization_id' => $org, 'project_id' => $project->id, 'ra_bill_id' => $bill->id, 'party_id' => $client->id,
            'kind' => 'credit', 'number' => $project->code.'/CN/1', 'note_date' => '2026-11-20', 'reason' => 'Rate correction on item 1',
            'taxable_amount' => $money['note_taxable'], 'gst_pct' => '18.00', 'gst_amount' => '0.00',
            'total_amount' => $money['note_total'], 'net_effect' => '-'.$money['note_total'], 'status' => 'DRAFT', 'created_by' => $owner->id,
        ]);

        // Site records
        $dpr = Dpr::create(['organization_id' => $org, 'project_id' => $project->id, 'site_id' => $site->id, 'report_date' => '2026-11-09', 'weather' => 'Clear', 'summary' => 'Slab shuttering', 'status' => 'DRAFT', 'created_by' => $engineer->id]);
        DprWorkItem::create(['organization_id' => $org, 'dpr_id' => $dpr->id, 'boq_item_id' => $item->id, 'description' => 'Shuttering', 'qty' => '5.000']);
        $photo = DprPhoto::create(['organization_id' => $org, 'dpr_id' => $dpr->id, 'path' => 'none/photo.jpg']);

        $issue = Issue::create(['organization_id' => $org, 'project_id' => $project->id, 'title' => 'Honeycombing at C7', 'priority' => 'high', 'status' => 'open', 'raised_by' => $engineer->id]);
        $issuePhoto = IssuePhoto::create(['organization_id' => $org, 'issue_id' => $issue->id, 'kind' => 'photo', 'path' => 'none/issue.jpg']);

        $worker = Worker::create(['organization_id' => $org, 'name' => 'Ramesh Pawar', 'trade' => 'Mason', 'type' => 'own', 'daily_wage' => $money['wage'], 'is_active' => true]);
        Attendance::create(['organization_id' => $org, 'project_id' => $project->id, 'worker_id' => $worker->id, 'attendance_date' => now()->toDateString(), 'status' => 'present', 'ot_hours' => 0, 'method' => 'manual', 'marked_by' => $engineer->id]);

        // Petty cash: the engineer holds one wallet, the owner another
        $ownWallet = PettyCashWallet::create(['organization_id' => $org, 'project_id' => $project->id, 'name' => 'Engineer imprest', 'user_id' => $engineer->id]);
        PettyCashEntry::create(['organization_id' => $org, 'petty_cash_wallet_id' => $ownWallet->id, 'type' => 'topup', 'amount' => $money['own_wallet'], 'entry_date' => '2026-11-01', 'created_by' => $owner->id]);
        $otherWallet = PettyCashWallet::create(['organization_id' => $org, 'project_id' => $project->id, 'name' => 'Owner cash', 'user_id' => $owner->id]);
        $otherEntry = PettyCashEntry::create(['organization_id' => $org, 'petty_cash_wallet_id' => $otherWallet->id, 'type' => 'topup', 'amount' => $money['other_wallet'], 'entry_date' => '2026-11-02', 'created_by' => $owner->id, 'bill_path' => 'none/bill.jpg']);

        // Plant and equipment
        $category = (string) array_key_first(config('modules.assets.categories'));
        $asset = Asset::create([
            'organization_id' => $org, 'code' => 'AS-001', 'tag' => Str::random(20), 'name' => 'JCB 3DX', 'category' => $category,
            'ownership' => 'hired', 'vendor_id' => $vendor->id, 'hire_basis' => 'hour', 'hire_rate' => $money['hire_rate'], 'hire_type' => 'dry',
            'meter_unit' => 'hours', 'project_id' => $project->id, 'site_id' => $site->id, 'status' => 'active',
        ]);
        Asset::create([
            'organization_id' => $org, 'code' => 'AS-002', 'tag' => Str::random(20), 'name' => 'Concrete mixer', 'category' => $category,
            'ownership' => 'owned', 'purchase_cost' => $money['purchase_cost'], 'purchase_date' => '2025-06-01',
            'meter_unit' => 'hours', 'project_id' => $project->id, 'site_id' => $site->id, 'status' => 'active',
        ]);
        FuelIssue::create(['organization_id' => $org, 'asset_id' => $asset->id, 'project_id' => $project->id, 'issue_date' => '2026-11-05', 'litres' => '10.000', 'rate' => $money['fuel_rate'], 'amount' => $money['fuel_amount'], 'issued_by' => $owner->id]);
        AssetBreakdown::create(['organization_id' => $org, 'asset_id' => $asset->id, 'project_id' => $project->id, 'reported_at' => now()->subDays(3), 'description' => 'Hydraulic hose burst', 'status' => 'resolved', 'resolved_at' => now()->subDays(2), 'repair_cost' => $money['repair_cost'], 'resolution' => 'Hose replaced', 'reported_by' => $engineer->id]);
        AssetServiceRecord::create(['organization_id' => $org, 'asset_id' => $asset->id, 'done_on' => '2026-10-01', 'description' => 'Engine oil change', 'cost' => $money['service_cost'], 'done_by' => 'Site mechanic', 'created_by' => $owner->id]);
        MachineLog::create(['organization_id' => $org, 'asset_id' => $asset->id, 'project_id' => $project->id, 'log_date' => '2026-11-08', 'hours' => '8.000', 'operator' => 'Suresh']);
        AssetMaintenance::create(['organization_id' => $org, 'asset_id' => $asset->id, 'title' => 'Engine oil and filters', 'interval_days' => 90, 'last_done_on' => '2026-10-01', 'is_active' => true]);
        $hireBill = AssetHireBill::create([
            'organization_id' => $org, 'asset_id' => $asset->id, 'vendor_id' => $vendor->id, 'seq' => 1, 'number' => 'HB-001',
            'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'basis' => 'hour', 'rate' => $money['hire_rate'],
            'actual_quantity' => '10.000', 'quantity' => '10.000', 'gross_amount' => $money['hire_bill_net'], 'net_payable' => $money['hire_bill_net'],
            'status' => 'DRAFT', 'created_by' => $owner->id,
        ]);

        // Procurement chain
        $kg = MaterialUnit::create(['organization_id' => $org, 'code' => 'kg']);
        $cement = Material::create(['organization_id' => $org, 'code' => 'CEM-53', 'name' => 'OPC 53 cement', 'base_unit_id' => $kg->id]);
        $store = MaterialStore::create(['organization_id' => $org, 'project_id' => $project->id, 'name' => 'Main store']);
        $yard = MaterialStore::create(['organization_id' => $org, 'project_id' => $project->id, 'name' => 'Yard store']);

        $indent = Indent::create(['organization_id' => $org, 'project_id' => $project->id, 'store_id' => $store->id, 'number' => 'IND-0001', 'status' => 'APPROVED', 'required_by' => '2026-11-20', 'created_by' => $engineer->id]);
        $indentItem = IndentItem::create(['organization_id' => $org, 'indent_id' => $indent->id, 'material_id' => $cement->id, 'qty' => '100.000', 'unit_id' => $kg->id, 'base_qty' => '100.000']);
        $rfq = Rfq::create(['organization_id' => $org, 'project_id' => $project->id, 'indent_id' => $indent->id, 'number' => 'RFQ-0001', 'status' => 'OPEN', 'created_by' => $owner->id]);
        $rfqItem = RfqItem::create(['organization_id' => $org, 'rfq_id' => $rfq->id, 'indent_item_id' => $indentItem->id, 'material_id' => $cement->id, 'qty' => '100.000', 'unit_id' => $kg->id, 'base_qty' => '100.000']);
        $quotation = RfqQuotation::create(['organization_id' => $org, 'rfq_id' => $rfq->id, 'party_id' => $vendor->id, 'status' => 'RECEIVED', 'quote_date' => '2026-11-01', 'valid_until' => '2027-01-31', 'total' => $money['quote_total']]);
        RfqQuotationItem::create(['organization_id' => $org, 'rfq_quotation_id' => $quotation->id, 'rfq_item_id' => $rfqItem->id, 'rate' => $money['quote_rate'], 'gst_pct' => '18.00', 'amount' => $money['quote_amount'], 'gst_amount' => '0.00']);

        $order = PurchaseOrder::create([
            'organization_id' => $org, 'project_id' => $project->id, 'rfq_id' => $rfq->id, 'party_id' => $vendor->id, 'store_id' => $store->id,
            'number' => 'PO-0001', 'po_date' => '2026-11-02', 'status' => 'ISSUED',
            'subtotal' => $money['po_subtotal'], 'gst_amount' => $money['po_gst'], 'total' => $money['po_total'], 'created_by' => $owner->id,
        ]);
        $orderItem = $order->items()->create([
            'organization_id' => $org, 'rfq_item_id' => $rfqItem->id, 'material_id' => $cement->id, 'qty' => '100.000', 'unit_id' => $kg->id, 'base_qty' => '100.000',
            'rate' => $money['po_rate'], 'gst_pct' => '18.00', 'amount' => $money['po_amount'], 'gst_amount' => '0.00',
        ]);
        $grn = Grn::create(['organization_id' => $org, 'project_id' => $project->id, 'purchase_order_id' => $order->id, 'store_id' => $store->id, 'number' => 'GRN-0001', 'grn_date' => '2026-11-06', 'status' => 'POSTED', 'created_by' => $this->users['store']->id]);
        GrnItem::create(['organization_id' => $org, 'grn_id' => $grn->id, 'purchase_order_item_id' => $orderItem->id, 'material_id' => $cement->id, 'qty' => '50.000', 'unit_id' => $kg->id, 'base_qty' => '50.000']);
        $materialIssue = MaterialIssue::create(['organization_id' => $org, 'project_id' => $project->id, 'store_id' => $store->id, 'number' => 'MI-0001', 'issue_date' => '2026-11-07', 'status' => 'POSTED', 'created_by' => $this->users['store']->id]);
        MaterialIssueItem::create(['organization_id' => $org, 'material_issue_id' => $materialIssue->id, 'material_id' => $cement->id, 'qty' => '10.000', 'unit_id' => $kg->id, 'base_qty' => '10.000', 'activity' => 'Slab']);
        $materialReturn = MaterialReturn::create(['organization_id' => $org, 'project_id' => $project->id, 'store_id' => $store->id, 'return_type' => 'to_store', 'material_issue_id' => $materialIssue->id, 'number' => 'MR-0001', 'return_date' => '2026-11-08', 'status' => 'POSTED']);
        MaterialReturnItem::create(['organization_id' => $org, 'material_return_id' => $materialReturn->id, 'material_id' => $cement->id, 'qty' => '2.000', 'unit_id' => $kg->id, 'base_qty' => '2.000']);
        $transfer = StockTransfer::create(['organization_id' => $org, 'from_project_id' => $project->id, 'to_project_id' => $project->id, 'from_store_id' => $store->id, 'to_store_id' => $yard->id, 'number' => 'ST-0001', 'dispatch_date' => '2026-11-08', 'status' => 'DISPATCHED']);
        StockTransferItem::create(['organization_id' => $org, 'stock_transfer_id' => $transfer->id, 'material_id' => $cement->id, 'qty' => '5.000', 'unit_id' => $kg->id, 'base_qty' => '5.000']);

        // Planning and budget
        $activity = PlanActivity::create(['organization_id' => $org, 'project_id' => $project->id, 'code' => 'A1', 'name' => 'Excavation', 'planned_start' => '2026-01-05', 'planned_end' => '2026-02-05']);
        PlanBoqLink::create(['organization_id' => $org, 'project_id' => $project->id, 'plan_activity_id' => $activity->id, 'boq_item_id' => $item->id, 'qty' => '100.000']);
        PlanBudgetLine::create(['organization_id' => $org, 'project_id' => $project->id, 'head' => 'material', 'amount' => $money['budget']]);
        PlanCostEntry::create(['organization_id' => $org, 'project_id' => $project->id, 'head' => 'material', 'entry_date' => '2026-11-03', 'amount' => $money['cost_entry'], 'created_by' => $owner->id]);

        // Quality and safety
        $ncr = Ncr::create(['organization_id' => $org, 'project_id' => $project->id, 'site_id' => $site->id, 'seq' => 1, 'number' => 'NCR-001', 'title' => 'Honeycomb in column C7', 'category' => 'workmanship', 'severity' => 'major', 'raised_on' => '2026-11-04', 'raised_by' => $engineer->id, 'rework_cost' => $money['rework'], 'status' => 'open']);
        $attachment = FieldAttachment::create(['organization_id' => $org, 'attachable_type' => $ncr->getMorphClass(), 'attachable_id' => $ncr->id, 'path' => 'none/ncr.jpg', 'original_name' => 'ncr.jpg']);
        $test = QualityTest::create(['organization_id' => $org, 'project_id' => $project->id, 'test_type' => 'cube', 'element' => 'Slab S1', 'grade' => 'M25', 'cast_date' => '2026-11-01', 'created_by' => $engineer->id]);
        $incident = SafetyIncident::create(['organization_id' => $org, 'project_id' => $project->id, 'seq' => 1, 'number' => 'INC-001', 'kind' => 'near_miss', 'severity' => 'low', 'occurred_at' => now()->subDay(), 'description' => 'Loose scaffold plank', 'status' => 'open', 'reported_by' => $engineer->id]);
        ToolboxTalk::create(['organization_id' => $org, 'project_id' => $project->id, 'talk_date' => '2026-11-09', 'topic' => 'Working at height', 'conducted_by' => 'Safety officer']);
        $template = ChecklistTemplate::create(['organization_id' => $org, 'name' => 'Pre-pour card', 'category' => 'general', 'items' => ['Shuttering checked', 'Reinforcement cover ok']]);
        $inspection = Inspection::create(['organization_id' => $org, 'project_id' => $project->id, 'checklist_template_id' => $template->id, 'title' => 'Pre-pour slab S1', 'category' => 'general', 'inspected_on' => '2026-11-09', 'status' => 'in_progress', 'inspector_id' => $engineer->id]);
        InspectionItem::create(['organization_id' => $org, 'inspection_id' => $inspection->id, 'sort' => 1, 'text' => 'Shuttering checked', 'result' => 'pending']);
        $rfi = Rfi::create(['organization_id' => $org, 'project_id' => $project->id, 'seq' => 1, 'number' => 'RFI-001', 'subject' => 'Beam depth at grid 5', 'question' => 'Drawing shows 450 but the schedule says 500.', 'status' => 'DRAFT', 'raised_by' => $engineer->id]);

        // Drawings
        $drawing = Drawing::create(['organization_id' => $org, 'project_id' => $project->id, 'kind' => 'drawing', 'number' => 'A-101', 'title' => 'Ground floor plan']);
        $version = DrawingVersion::create(['organization_id' => $org, 'project_id' => $project->id, 'drawing_id' => $drawing->id, 'version_no' => 1, 'revision' => 'R0', 'status' => 'for_construction', 'path' => 'none/A-101.pdf', 'original_name' => 'A-101.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1024, 'is_current' => true]);
        $sheet = DrawingSheet::create(['organization_id' => $org, 'project_id' => $project->id, 'drawing_version_id' => $version->id, 'page_no' => 1, 'width' => '1000', 'height' => '800']);
        $transmittal = DrawingTransmittal::create(['organization_id' => $org, 'project_id' => $project->id, 'party_id' => $client->id, 'number' => 'TR-001', 'recipient_name' => 'V. Shinde', 'subject' => 'GF plan for construction', 'purpose' => 'for_construction', 'status' => 'issued', 'issued_at' => now(), 'issued_by' => $owner->id]);
        DrawingTransmittalItem::create(['organization_id' => $org, 'drawing_transmittal_id' => $transmittal->id, 'drawing_version_id' => $version->id]);
        $schedule = BbsSchedule::create(['organization_id' => $org, 'project_id' => $project->id, 'drawing_id' => $drawing->id, 'number' => 'BBS-001', 'title' => 'Footing F1', 'created_by' => $owner->id]);
        $layer = TakeoffLayer::create(['organization_id' => $org, 'project_id' => $project->id, 'name' => 'Brickwork', 'colour' => '#b4451f']);

        // Finance, payroll and sales records: out of reach for site roles, but the walk still has to visit their routes
        $vendorBill = VendorBill::create(['organization_id' => $org, 'project_id' => $project->id, 'party_id' => $vendor->id, 'bill_no' => 'VB-001', 'bill_date' => '2026-11-03', 'cost_head' => 'material', 'taxable_amount' => $money['vendor_bill'], 'total_amount' => $money['vendor_bill'], 'net_payable' => $money['vendor_bill']]);
        $invoice = GstInvoice::create([
            'organization_id' => $org, 'project_id' => $project->id, 'ra_bill_id' => $bill->id, 'financial_year' => '2026-27', 'serial' => 1, 'invoice_no' => 'INV/26-27/1',
            'invoice_date' => '2026-11-30', 'supplier_name' => 'Sentinel Infra', 'supplier_gstin' => '27AAAAA0000A1Z5', 'buyer_name' => $client->name,
            'place_of_supply_code' => '27', 'place_of_supply' => 'Maharashtra', 'supply_type' => 'intra', 'sac' => '9954', 'description' => 'Works contract services',
            'taxable_amount' => $money['invoice_value'], 'gst_pct' => '18.00', 'invoice_value' => $money['invoice_value'],
        ]);
        $wageRun = WageRun::create(['organization_id' => $org, 'number' => 'WR-001', 'project_id' => $project->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'DRAFT', 'total_days' => '1.000', 'total_gross' => $money['wage_net'], 'total_net' => $money['wage_net'], 'prepared_by' => $owner->id]);
        $wageLine = WageRunLine::create(['organization_id' => $org, 'wage_run_id' => $wageRun->id, 'worker_id' => $worker->id, 'worker_name' => $worker->name, 'trade' => $worker->trade, 'daily_wage' => $money['wage'], 'present_days' => '1.000', 'payable_days' => '1.000', 'day_pay' => $money['wage_net'], 'gross' => $money['wage_net'], 'net' => $money['wage_net']]);
        $tender = CrmTender::create(['organization_id' => $org, 'title' => 'Road widening, NH-48', 'authority' => 'PWD Pune', 'stage' => 'enquiry', 'estimated_value' => $money['tender_value']]);
        $lead = CrmLead::create(['organization_id' => $org, 'name' => 'Ramesh Kulkarni', 'company_name' => 'Kulkarni Developers', 'source' => 'referral', 'status' => 'new', 'expected_value' => $money['lead_value']]);

        // Staff claims: the engineer's own and a manager's
        $ownClaim = ExpenseClaim::create(['organization_id' => $org, 'user_id' => $engineer->id, 'project_id' => $project->id, 'claim_date' => '2026-11-05', 'category' => 'Travel', 'amount' => $money['own_claim'], 'description' => 'Site visit', 'status' => 'PENDING']);
        ExpenseClaim::create(['organization_id' => $org, 'user_id' => $this->users['pm']->id, 'project_id' => $project->id, 'claim_date' => '2026-11-06', 'category' => 'Food', 'amount' => $money['other_claim'], 'status' => 'PENDING']);
        $leave = StaffLeave::create(['organization_id' => $org, 'user_id' => $engineer->id, 'leave_type' => 'casual', 'from_date' => '2026-11-12', 'to_date' => '2026-11-12', 'days' => '1.000', 'status' => 'PENDING']);

        // An AI agent chat the engineer started
        app(AgentRegistry::class)->sync();
        $agent = Agent::where('key', 'project_brain')->firstOrFail();
        $conversation = AgentConversation::create(['organization_id' => $org, 'agent_id' => $agent->id, 'user_id' => $engineer->id, 'project_id' => $project->id, 'title' => 'Slab progress']);
        $run = AgentRun::create([
            'organization_id' => $org, 'agent_id' => $agent->id, 'agent_version_id' => $agent->current_version_id, 'user_id' => $engineer->id,
            'project_id' => $project->id, 'agent_conversation_id' => $conversation->id, 'status' => AgentRun::COMPLETED, 'autonomy' => 'L0',
            'model' => 'claude-sonnet-5-5', 'input' => 'How much slab is done?', 'messages' => [], 'answer' => 'Half the slab is cast.',
            'output' => ['answer' => 'Half the slab is cast.', 'sources' => []], 'sources' => [],
            'max_steps' => 5, 'max_seconds' => 60, 'max_credits' => '1.0000', 'started_at' => now(), 'finished_at' => now(),
        ]);

        $customRole = CustomRole::create(['organization_id' => $org, 'name' => 'Site lead', 'permissions' => ['project.view', 'boq.view'], 'created_by' => $owner->id]);

        $this->rows = [
            Project::class => $project, Site::class => $site, BoqItem::class => $item, MeasurementEntry::class => $draft,
            MeasurementAttachment::class => MeasurementAttachment::first(), WorkOrder::class => $workOrder, RaBill::class => $bill, BillNote::class => $note,
            Dpr::class => $dpr, DprPhoto::class => $photo, Issue::class => $issue, IssuePhoto::class => $issuePhoto,
            Worker::class => $worker, PettyCashWallet::class => $otherWallet, PettyCashEntry::class => $otherEntry,
            Asset::class => $asset, MaterialUnit::class => $kg, Material::class => $cement, MaterialStore::class => $store,
            Indent::class => $indent, Rfq::class => $rfq, PurchaseOrder::class => $order, Grn::class => $grn,
            MaterialIssue::class => $materialIssue, MaterialReturn::class => $materialReturn, StockTransfer::class => $transfer,
            PlanActivity::class => $activity, Ncr::class => $ncr, FieldAttachment::class => $attachment, QualityTest::class => $test,
            SafetyIncident::class => $incident, ChecklistTemplate::class => $template, Inspection::class => $inspection, Rfi::class => $rfi,
            Drawing::class => $drawing, DrawingVersion::class => $version, DrawingSheet::class => $sheet, DrawingTransmittal::class => $transmittal,
            BbsSchedule::class => $schedule, TakeoffLayer::class => $layer, ExpenseClaim::class => $ownClaim, StaffLeave::class => $leave,
            Agent::class => $agent, AgentConversation::class => $conversation, AgentRun::class => $run,
            AssetHireBill::class => $hireBill, VendorBill::class => $vendorBill, GstInvoice::class => $invoice,
            WageRun::class => $wageRun, WageRunLine::class => $wageLine, CrmTender::class => $tender, CrmLead::class => $lead,
            CustomRole::class => $customRole, User::class => $engineer,
        ];

        // Parameters that are plain values: the asset tag, an agent key, and the ids the public API and signed links take.
        $this->params = ['tag' => $asset->tag, 'agent' => 'project_brain', 'id' => $dpr->id, 'projectId' => $project->id, 'dprId' => $dpr->id, 'billId' => $bill->id];
    }

    /** The eight digits that identify a figure, however it is grouped or rounded on a page. */
    protected function needle(string $key): string
    {
        return substr(self::MONEY[$key], 0, 8);
    }

    /** Text with digit grouping and decimal points removed, so 8,36,14,729.00 and 83614729.00 read the same. */
    protected function digits(string $text): string
    {
        return (string) preg_replace('/[,.]/', '', $text);
    }
}
