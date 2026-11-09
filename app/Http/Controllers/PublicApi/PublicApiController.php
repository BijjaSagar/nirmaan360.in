<?php

namespace App\Http\Controllers\PublicApi;

use App\Http\Controllers\Controller;
use App\Models\BoqItem;
use App\Models\Dpr;
use App\Models\Project;
use App\Models\RaBill;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * Read-only data for a company's own tools, over an API key.
 *
 * No user is signed in on these requests, so the organisation scope on models is off. Every query
 * below starts from forOrganization() and every id from the URL is looked up through it, so a key can
 * never reach another company's rows. Money and quantities go out as decimal strings, never floats.
 */
class PublicApiController extends Controller
{
    public function projects(Request $request): JsonResponse
    {
        $query = $this->projectQuery($request)->with('client')->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->validate(['status' => [Rule::in(config('buildos.project_statuses'))]])['status']);
        }

        return $this->page($query->paginate($this->perPage($request)), fn (Project $project) => $this->projectData($project));
    }

    public function showProject(Request $request, int $projectId): JsonResponse
    {
        return response()->json(['data' => $this->projectData($this->findProject($request, $projectId))]);
    }

    public function boq(Request $request, int $projectId): JsonResponse
    {
        $project = $this->findProject($request, $projectId);

        $items = BoqItem::withoutGlobalScopes()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->withLedger()
            ->with('section')
            ->orderBy('sort')->orderBy('id')
            ->paginate($this->perPage($request, 200, 500));

        return $this->page($items, fn (BoqItem $item) => [
            'id' => $item->id,
            'section' => $item->section?->name,
            'code' => $item->code,
            'description' => $item->description,
            'unit' => $item->unit,
            'qty' => $item->qty,
            'rate' => $item->rate,
            'amount' => $item->amount(),
            'executed_qty' => Dec::qty($item->executed_qty ?? 0),
            'measured_qty' => Dec::qty($item->measured_qty ?? 0),
            'approved_qty' => Dec::qty($item->approved_qty ?? 0),
            'billed_qty' => Dec::qty($item->billed_qty ?? 0),
            'balance_qty' => $item->balanceQty(),
        ]);
    }

    public function dprs(Request $request, int $projectId): JsonResponse
    {
        $project = $this->findProject($request, $projectId);
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'REJECTED'])],
        ]);

        $dprs = $this->dprQuery($request)
            ->where('project_id', $project->id)
            ->when($filters['from'] ?? null, fn (Builder $query, $from) => $query->whereDate('report_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, $to) => $query->whereDate('report_date', '<=', $to))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->withCount('photos')
            ->withSum('manpower as manpower_total', 'count')
            ->orderByDesc('report_date')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->page($dprs, fn (Dpr $dpr) => $this->dprSummary($dpr));
    }

    public function showDpr(Request $request, int $dprId): JsonResponse
    {
        $dpr = $this->dprQuery($request)->with('manpower', 'machinery', 'workItems.boqItem')->withCount('photos')->findOrFail($dprId);

        return response()->json(['data' => [
            ...$this->dprSummary($dpr),
            'manpower' => $dpr->manpower->map(fn ($row) => ['trade' => $row->trade, 'count' => (int) $row->count])->all(),
            'machinery' => $dpr->machinery->map(fn ($row) => ['name' => $row->name, 'hours' => $row->hours])->all(),
            'work_items' => $dpr->workItems->map(fn ($row) => [
                'boq_item_id' => $row->boq_item_id,
                'code' => $row->boqItem?->code,
                'description' => $row->description ?? $row->boqItem?->description,
                'unit' => $row->boqItem?->unit,
                'qty' => $row->qty,
            ])->all(),
        ]]);
    }

    public function bills(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['client', 'subcontractor'])],
            'status' => ['nullable', Rule::in(['DRAFT', 'IN_REVIEW', 'RETURNED', 'APPROVED', 'PAID', 'REJECTED', 'CANCELLED'])],
        ]);

        $bills = $this->billQuery($request)->with('party', 'project')
            ->when($filters['project_id'] ?? null, fn (Builder $query, $id) => $query->where('project_id', $id))
            ->when($filters['type'] ?? null, fn (Builder $query, $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn (Builder $query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate($this->perPage($request));

        return $this->page($bills, fn (RaBill $bill) => $this->billSummary($bill));
    }

    public function showBill(Request $request, int $billId): JsonResponse
    {
        $bill = $this->billQuery($request)->with('party', 'project', 'items', 'deductions')->findOrFail($billId);

        return response()->json(['data' => [
            ...$this->billSummary($bill),
            'items' => $bill->items->map(fn ($line) => [
                'boq_item_id' => $line->boq_item_id,
                'code' => $line->code,
                'description' => $line->description,
                'unit' => $line->unit,
                'rate' => $line->rate,
                'prev_qty' => $line->prev_qty,
                'current_qty' => $line->current_qty,
                'cumulative_qty' => $line->cumulative_qty,
                'prev_amount' => $line->prev_amount,
                'current_amount' => $line->current_amount,
                'cumulative_amount' => $line->cumulative_amount,
            ])->all(),
            'deductions' => $bill->deductions->map(fn ($row) => ['type' => $row->type, 'label' => $row->label, 'pct' => $row->pct, 'amount' => $row->amount])->all(),
        ]]);
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('api_organization_id');
    }

    private function projectQuery(Request $request): Builder
    {
        return Project::withoutGlobalScopes()->where('organization_id', $this->organizationId($request))->whereNull('deleted_at');
    }

    private function findProject(Request $request, int $id): Project
    {
        return $this->projectQuery($request)->findOrFail($id);
    }

    private function dprQuery(Request $request): Builder
    {
        return Dpr::withoutGlobalScopes()->where('organization_id', $this->organizationId($request))->whereNull('deleted_at');
    }

    private function billQuery(Request $request): Builder
    {
        return RaBill::withoutGlobalScopes()->where('organization_id', $this->organizationId($request));
    }

    private function projectData(Project $project): array
    {
        return [
            'id' => $project->id,
            'code' => $project->code,
            'name' => $project->name,
            'client' => $project->client?->name,
            'location' => $project->location,
            'state' => $project->state,
            'status' => $project->status,
            'contract_value' => $project->contract_value,
            'start_date' => $project->start_date?->toDateString(),
            'end_date' => $project->end_date?->toDateString(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }

    private function dprSummary(Dpr $dpr): array
    {
        return [
            'id' => $dpr->id,
            'project_id' => $dpr->project_id,
            'report_date' => $dpr->report_date?->toDateString(),
            'status' => $dpr->status,
            'weather' => $dpr->weather,
            'summary' => $dpr->summary,
            'hindrances' => $dpr->hindrances,
            'manpower_total' => (int) ($dpr->manpower_total ?? $dpr->manpower?->sum('count') ?? 0),
            'photo_count' => (int) ($dpr->photos_count ?? 0),
            'updated_at' => $dpr->updated_at?->toIso8601String(),
        ];
    }

    private function billSummary(RaBill $bill): array
    {
        return [
            'id' => $bill->id,
            'number' => $bill->number,
            'project_id' => $bill->project_id,
            'project' => $bill->project?->name,
            'type' => $bill->type,
            'party' => $bill->party?->name,
            'status' => $bill->status,
            'period_from' => $bill->period_from?->toDateString(),
            'period_to' => $bill->period_to?->toDateString(),
            'gross_amount' => $bill->gross_amount,
            'deduction_total' => $bill->deduction_total,
            'gst_amount' => $bill->gst_amount,
            'net_payable' => $bill->net_payable,
            'approved_at' => $bill->approved_at?->toIso8601String(),
            'paid_at' => $bill->paid_at?->toIso8601String(),
        ];
    }

    private function perPage(Request $request, int $default = 50, int $max = 100): int
    {
        $requested = (int) $request->query('per_page', $default);

        return max(1, min($requested, $max));
    }

    private function page(LengthAwarePaginator $paginator, callable $present): JsonResponse
    {
        return response()->json([
            'data' => $paginator->getCollection()->map($present)->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
