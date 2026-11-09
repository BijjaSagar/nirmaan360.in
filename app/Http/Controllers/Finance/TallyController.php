<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Finance\Gst;
use App\Services\Finance\TallyExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TallyController extends Controller
{
    public function __construct(private TallyExportService $service) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $vouchers = $this->service->vouchers($request->user()->organization_id, $filters['from'], $filters['to'], $filters['project_id'], $filters['bank_ledger']);

        return view('modules.finance.reports.tally', [
            'filters' => $filters,
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'counts' => collect($vouchers)->countBy('type')->sortKeys(),
            'ledgerCount' => count($this->service->ledgers($request->user()->organization_id, $vouchers, $filters['bank_ledger'])),
            'defaultBank' => TallyExportService::DEFAULT_BANK_LEDGER,
        ]);
    }

    public function vouchers(Request $request): Response
    {
        $filters = $this->filters($request);
        $vouchers = $this->service->vouchers($request->user()->organization_id, $filters['from'], $filters['to'], $filters['project_id'], $filters['bank_ledger']);

        return $this->xml($this->service->vouchersXml($vouchers), "tally-vouchers_{$filters['from']}_to_{$filters['to']}.xml");
    }

    public function ledgers(Request $request): Response
    {
        $filters = $this->filters($request);
        $organizationId = $request->user()->organization_id;
        $vouchers = $this->service->vouchers($organizationId, $filters['from'], $filters['to'], $filters['project_id'], $filters['bank_ledger']);

        return $this->xml($this->service->ledgersXml($this->service->ledgers($organizationId, $vouchers, $filters['bank_ledger'])), "tally-ledgers_{$filters['from']}_to_{$filters['to']}.xml");
    }

    /** @return array{from:string, to:string, project_id:?int, bank_ledger:?string} */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'project_id' => ['bail', 'nullable', 'integer', org_exists('projects')],
            'bank_ledger' => ['nullable', 'string', 'max:100'],
        ]);
        [$from, $to] = Gst::quarterRange(now());

        return [
            'from' => $data['from'] ?? $from,
            'to' => $data['to'] ?? $to,
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
            'bank_ledger' => $data['bank_ledger'] ?? null,
        ];
    }

    private function xml(string $body, string $filename): Response
    {
        return response($body, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
