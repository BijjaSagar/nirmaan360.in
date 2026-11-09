<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\FinancialYear;
use App\Services\Crm\WinLossReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function winLoss(Request $request, WinLossReport $report): View
    {
        $year = (int) $request->query('fy', FinancialYear::current());
        $year = $year >= 2000 && $year <= 2100 ? $year : FinancialYear::current();

        return view('modules.crm.reports.win-loss', ['report' => $report->build($request->user()->organization_id, $year)]);
    }
}
