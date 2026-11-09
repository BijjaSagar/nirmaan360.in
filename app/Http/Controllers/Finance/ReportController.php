<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Finance\CashFlowForecastService;
use App\Services\Finance\Gst;
use App\Services\Finance\Gstr1Service;
use App\Services\Finance\TdsReportService;
use App\Services\Finance\Workbook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function gstr1(Request $request, Gstr1Service $service): View
    {
        return view('modules.finance.reports.gstr1', ['report' => $service->report($request->user()->organization_id, $this->month($request))]);
    }

    public function gstr1Excel(Request $request, Gstr1Service $service): StreamedResponse
    {
        $month = $this->month($request);

        return Workbook::download($service->spreadsheet($service->report($request->user()->organization_id, $month)), "GSTR1_{$month}_for_CA_check.xlsx");
    }

    public function gstr1Json(Request $request, Gstr1Service $service): Response
    {
        $month = $this->month($request);

        return response($service->json($service->report($request->user()->organization_id, $month)), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"GSTR1_{$month}_for_CA_check.json\"",
        ]);
    }

    public function tds(Request $request, TdsReportService $service): View
    {
        [$from, $to] = $this->range($request);

        return view('modules.finance.reports.tds', ['report' => $service->report($request->user()->organization_id, $from, $to)]);
    }

    public function tdsExcel(Request $request, TdsReportService $service): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        return Workbook::download($service->spreadsheet($service->report($request->user()->organization_id, $from, $to)), "TDS_{$from}_to_{$to}_for_CA_check.xlsx");
    }

    public function cashFlow(Request $request, CashFlowForecastService $service): View
    {
        $filters = $request->validate([
            'weeks' => ['nullable', 'integer', 'between:4,26'],
            'credit_days' => ['nullable', 'integer', 'between:0,180'],
            'project_id' => ['bail', 'nullable', 'integer', org_exists('projects')],
        ]);

        return view('modules.finance.reports.cash-flow', [
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'projectId' => $filters['project_id'] ?? null,
            'forecast' => $service->forecast(
                $request->user()->organization_id,
                now(),
                (int) ($filters['weeks'] ?? 12),
                isset($filters['credit_days']) ? (int) $filters['credit_days'] : null,
                isset($filters['project_id']) ? (int) $filters['project_id'] : null,
            ),
        ]);
    }

    private function month(Request $request): string
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return $data['month'] ?? now()->format('Y-m');
    }

    /** @return array{0:string,1:string} Defaults to the current financial quarter. */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        [$from, $to] = Gst::quarterRange(now());

        return [$data['from'] ?? $from, $data['to'] ?? $to];
    }
}
