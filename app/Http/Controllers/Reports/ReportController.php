<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Reports\Download;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\ReportResult;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The Reports screen and its Excel and PDF exports. */
class ReportController extends Controller
{
    /** Rows shown on the screen. The exports carry more. */
    private const PREVIEW_ROWS = 200;

    public function __construct(private ReportBuilder $builder, private ReportExporter $exporter) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $organization = $user->organization;
        $available = $this->builder->availableFor($user, $organization);
        $filters = null;
        $result = null;
        $total = 0;

        if ($request->filled('report')) {
            $filters = $this->filters($request);
            $full = $this->run($request, $filters);
            $total = $full->rowCount();
            $result = $full->limited(self::PREVIEW_ROWS);
        }

        return view('modules.reports.index', [
            'available' => $available,
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'filters' => $filters,
            'result' => $result,
            'total' => $total,
            'periods' => ReportPeriod::LABELS,
            'previewRows' => self::PREVIEW_ROWS,
            'query' => $filters ? $this->query($filters) : [],
        ]);
    }

    public function xlsx(Request $request): Response
    {
        $result = $this->run($request, $this->filters($request));

        return Download::file($this->exporter->xlsx($result, $request->user()->organization), $this->exporter->filename($result, 'xlsx'), Download::XLSX);
    }

    public function pdf(Request $request): Response
    {
        $result = $this->run($request, $this->filters($request));

        return Download::file($this->exporter->pdf($result, $request->user()->organization), $this->exporter->filename($result, 'pdf'), Download::PDF);
    }

    /**
     * The choices on the form, checked. The report must be one this person may run.
     *
     * @return array{report:string, project_id:?int, period:string, from:?string, to:?string, status:?string}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'report' => ['required', Rule::in(array_keys(ReportBuilder::DEFINITIONS))],
            'project_id' => ['nullable', 'integer', org_exists('projects')->whereNull('deleted_at')],
            'period' => ['nullable', Rule::in(array_keys(ReportPeriod::LABELS))],
            'from' => ['nullable', 'date', 'required_if:period,custom'],
            'to' => ['nullable', 'date', 'required_if:period,custom', 'after_or_equal:from'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        abort_unless($this->builder->allowed($request->user(), $request->user()->organization, $data['report']), 403, __('You cannot open this report.'));

        return [
            'report' => $data['report'],
            'project_id' => isset($data['project_id']) ? (int) $data['project_id'] : null,
            'period' => $data['period'] ?? 'this_month',
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'status' => $data['status'] ?? null,
        ];
    }

    private function run(Request $request, array $filters): ReportResult
    {
        [$from, $to] = ReportPeriod::resolve($filters['period'], $filters['from'], $filters['to']);

        return $this->builder->build($filters['report'], $request->user()->organization, $filters['project_id'], $from, $to, $filters['status']);
    }

    /** The filters as a query string for the export links. */
    private function query(array $filters): array
    {
        return array_filter($filters, fn ($value) => $value !== null && $value !== '');
    }
}
