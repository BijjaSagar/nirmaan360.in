<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\Attendance;
use App\Models\BoqItem;
use App\Models\Dpr;
use App\Models\Issue;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Models\RaBill;
use App\Services\ApprovalService;
use App\Services\BillingExtras\ContractPositionService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ApprovalService $approvals): View
    {
        $user = $request->user();
        $today = now()->toDateString();
        $seesMoney = Money::visible($user);

        $pending = Approval::with('approvable')
            ->where('status', 'IN_REVIEW')
            ->latest('id')
            ->get()
            ->filter(fn ($approval) => $approvals->canAct($approval, $user));

        $projects = Project::whereIn('status', ['planning', 'active'])->latest('id')->take(8)->get();

        // These roll-ups are for display only. Bills are calculated with exact decimals.
        // Everyone gets the percentage; the rupee figures behind it only go to people who may see money.
        $boqValues = BoqItem::selectRaw('project_id, SUM(qty * rate) as total')
            ->whereIn('project_id', $projects->pluck('id'))
            ->groupBy('project_id')
            ->pluck('total', 'project_id');

        $approvedValues = MeasurementEntry::query()
            ->join('boq_items', 'boq_items.id', '=', 'measurement_entries.boq_item_id')
            ->selectRaw('measurement_entries.project_id, SUM(measurement_entries.qty * boq_items.rate) as total')
            ->where('measurement_entries.status', 'APPROVED')
            ->whereIn('measurement_entries.project_id', $projects->pluck('id'))
            ->groupBy('measurement_entries.project_id')
            ->pluck('total', 'measurement_entries.project_id');

        return view('dashboard.index', [
            'seesMoney' => $seesMoney,
            'pending' => $pending->take(6),
            'pendingCount' => $pending->count(),
            'projects' => $projects,
            'boqValues' => $boqValues,
            'approvedValues' => $approvedValues,
            'activeProjects' => Project::where('status', 'active')->count(),
            'contractValue' => $seesMoney ? Project::whereIn('status', ['planning', 'active'])->sum('contract_value') : null,
            'billed' => $seesMoney
                ? RaBill::where('type', 'client')->whereIn('status', ['APPROVED', 'PAID'])->sum('gross_amount') + (float) app(ContractPositionService::class)->clientNoteAdjustment()
                : null,
            'inReview' => MeasurementEntry::where('status', 'IN_REVIEW')->count(),
            'openIssues' => Issue::whereIn('status', ['open', 'in_progress'])->count(),
            'presentToday' => Attendance::whereDate('attendance_date', $today)->whereIn('status', ['present', 'half', 'overtime'])->count(),
            'dprsToday' => Dpr::whereDate('report_date', $today)->count(),
            'returned' => MeasurementEntry::with('boqItem', 'project')->where('created_by', $user->id)->where('status', 'RETURNED')->latest('id')->take(5)->get(),
            'recentDprs' => Dpr::with('project', 'creator')->latest('id')->take(5)->get(),
        ]);
    }
}
